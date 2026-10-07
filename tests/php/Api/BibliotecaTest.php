<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Preparo\Biblioteca;
use Rankly\Testes\Lib\AmbienteTeste;

final class BibliotecaTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testBundleComEtagECache304(): void
    {
        $c = new ClienteApi($this->app);
        $r = $c->get('/api/biblioteca');
        $this->assertSame(200, $r->status);
        $versao = Biblioteca::versao(AmbienteTeste::fixtureBiblioteca());
        $this->assertSame('"' . $versao . '"', $r->obterCabecalho('ETag'));
        $this->assertSame('no-cache', $r->obterCabecalho('Cache-Control'));
        $dados = $r->dados();
        $this->assertSame(['versao', 'secoes', 'parciais', 'baseCss', 'modelos', 'nichos', 'comum', 'icones', 'fontes'], array_keys($dados));
        $this->assertSame($versao, $dados['versao']);
        $this->assertSame(Biblioteca::carregar(AmbienteTeste::fixtureBiblioteca()), $dados, 'Mesmo bundle de Preparo\\Biblioteca::carregar');
        $this->assertNotEmpty(glob($this->app->dir('var') . '/cache/biblioteca-*.json'), 'Cache em var/cache');

        $r304 = $c->get('/api/biblioteca', ['If-None-Match' => '"' . $versao . '"']);
        $this->assertSame(304, $r304->status);
        $this->assertSame('', $r304->corpo);

        // Uma segunda aplicação (outra requisição) lê do cache em disco.
        $app2 = Aplicacao::iniciar(AmbienteTeste::config($this->dir, AmbienteTeste::extraBanco()));
        $this->assertSame($versao, $app2->bundleBiblioteca()['versao']);
        $this->assertArrayNotHasKey('dados', $app2->bundleBiblioteca(), 'Veio do cache, sem recarregar a pasta');
        $this->assertSame($dados['nichos'], $app2->biblioteca()['nichos']);
    }

    public function testFontesComCacheLongoENomesValidados(): void
    {
        $c = new ClienteApi($this->app);
        $r = $c->get('/api/fontes/manrope-latin-wght-normal.woff2');
        $this->assertSame(200, $r->status);
        $this->assertSame('font/woff2', $r->obterCabecalho('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $r->obterCabecalho('Cache-Control'));
        $this->assertFileEquals(AmbienteTeste::fixtureBiblioteca() . '/fontes/manrope-latin-wght-normal.woff2', (string) $r->arquivo);
        foreach (['fontes.json', '..%2Ffontes.json', 'nao-existe.woff2', 'Manrope.woff2', '.woff2'] as $nome) {
            $this->assertSame(404, $c->get('/api/fontes/' . $nome)->status, $nome);
        }
        $this->assertSame(404, $c->get('/api/fontes/../nichos/clinicas.json')->status);
    }
}
