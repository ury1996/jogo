<?php

declare(strict_types=1);

namespace Rankly\Lib\Email;

use Rankly\Aplicacao;

/**
 * Monta e envia e-mails de texto (UTF-8). email_modo = "smtp" envia pelo Smtp;
 * "arquivo" (desenvolvimento) grava cada mensagem em var/emails/*.eml.
 */
final class Mailer
{
    public function __construct(private readonly Aplicacao $app)
    {
    }

    /**
     * Envia uma mensagem e devolve o Message-ID.
     *
     * @param array{para: string|list<string>, assunto: string, texto: string, responderPara?: ?string, copiaOculta?: list<string>} $msg
     */
    public function enviar(array $msg): string
    {
        $para = $this->listaEnderecos($msg['para'] ?? []);
        $oculta = $this->listaEnderecos($msg['copiaOculta'] ?? []);
        if ($para === []) {
            throw new \InvalidArgumentException('E-mail sem destinatário válido.');
        }
        $remetente = (string) $this->app->config('smtp.remetente', 'nao-responda@localhost');
        $dominio = substr((string) strrchr($remetente, '@'), 1) ?: 'localhost';
        $messageId = '<' . bin2hex(random_bytes(12)) . '.' . $this->app->agora()->getTimestamp() . '@' . $dominio . '>';
        $mensagem = $this->montar($msg + ['para' => $para], $messageId);

        if ($this->app->config('email_modo', 'arquivo') === 'smtp') {
            $smtp = new Smtp(
                (string) $this->app->config('smtp.host'),
                (int) $this->app->config('smtp.porta', 587),
                (string) $this->app->config('smtp.seguranca', 'tls'),
                (string) $this->app->config('smtp.usuario', ''),
                (string) $this->app->config('smtp.senha', ''),
                (int) $this->app->config('smtp.tempo_limite', 15),
                $dominio,
            );
            $smtp->enviar($remetente, array_values(array_unique(array_merge($para, $oculta))), $mensagem);
        } else {
            $dir = $this->app->dirVar('emails');
            $nome = $this->app->agora()->format('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            $envelope = 'X-Envelope-To: ' . implode(', ', array_merge($para, $oculta)) . "\r\n";
            if (@file_put_contents($dir . '/' . $nome, $envelope . $mensagem) === false) {
                throw new \RuntimeException('Não foi possível gravar o e-mail em ' . $dir . '.');
            }
        }
        return $messageId;
    }

    /** Mensagem completa (cabeçalhos + corpo quoted-printable), com CRLF. */
    public function montar(array $msg, string $messageId): string
    {
        $remetente = (string) $this->app->config('smtp.remetente', 'nao-responda@localhost');
        $nomeRemetente = (string) $this->app->config('smtp.nome_remetente', 'Sites Rankly');
        $para = $this->listaEnderecos($msg['para'] ?? []);
        $cab = [
            'Date' => $this->app->agora()->format(DATE_RFC2822),
            'Message-ID' => $messageId,
            'From' => self::nomeExibicao($nomeRemetente) . ' <' . $remetente . '>',
            'To' => implode(', ', $para),
        ];
        $responder = isset($msg['responderPara']) && is_string($msg['responderPara']) ? trim($msg['responderPara']) : '';
        if ($responder !== '' && self::enderecoValido($responder)) {
            $cab['Reply-To'] = $responder;
        }
        $cab['Subject'] = self::codificarCabecalho((string) ($msg['assunto'] ?? ''));
        $cab['MIME-Version'] = '1.0';
        $cab['Content-Type'] = 'text/plain; charset=UTF-8';
        $cab['Content-Transfer-Encoding'] = 'quoted-printable';
        $cab['Auto-Submitted'] = 'auto-generated';

        $linhas = [];
        foreach ($cab as $nome => $valor) {
            $linhas[] = $nome . ': ' . self::semQuebras($valor);
        }
        $texto = preg_replace("/\r\n|\r|\n/", "\r\n", (string) ($msg['texto'] ?? '')) ?? '';
        return implode("\r\n", $linhas) . "\r\n\r\n" . quoted_printable_encode($texto) . "\r\n";
    }

    /** Palavra codificada RFC 2047 (só quando há caracteres fora do ASCII imprimível). */
    public static function codificarCabecalho(string $texto): string
    {
        $texto = self::semQuebras($texto);
        if (preg_match('/^[\x20-\x7e]*$/D', $texto)) {
            return $texto;
        }
        return mb_encode_mimeheader($texto, 'UTF-8', 'B', "\r\n ");
    }

    /** Nome de exibição: codificado se tiver acentos; entre aspas se tiver caracteres especiais. */
    public static function nomeExibicao(string $nome): string
    {
        $nome = self::semQuebras($nome);
        if (preg_match('/^[\x20-\x7e]*$/D', $nome) && preg_match('/[()<>\[\]:;@\\\\,."]/', $nome)) {
            return '"' . addcslashes($nome, '"\\') . '"';
        }
        return self::codificarCabecalho($nome);
    }

    public static function enderecoValido(string $email): bool
    {
        return !preg_match('/[\r\n<>,;]/', $email) && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function semQuebras(string $s): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $s) ?? '');
    }

    /** @return list<string> endereços válidos, sem repetição */
    private function listaEnderecos(mixed $v): array
    {
        $lista = is_array($v) ? $v : [$v];
        $r = [];
        foreach ($lista as $e) {
            $e = is_string($e) ? trim($e) : '';
            if ($e !== '' && self::enderecoValido($e) && !in_array(strtolower($e), array_map('strtolower', $r), true)) {
                $r[] = $e;
            }
        }
        return $r;
    }
}
