<?php

declare(strict_types=1);

namespace Rankly\Lib\Email;

/**
 * Cliente SMTP mínimo [M20]: SSL direto (465) ou STARTTLS (587), AUTH LOGIN, tempo limite
 * por operação. Recebe a mensagem já montada (Mailer::montar).
 */
final class Smtp
{
    /** @var resource|null */
    private $conexao = null;

    public function __construct(
        private readonly string $host,
        private readonly int $porta = 587,
        private readonly string $seguranca = 'tls',
        private readonly string $usuario = '',
        private readonly string $senha = '',
        private readonly int $tempoLimite = 15,
        private readonly string $ehlo = 'localhost',
    ) {
        if (!in_array($seguranca, ['tls', 'ssl', 'nenhuma'], true)) {
            throw new \InvalidArgumentException('smtp.seguranca deve ser tls, ssl ou nenhuma.');
        }
    }

    /**
     * Envia uma mensagem.
     *
     * @param list<string> $destinatarios
     * @throws \RuntimeException falha de conexão, TLS, autenticação ou recusa do servidor
     */
    public function enviar(string $remetente, array $destinatarios, string $mensagem): void
    {
        if ($destinatarios === []) {
            throw new \InvalidArgumentException('Nenhum destinatário.');
        }
        try {
            $this->conectar();
            $this->esperar([220]);
            $this->ehlo();
            if ($this->seguranca === 'tls') {
                $this->comando('STARTTLS', [220]);
                $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (@stream_socket_enable_crypto($this->conexao, true, $metodo) !== true) {
                    throw new \RuntimeException('Falha ao iniciar TLS com o servidor SMTP.');
                }
                $this->ehlo();
            }
            if ($this->usuario !== '') {
                $this->comando('AUTH LOGIN', [334]);
                $this->comando(base64_encode($this->usuario), [334], 'AUTH (usuário)');
                $this->comando(base64_encode($this->senha), [235], 'AUTH (senha)');
            }
            $this->comando('MAIL FROM:<' . self::endereco($remetente) . '>', [250]);
            foreach ($destinatarios as $para) {
                $this->comando('RCPT TO:<' . self::endereco($para) . '>', [250, 251]);
            }
            $this->comando('DATA', [354]);
            $this->escrever(self::prepararDados($mensagem) . "\r\n.\r\n");
            $this->esperar([250]);
            try {
                $this->comando('QUIT', [221]);
            } catch (\RuntimeException) {
                // alguns servidores fecham sem responder ao QUIT
            }
        } finally {
            $this->fechar();
        }
    }

    /** Normaliza quebras para CRLF e duplica pontos no início de linha (RFC 5321 §4.5.2). */
    public static function prepararDados(string $mensagem): string
    {
        $m = preg_replace("/\r\n|\r|\n/", "\r\n", $mensagem) ?? $mensagem;
        $m = preg_replace('/^\./m', '..', $m) ?? $m;
        return rtrim($m, "\r\n");
    }

    private static function endereco(string $email): string
    {
        if (preg_match('/[\r\n<>]/', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Endereço de e-mail inválido para SMTP.');
        }
        return $email;
    }

    private function conectar(): void
    {
        $esquema = $this->seguranca === 'ssl' ? 'ssl' : 'tcp';
        $contexto = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $erroNum = 0;
        $erroTexto = '';
        $c = @stream_socket_client(
            "{$esquema}://{$this->host}:{$this->porta}",
            $erroNum,
            $erroTexto,
            $this->tempoLimite,
            STREAM_CLIENT_CONNECT,
            $contexto,
        );
        if ($c === false) {
            throw new \RuntimeException("Não foi possível conectar ao SMTP {$this->host}:{$this->porta} ({$erroTexto}).");
        }
        stream_set_timeout($c, $this->tempoLimite);
        $this->conexao = $c;
    }

    private function ehlo(): void
    {
        $nome = preg_replace('/[^A-Za-z0-9.\-]/', '', $this->ehlo) ?: 'localhost';
        $this->comando('EHLO ' . $nome, [250]);
    }

    /** @param list<int> $esperados */
    private function comando(string $linha, array $esperados, ?string $rotulo = null): string
    {
        $this->escrever($linha . "\r\n");
        return $this->esperar($esperados, $rotulo ?? strtok($linha, ' ') ?: $linha);
    }

    private function escrever(string $dados): void
    {
        $total = strlen($dados);
        $enviado = 0;
        while ($enviado < $total) {
            $n = @fwrite($this->conexao, substr($dados, $enviado));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Conexão SMTP interrompida ao enviar dados.');
            }
            $enviado += $n;
        }
    }

    /** Lê uma resposta (possivelmente de várias linhas) e confere o código. */
    private function esperar(array $esperados, string $rotulo = 'conexão'): string
    {
        $resposta = '';
        while (true) {
            $linha = fgets($this->conexao, 4096);
            if ($linha === false) {
                $meta = stream_get_meta_data($this->conexao);
                throw new \RuntimeException(($meta['timed_out'] ?? false)
                    ? "Tempo esgotado esperando o servidor SMTP ({$rotulo})."
                    : "Conexão SMTP encerrada ({$rotulo}).");
            }
            $resposta .= $linha;
            if (strlen($linha) < 4 || $linha[3] !== '-') {
                break;
            }
        }
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new \RuntimeException("Servidor SMTP recusou {$rotulo}: " . trim(mb_substr($resposta, 0, 300)));
        }
        return $resposta;
    }

    private function fechar(): void
    {
        if (is_resource($this->conexao)) {
            @fclose($this->conexao);
        }
        $this->conexao = null;
    }
}
