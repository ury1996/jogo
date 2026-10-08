<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

/**
 * Provedor sem internet: preenche o esquema com textos de teste curtos e coerentes.
 * Serve para desenvolvimento sem chave e para os testes (aceita uma resposta pronta).
 */
final class Simulado implements Provedor
{
    /** @var callable|null fn(string $instrucoes, string $pedido, array $esquema): array */
    private $resposta;

    public function __construct(?callable $resposta = null)
    {
        $this->resposta = $resposta;
    }

    public function nome(): string
    {
        return 'simulado';
    }

    public function gerarJson(string $instrucoes, string $pedido, array $esquema): array
    {
        if ($this->resposta !== null) {
            return ($this->resposta)($instrucoes, $pedido, $esquema);
        }
        $valor = $this->preencher($esquema, 'conteudo');
        return is_array($valor) ? $valor : [];
    }

    private function preencher(array $esquema, string $nome, int $indice = 1): mixed
    {
        $tipo = strtoupper((string) ($esquema['type'] ?? 'STRING'));
        if ($tipo === 'OBJECT') {
            $obj = [];
            foreach ((array) ($esquema['properties'] ?? []) as $chave => $sub) {
                $obj[$chave] = $this->preencher((array) $sub, (string) $chave, $indice);
            }
            return $obj;
        }
        if ($tipo === 'ARRAY') {
            $qtd = max(1, (int) ($esquema['minItems'] ?? 3));
            $itens = [];
            for ($i = 1; $i <= $qtd; $i++) {
                $itens[] = $this->preencher((array) ($esquema['items'] ?? []), $nome, $i);
            }
            return $itens;
        }
        $sobre = trim(explode(';', (string) ($esquema['description'] ?? $nome))[0]);
        $max = preg_match('/até (\d+) caracteres/', (string) ($esquema['description'] ?? ''), $m) ? (int) $m[1] : 120;
        $texto = '[IA simulada] ' . $sobre . ($indice > 1 ? ' ' . $indice : '');
        return mb_substr($texto, 0, $max, 'UTF-8');
    }
}
