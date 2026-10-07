<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Testes\Lib\AmbienteTeste;
use Rankly\Testes\Lib\Imagens;

/**
 * Teste rápido de ponta a ponta com o servidor embutido do PHP e o router-dev.php
 * (requisições HTTP de verdade, upload multipart real, cookies).
 */
final class ServidorTest extends TestCase
{
    private static ?string $dir = null;
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $dir = null;
        $app = AmbienteTeste::criar([], $dir);
        self::$dir = $dir;
        AmbienteTeste::usuario($app, 'admin', 'admin@rankly.teste');
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(AmbienteTeste::config($dir, AmbienteTeste::extraBanco()), true) . ';');

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) parse_url('tcp://' . stream_socket_get_name($sock, false), PHP_URL_PORT);
        fclose($sock);
        $raiz = dirname(__DIR__, 3);
        self::$proc = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-S', "127.0.0.1:{$porta}", '-t', $raiz . '/public_html', $raiz . '/public_html/router-dev.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/servidor.log', 'a'], 2 => ['file', $dir . '/servidor.log', 'a']],
            $pipes,
            $raiz,
            ['RANKLY_CONFIG' => $dir . '/config.php'] + getenv(),
        );
        self::$base = "http://127.0.0.1:{$porta}";
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $porta, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50000);
        }
        self::fail('O servidor php -S não subiu: ' . @file_get_contents($dir . '/servidor.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        AmbienteTeste::remover(self::$dir);
    }

    /** @return array{status: int, cabecalhos: array<string, string>, corpo: string, cookies: list<string>} */
    private function http(string $metodo, string $caminho, array $opcoes = []): array
    {
        $ch = curl_init(self::$base . $caminho);
        $cab = $opcoes['cabecalhos'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOPROXY => '*',
        ]);
        if (isset($opcoes['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opcoes['json']));
            $cab[] = 'Content-Type: application/json';
        } elseif (isset($opcoes['campos'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opcoes['campos']);
        }
        if (isset($opcoes['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $opcoes['cookie']);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $cab);
        $resposta = (string) curl_exec($ch);
        $tamanhoCab = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $cabecalhos = [];
        $cookies = [];
        foreach (explode("\r\n", substr($resposta, 0, $tamanhoCab)) as $linha) {
            if (str_contains($linha, ':')) {
                [$n, $v] = explode(':', $linha, 2);
                if (strtolower($n) === 'set-cookie') {
                    $cookies[] = trim($v);
                }
                $cabecalhos[strtolower($n)] = trim($v);
            }
        }
        return ['status' => $status, 'cabecalhos' => $cabecalhos, 'corpo' => substr($resposta, $tamanhoCab), 'cookies' => $cookies];
    }

    public function testFluxoBasicoPeloServidor(): void
    {
        $r = $this->http('GET', '/api/auth/eu');
        $this->assertSame(401, $r['status'], $r['corpo']);
        $this->assertSame('application/json; charset=utf-8', $r['cabecalhos']['content-type']);
        $this->assertSame('nosniff', $r['cabecalhos']['x-content-type-options']);
        $this->assertSame('DENY', $r['cabecalhos']['x-frame-options']);

        $r = $this->http('POST', '/api/auth/login', ['json' => ['email' => 'admin@rankly.teste', 'senha' => 'senha-forte-123']]);
        $this->assertSame(200, $r['status'], $r['corpo']);
        $csrf = json_decode($r['corpo'], true)['csrf'];
        $this->assertCount(1, $r['cookies']);
        $this->assertStringContainsString('HttpOnly', $r['cookies'][0]);
        $cookie = explode(';', $r['cookies'][0])[0];

        $this->assertSame(200, $this->http('GET', '/api/sites', ['cookie' => $cookie])['status']);
        $novo = ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso Vivo']];
        $this->assertSame(403, $this->http('POST', '/api/sites', ['cookie' => $cookie, 'json' => $novo])['status']);
        $r = $this->http('POST', '/api/sites', ['cookie' => $cookie, 'json' => $novo, 'cabecalhos' => ['X-CSRF-Token: ' . $csrf]]);
        $this->assertSame(201, $r['status'], $r['corpo']);
        $site = json_decode($r['corpo'], true)['site'];

        // Upload multipart de verdade (is_uploaded_file).
        $foto = Imagens::jpeg(self::$dir . '/foto.jpg', 1000, 700);
        $r = $this->http('POST', '/api/media', [
            'cookie' => $cookie, 'cabecalhos' => ['X-CSRF-Token: ' . $csrf],
            'campos' => ['site_id' => (string) $site['id'], 'tipo' => 'foto', 'arquivo' => new \CURLFile($foto, 'image/jpeg', 'foto.jpg')],
        ]);
        $this->assertSame(201, $r['status'], $r['corpo']);
        $midia = json_decode($r['corpo'], true)['midia'];
        $img = $this->http('GET', '/api/media/' . $midia['id'] . '/480', ['cookie' => $cookie]);
        $this->assertSame(200, $img['status']);
        $this->assertSame('image/webp', $img['cabecalhos']['content-type']);
        $this->assertSame('RIFF', substr($img['corpo'], 0, 4));

        // Lead por formulário sem JavaScript.
        $r = $this->http('POST', '/api/lead/sorrisovivo', ['campos' => ['nome' => 'Ana', 'telefone' => '11988887777', '_t' => '', 'empresa_site' => '']]);
        $this->assertSame(303, $r['status'], $r['corpo']);
        $this->assertSame('https://sorrisovivo.sites.teste/obrigado/', $r['cabecalhos']['location']);
        // O e-mail sai logo após a resposta (modo arquivo em dev).
        $this->assertCount(1, glob(self::$dir . '/var/emails/*.eml') ?: []);

        $r = $this->http('POST', '/api/auth/logout', ['cookie' => $cookie, 'cabecalhos' => ['X-CSRF-Token: ' . $csrf]]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(401, $this->http('GET', '/api/auth/eu', ['cookie' => $cookie])['status']);
    }

    public function testBibliotecaEArquivosEstaticos(): void
    {
        $r = $this->http('GET', '/api/biblioteca');
        $this->assertSame(200, $r['status']);
        $etag = $r['cabecalhos']['etag'];
        $this->assertSame(304, $this->http('GET', '/api/biblioteca', ['cabecalhos' => ['If-None-Match: ' . $etag]])['status']);

        $r = $this->http('GET', '/');
        $this->assertSame(302, $r['status']);
        $this->assertSame('/editor/', $r['cabecalhos']['location']);
        $r = $this->http('GET', '/editor/js/compartilhado/texto.mjs');
        $this->assertSame(200, $r['status']);
        $this->assertSame('text/javascript; charset=utf-8', $r['cabecalhos']['content-type']);
        $this->assertStringContainsString("script-src 'self'", $r['cabecalhos']['content-security-policy']);
        foreach (['/.htaccess', '/api/.env', '/editor/.git/config', '/router-dev.php', '/api/index.php/../../config/config.php'] as $caminho) {
            $this->assertContains($this->http('GET', $caminho)['status'], [403, 404], $caminho);
        }
        $this->assertSame(404, $this->http('GET', '/editor/nao-existe.js')['status']);
    }
}
