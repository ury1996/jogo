<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Email\Mailer;
use Rankly\Lib\Email\Smtp;

final class EmailTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['smtp' => ['nome_remetente' => 'Sites Rankly · Avisos']], $this->dir);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testMensagemMontadaComCabecalhosSegurosEUtf8(): void
    {
        $m = new Mailer($this->app);
        $eml = $m->montar([
            'para' => ['a@exemplo.com', 'invalido', "b@exemplo.com\r\nBcc: x@mal.example"],
            'assunto' => "Olá\r\nBcc: injetado@mal.example",
            'texto' => "Linha 1\n.linha com ponto\nAção",
            'responderPara' => "visitante@exemplo.com\r\nX: y",
        ], '<id@rankly.teste>');
        $this->assertStringContainsString("To: a@exemplo.com\r\n", $eml, 'Endereços inválidos ou com quebra são descartados');
        $this->assertStringNotContainsString('Reply-To', $eml);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $eml, 'Sem injeção de cabeçalho');
        $this->assertMatchesRegularExpression('/^From: [^\r\n]*=\?UTF-8\?B\?[^\r\n]+\?= <nao-responda@rankly\.teste>\r$/m', $eml);
        $this->assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $eml);
        $this->assertStringContainsString("Message-ID: <id@rankly.teste>\r\n", $eml);
        $this->assertSame("Linha 1\r\n.linha com ponto\r\nAção\r\n", AmbienteTeste::corpoEmail($eml));
    }

    public function testModoArquivoGravaEml(): void
    {
        $id = (new Mailer($this->app))->enviar(['para' => 'dono@exemplo.com', 'assunto' => 'Teste', 'texto' => 'Oi', 'copiaOculta' => ['agencia@exemplo.com']]);
        $this->assertMatchesRegularExpression('/^<[0-9a-f]{24}\.\d+@rankly\.teste>$/', $id);
        $emails = AmbienteTeste::emails($this->app);
        $this->assertCount(1, $emails);
        $this->assertStringContainsString("X-Envelope-To: dono@exemplo.com, agencia@exemplo.com\r\n", $emails[0]);
        $this->assertStringNotContainsString("\r\nBcc:", $emails[0]);
        $this->expectException(\InvalidArgumentException::class);
        (new Mailer($this->app))->enviar(['para' => 'invalido', 'assunto' => 'x', 'texto' => 'y']);
    }

    public function testPreparacaoDosDadosSmtp(): void
    {
        $this->assertSame("a\r\n..b\r\nc", Smtp::prepararDados("a\n.b\rc\n"));
    }

    public function testEnvioSmtpComAuthLoginContraServidorFalso(): void
    {
        [$proc, $porta, $registro] = $this->servidorFalso('usuario@rankly.teste', 's3nha');
        try {
            $smtp = new Smtp('127.0.0.1', $porta, 'nenhuma', 'usuario@rankly.teste', 's3nha', 5, 'rankly.teste');
            $mensagem = (new Mailer($this->app))->montar(['para' => 'dono@exemplo.com', 'assunto' => 'Oi', 'texto' => ".começa com ponto\nfim"], '<x@rankly.teste>');
            $smtp->enviar('nao-responda@rankly.teste', ['dono@exemplo.com', 'copia@exemplo.com'], $mensagem);
        } finally {
            $saida = $this->encerrar($proc);
        }
        $log = (string) file_get_contents($registro);
        $this->assertStringContainsString("EHLO rankly.teste\r\n", $log, $saida);
        $this->assertStringContainsString("AUTH LOGIN\r\n", $log);
        $this->assertStringContainsString(base64_encode('usuario@rankly.teste') . "\r\n", $log);
        $this->assertStringContainsString("MAIL FROM:<nao-responda@rankly.teste>\r\n", $log);
        $this->assertStringContainsString("RCPT TO:<dono@exemplo.com>\r\nRCPT TO:<copia@exemplo.com>\r\n", $log);
        $this->assertStringContainsString("\r\n..come", $log, 'Ponto no início da linha duplicado');
        $this->assertStringContainsString("QUIT\r\n", $log);
    }

    public function testSmtpRecusandoSenhaLancaErroSemVazarSenha(): void
    {
        [$proc, $porta] = $this->servidorFalso('usuario@rankly.teste', 'certa');
        try {
            $smtp = new Smtp('127.0.0.1', $porta, 'nenhuma', 'usuario@rankly.teste', 'errada-super-secreta', 5);
            $smtp->enviar('a@rankly.teste', ['b@exemplo.com'], "Subject: x\r\n\r\ny");
            $this->fail('Deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringNotContainsString('errada-super-secreta', $e->getMessage());
        } finally {
            $this->encerrar($proc);
        }
    }

    public function testSmtpSemServidorFalhaComMensagemClara(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível conectar ao SMTP');
        (new Smtp('127.0.0.1', 1, 'nenhuma', '', '', 2))->enviar('a@rankly.teste', ['b@exemplo.com'], 'x');
    }

    /** @return array{0: resource, 1: int, 2: string} processo, porta e arquivo com o diálogo recebido */
    private function servidorFalso(string $usuario, string $senha): array
    {
        $script = $this->dir . '/smtp-falso.php';
        $registro = $this->dir . '/smtp-registro.txt';
        file_put_contents($script, <<<'PHP'
        <?php
        [$_, $usuario, $senha, $registro] = $argv;
        $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        echo parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT), "\n";
        fflush(STDOUT);
        $c = stream_socket_accept($srv, 10);
        $log = fopen($registro, 'w');
        $responder = fn (string $s) => fwrite($c, $s . "\r\n");
        $responder('220 falso ESMTP');
        $estado = '';
        while (($linha = fgets($c)) !== false) {
            fwrite($log, $linha);
            $cmd = strtoupper(trim($linha));
            if ($estado === 'usuario') { $estado = base64_decode(trim($linha)) === $usuario ? 'senha' : 'erro'; $responder('334 UGFzc3dvcmQ6'); continue; }
            if ($estado === 'senha') { $estado = ''; $responder(base64_decode(trim($linha)) === $senha ? '235 ok' : '535 autenticacao falhou'); continue; }
            if ($estado === 'erro') { $estado = ''; $responder('535 autenticacao falhou'); continue; }
            if ($estado === 'dados') { if (rtrim($linha, "\r\n") === '.') { $estado = ''; $responder('250 enfileirada'); } continue; }
            if (str_starts_with($cmd, 'EHLO')) { $responder('250-falso'); $responder('250 AUTH LOGIN'); }
            elseif ($cmd === 'AUTH LOGIN') { $estado = 'usuario'; $responder('334 VXNlcm5hbWU6'); }
            elseif (str_starts_with($cmd, 'MAIL FROM') || str_starts_with($cmd, 'RCPT TO')) { $responder('250 ok'); }
            elseif ($cmd === 'DATA') { $estado = 'dados'; $responder('354 pode enviar'); }
            elseif ($cmd === 'QUIT') { $responder('221 tchau'); break; }
            else { $responder('502 ?'); }
        }
        fclose($log);
        PHP);
        $proc = proc_open([PHP_BINARY, $script, $usuario, $senha, $registro], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $porta = (int) trim((string) fgets($pipes[1]));
        $this->assertGreaterThan(0, $porta, 'Servidor SMTP falso não subiu');
        return [$proc, $porta, $registro, $pipes];
    }

    private function encerrar($proc): string
    {
        $status = proc_get_status($proc);
        for ($i = 0; $i < 50 && $status['running']; $i++) {
            usleep(20000);
            $status = proc_get_status($proc);
        }
        if ($status['running']) {
            proc_terminate($proc);
        }
        proc_close($proc);
        return '';
    }
}
