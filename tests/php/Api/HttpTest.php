<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Http\ErroHttp;
use Rankly\Http\Requisicao;
use Rankly\Http\Resposta;
use Rankly\Http\Router;

final class HttpTest extends TestCase
{
    public function testRouterComParametrosERestricoes(): void
    {
        $r = new Router();
        $r->get('/api/sites/{id:\d+}', fn (Requisicao $q, array $p) => Resposta::json(['id' => $p['id']]));
        $r->get('/api/sites/{id:\d+}/leads.csv', fn (Requisicao $q, array $p) => Resposta::texto('csv ' . $p['id']));
        $r->put('/api/sites/{id:\d+}', fn () => Resposta::json('put'));
        $r->get('/api/media/{id}/{w:orig|\d+}', fn (Requisicao $q, array $p) => Resposta::json($p));

        $this->assertSame(['id' => '42'], $r->despachar(new Requisicao('GET', '/api/sites/42/'))->dados());
        $this->assertSame('csv 7', $r->despachar(new Requisicao('GET', '/api/sites/7/leads.csv'))->corpo);
        $this->assertSame(['id' => 'm_1', 'w' => 'orig'], $r->despachar(new Requisicao('GET', '/api/media/m_1/orig'))->dados());
        $this->assertSame(['id' => '42'], $r->despachar(new Requisicao('HEAD', '/api/sites/42'))->dados(), 'HEAD usa a rota GET');
        foreach (['/api/sites/abc', '/api/sites/1x', '/api/media/m_1/grande', '/api/sites/42/leads.csvx'] as $caminho) {
            try {
                $r->despachar(new Requisicao('GET', $caminho));
                $this->fail("Deveria dar 404: {$caminho}");
            } catch (ErroHttp $e) {
                $this->assertSame(404, $e->status, $caminho);
            }
        }
        try {
            $r->despachar(new Requisicao('DELETE', '/api/sites/42'));
            $this->fail('Deveria dar 405');
        } catch (ErroHttp $e) {
            $this->assertSame(405, $e->status);
            $this->assertSame('GET, PUT', $e->cabecalhos['Allow']);
        }
    }

    public function testRequisicaoJsonEFormulario(): void
    {
        $json = new Requisicao('POST', '//api//x', [], ['content-type' => 'application/json; charset=utf-8'], '{"a":1}');
        $this->assertSame('/api/x', $json->caminho);
        $this->assertSame(['a' => 1], $json->dados());
        $this->assertTrue($json->querJson());
        $lista = new Requisicao('POST', '/x', [], ['Content-Type' => 'application/json'], '[1,2]');
        try {
            $lista->dados();
            $this->fail('Lista JSON não é objeto');
        } catch (ErroHttp $e) {
            $this->assertSame(400, $e->status);
        }
        $form = new Requisicao('POST', '/x', [], ['Content-Type' => 'application/x-www-form-urlencoded'], 'nome=Ana+Lima&x=%C3%A7');
        $this->assertSame(['nome' => 'Ana Lima', 'x' => 'ç'], $form->dados());
        $this->assertFalse($form->querJson());
        $this->assertTrue((new Requisicao('POST', '/x', [], ['Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*']))->querJson(), 'fetch() sem Accept JSON');
        $this->assertFalse((new Requisicao('POST', '/x', [], ['Sec-Fetch-Dest' => 'document', 'Accept' => 'text/html']))->querJson());
        $grande = new Requisicao('POST', '/x', [], ['Content-Type' => 'application/json'], str_repeat(' ', Requisicao::MAX_JSON + 1));
        try {
            $grande->json();
            $this->fail('Corpo grande demais');
        } catch (ErroHttp $e) {
            $this->assertSame(413, $e->status);
        }
        $this->assertSame('0.0.0.0', (new Requisicao('GET', '/', servidor: ['REMOTE_ADDR' => 'lixo']))->ip());
        $this->assertTrue((new Requisicao('GET', '/', [], ['X-Forwarded-Proto' => 'https']))->seguro());
    }

    /**
     * Regressão: os roteadores de desenvolvimento não funcionam fora do php -S (no Apache, um
     * GET /router-dev.php não pode virar um segundo roteador com regras próprias).
     */
    public function testRoteadoresDeDesenvolvimentoSoNoServidorEmbutido(): void
    {
        $raiz = dirname(__DIR__, 3);
        foreach (['public_html/router-dev.php', 'sites/router-dev.php'] as $arquivo) {
            $proc = proc_open([PHP_BINARY, $raiz . '/' . $arquivo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz,
                ['REQUEST_URI' => '/editor/index.html', 'REQUEST_METHOD' => 'GET'] + getenv());
            $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame('', $saida, $arquivo);
        }
    }

    public function testRespostaCabecalhosECookies(): void
    {
        $r = Resposta::json(['ok' => true, 'texto' => 'ação/<b>'], 201);
        $this->assertSame('{"ok":true,"texto":"ação/<b>"}', $r->corpo);
        $r->cabecalho('x-teste', '1')->cabecalho('X-Teste', '2');
        $this->assertSame(['Content-Type' => 'application/json; charset=utf-8', 'X-Teste' => '2'], $r->cabecalhos());
        $r->cabecalhoPadrao('X-Teste', '3');
        $this->assertSame('2', $r->obterCabecalho('x-teste'));
        $r->cookie('rk_sessao', 'abc', ['maxAge' => 60, 'expira' => 0, 'seguro' => true]);
        $this->assertSame('rk_sessao=abc; Path=/; Max-Age=60; Expires=Thu, 01 Jan 1970 00:01:00 GMT; Secure; HttpOnly; SameSite=Lax', $r->cookies()[0]);
        $this->expectException(\InvalidArgumentException::class);
        $r->cabecalho('Location', "http://a\r\nSet-Cookie: x=1");
    }
}
