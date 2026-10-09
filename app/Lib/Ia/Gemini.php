<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

/**
 * Google Gemini pela API REST (generateContent), com resposta em JSON.
 *
 * - A chave vai no cabeçalho x-goog-api-key (nunca na URL, para não cair em logs).
 * - Cota estourada (429), instabilidade (5xx) ou modelo indisponível (404) → tenta os modelos reserva
 *   (um ou vários, separados por vírgula), na ordem.
 * - Se a API recusar o esquema (400), repete sem responseSchema e valida a resposta do nosso lado.
 */
final class Gemini implements Provedor
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** @var callable|null fn(string $url, array $cabecalhos, string $corpo, int $tempo): array{0:int,1:string} (testes) */
    private $transporte;

    public function __construct(
        private readonly string $chave,
        private readonly string $modelo,
        private readonly string $modeloReserva = '',
        private readonly int $tempoLimite = 90,
        ?callable $transporte = null,
    ) {
        $this->transporte = $transporte;
    }

    public function nome(): string
    {
        return 'gemini';
    }

    public function gerarJson(string $instrucoes, string $pedido, array $esquema): array
    {
        $modelos = array_values(array_unique(array_filter(array_map('trim', explode(',', $this->modelo . ',' . $this->modeloReserva)))));
        $ultimo = null;
        foreach ($modelos as $modelo) {
            try {
                return $this->pedir($modelo, $instrucoes, $pedido, $esquema);
            } catch (ErroIa $e) {
                $ultimo = $e;
                if (!$e->temporario) {
                    throw $e;
                }
            }
        }
        throw $ultimo ?? new ErroIa('A IA não está configurada.');
    }

    private function pedir(string $modelo, string $instrucoes, string $pedido, array $esquema): array
    {
        $corpo = [
            'systemInstruction' => ['parts' => [['text' => $instrucoes]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $pedido]]]],
            'generationConfig' => [
                'temperature' => 0.7,
                'responseMimeType' => 'application/json',
                'responseSchema' => $esquema,
            ],
        ];
        [$status, $resposta] = $this->enviar($modelo, $corpo);
        if ($status === 400 && stripos($resposta, 'schema') !== false) {
            unset($corpo['generationConfig']['responseSchema']);
            [$status, $resposta] = $this->enviar($modelo, $corpo);
        }
        if ($status !== 200) {
            throw $this->erroHttp($status, $resposta);
        }
        $json = json_decode($resposta, true);
        $candidato = is_array($json) ? ($json['candidates'][0] ?? null) : null;
        if (!is_array($candidato)) {
            $bloqueio = is_array($json) ? ($json['promptFeedback']['blockReason'] ?? null) : null;
            throw new ErroIa($bloqueio !== null
                ? 'A IA recusou o pedido. Reescreva a descrição do negócio e tente de novo.'
                : 'A IA devolveu uma resposta vazia. Tente de novo.', $bloqueio === null);
        }
        $texto = '';
        foreach ((array) ($candidato['content']['parts'] ?? []) as $parte) {
            if (is_array($parte) && is_string($parte['text'] ?? null) && empty($parte['thought'])) {
                $texto .= $parte['text'];
            }
        }
        $dados = self::decodificar($texto);
        if ($dados === null) {
            $fim = (string) ($candidato['finishReason'] ?? '');
            throw new ErroIa($fim === 'MAX_TOKENS'
                ? 'A resposta da IA ficou longa demais e foi cortada. Tente uma descrição mais curta.'
                : 'A IA devolveu um texto fora do formato esperado. Tente de novo.', true);
        }
        return $dados;
    }

    /** JSON da resposta, tolerando cercas ```json. */
    public static function decodificar(string $texto): ?array
    {
        $texto = trim($texto);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $texto, $m)) {
            $texto = $m[1];
        }
        $dados = json_decode($texto, true);
        return is_array($dados) && !array_is_list($dados) ? $dados : null;
    }

    private function erroHttp(int $status, string $resposta): ErroIa
    {
        $msg = '';
        $json = json_decode($resposta, true);
        if (is_array($json) && is_string($json['error']['message'] ?? null)) {
            $msg = $json['error']['message'];
        }
        return match (true) {
            $status === 0 => new ErroIa('Não foi possível falar com a IA (sem conexão ou tempo esgotado). Tente de novo.', true),
            $status === 429 => new ErroIa('O limite gratuito da IA foi atingido por agora. Tente de novo em alguns minutos.', true),
            $status === 404 => new ErroIa('O modelo de IA configurado não existe mais. Ajuste ia.modelo no config.php.', true),
            $status === 400 && stripos($msg, 'api key') !== false,
            $status === 401, $status === 403 => new ErroIa('A chave da IA (Gemini) é inválida ou não tem permissão. Confira ia.chave no config.php.'),
            $status === 400 && stripos($msg, 'location') !== false => new ErroIa('A IA não está disponível na região deste servidor.'),
            $status >= 500 => new ErroIa('A IA está instável no momento. Tente de novo em instantes.', true),
            default => new ErroIa('A IA recusou o pedido (erro ' . $status . '). Tente de novo.'),
        };
    }

    /** @return array{0: int, 1: string} [status HTTP (0 = falha de rede), corpo] */
    private function enviar(string $modelo, array $corpo): array
    {
        $url = sprintf(self::URL, rawurlencode($modelo));
        $cabecalhos = ['Content-Type: application/json', 'x-goog-api-key: ' . $this->chave];
        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($this->transporte !== null) {
            return ($this->transporte)($url, $cabecalhos, $json, $this->tempoLimite);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->tempoLimite,
        ]);
        $resposta = curl_exec($ch);
        $status = $resposta === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $resposta === false ? '' : (string) $resposta];
    }
}
