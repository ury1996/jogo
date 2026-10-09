<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Publicador;

final class PublicadorTest extends TestCase
{
    private string $dir;
    private Publicador $p;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rankly-teste-pub-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
        $this->p = new Publicador($this->dir);
    }

    protected function tearDown(): void
    {
        Publicador::apagarArvore($this->dir);
    }

    public function testGravaComPermissoesEApontaComLinkRelativo(): void
    {
        $caminho = $this->p->gravar('sorriso', '1-abcd1234', ['index.html' => 'v1', 'privacidade/index.html' => 'p'], ['copia.txt' => __FILE__]);
        $this->assertSame($this->dir . '/.releases/sorriso/1-abcd1234', $caminho);
        $this->assertSame('0755', substr(sprintf('%o', fileperms($caminho)), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms($caminho . '/privacidade')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($caminho . '/index.html')), -4));
        $this->assertSame(file_get_contents(__FILE__), file_get_contents($caminho . '/copia.txt'));
        $this->assertNull($this->p->releaseAtual('sorriso'));

        $r = $this->p->apontar('sorriso', '1-abcd1234');
        $this->assertSame(['anterior' => null, 'legado' => null], $r);
        $this->assertTrue(is_link($this->dir . '/sorriso'));
        $this->assertSame('.releases/sorriso/1-abcd1234', readlink($this->dir . '/sorriso'), 'link relativo (pasta pode mudar de lugar)');
        $this->assertSame('v1', file_get_contents($this->dir . '/sorriso/index.html'));

        $this->p->gravar('sorriso', '2-abcd5678', ['index.html' => 'v2'], []);
        $this->assertSame('v1', file_get_contents($this->dir . '/sorriso/index.html'), 'release nova só entra no ar na troca');
        $r = $this->p->apontar('sorriso', '2-abcd5678');
        $this->assertSame('1-abcd1234', $r['anterior']);
        $this->assertSame('v2', file_get_contents($this->dir . '/sorriso/index.html'));
        $this->assertSame([], glob($this->dir . '/.tmp-*') ?: [], 'nenhum link temporário sobra');
        $this->assertSame(['2-abcd5678', '1-abcd1234'], $this->p->listar('sorriso'));
    }

    /** Processo de longa vida (PHP-FPM, php -S): a troca do link aparece na hora no roteador. */
    public function testServidorEstaticoVeATrocaApesarDoCacheDeRealpath(): void
    {
        $this->p->gravar('sorriso', '1-aaaa1111', ['index.html' => 'v1'], []);
        $this->p->gravar('sorriso', '2-bbbb2222', ['index.html' => 'v2'], []);
        $this->p->apontar('sorriso', '1-aaaa1111');
        $servidor = new \Rankly\Gerador\ServidorEstatico($this->dir);
        $this->assertStringEndsWith('/1-aaaa1111', (string) $servidor->pastaDoSite('sorriso'));
        $this->assertStringEndsWith('/1-aaaa1111', (string) realpath($this->dir . '/sorriso'), 'realpath agora está em cache');
        // A troca acontece em OUTRO processo (a API), como na produção.
        $codigo = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' (new Rankly\\Gerador\\Publicador(' . var_export($this->dir, true) . '))->apontar("sorriso", "2-bbbb2222");';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo), $saida, $status);
        $this->assertSame(0, $status, implode("\n", $saida));
        $this->assertStringEndsWith('/2-bbbb2222', (string) $servidor->pastaDoSite('sorriso'));
    }

    public function testFalhaNaGravacaoNaoDeixaReleaseNemMudaOAr(): void
    {
        $this->p->gravar('sorriso', '1-aaaa0000', ['index.html' => 'v1'], []);
        $this->p->apontar('sorriso', '1-aaaa0000');
        try {
            $this->p->gravar('sorriso', '2-bbbb0000', ['index.html' => 'v2'], ['img/x.webp' => $this->dir . '/nao-existe.webp']);
            $this->fail('deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('img/x.webp', $e->getMessage());
        }
        $this->assertSame(['1-aaaa0000'], $this->p->listar('sorriso'));
        $this->assertSame([], glob($this->dir . '/.releases/sorriso/.*.parcial') ?: []);
        $this->assertSame('v1', file_get_contents($this->dir . '/sorriso/index.html'));
    }

    public function testCaminhosInseguros(): void
    {
        foreach (['../fora.html', '/abs.html', 'a/../../b', '.htaccess', 'img/.oculto', "a\0b", 'a//b'] as $ruim) {
            try {
                Publicador::relativoSeguro($ruim);
                $this->fail("aceitou {$ruim}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('img/m_12345678-480.webp', Publicador::relativoSeguro('img/m_12345678-480.webp'));
        $this->expectException(\InvalidArgumentException::class);
        $this->p->dirReleases('../etc');
    }

    public function testLimparMantemAsMaisNovasEADoAr(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->p->gravar('sorriso', $v . '-cafe000' . $v, ['index.html' => "v{$v}"], []);
        }
        $this->p->apontar('sorriso', '2-cafe0002'); // a do ar é antiga (ex.: depois de reverter)
        $removidas = $this->p->limpar('sorriso', 2);
        sort($removidas);
        $this->assertSame(['1-cafe0001', '3-cafe0003'], $removidas);
        $this->assertSame(['5-cafe0005', '4-cafe0004', '2-cafe0002'], $this->p->listar('sorriso'));
        $this->assertSame('v2', file_get_contents($this->dir . '/sorriso/index.html'));
    }

    public function testMigraPastaLegadaComSeguranca(): void
    {
        mkdir($this->dir . '/sorriso');
        file_put_contents($this->dir . '/sorriso/index.html', 'legado');
        $this->p->gravar('sorriso', '3-dddd0000', ['index.html' => 'novo'], []);
        $r = $this->p->apontar('sorriso', '3-dddd0000', 2);
        $this->assertNull($r['anterior']);
        $this->assertMatchesRegularExpression('/^2-legado[0-9a-f]{6}$/', (string) $r['legado']);
        $this->assertTrue(is_link($this->dir . '/sorriso'));
        $this->assertSame('novo', file_get_contents($this->dir . '/sorriso/index.html'));
        $this->assertSame('legado', file_get_contents($this->dir . '/.releases/sorriso/' . $r['legado'] . '/index.html'));
        $this->assertContains($r['legado'], $this->p->listar('sorriso'), 'o legado vira uma release (dá para voltar a ele)');

        $this->p->restaurar('sorriso', $r['legado']);
        $this->assertSame('legado', file_get_contents($this->dir . '/sorriso/index.html'));
    }

    public function testArquivoNoLugarDoSiteNaoESobrescrito(): void
    {
        file_put_contents($this->dir . '/sorriso', 'arquivo estranho');
        $this->p->gravar('sorriso', '1-eeee0000', ['index.html' => 'x'], []);
        try {
            $this->p->apontar('sorriso', '1-eeee0000');
            $this->fail('deveria recusar');
        } catch (\RuntimeException) {
            $this->assertSame('arquivo estranho', file_get_contents($this->dir . '/sorriso'));
            $this->assertSame([], glob($this->dir . '/.tmp-*') ?: []);
        }
    }

    public function testDespublicarRemoveSoOLink(): void
    {
        $this->p->gravar('sorriso', '1-ffff0000', ['index.html' => 'x'], []);
        $this->p->apontar('sorriso', '1-ffff0000');
        $this->p->despublicar('sorriso');
        $this->assertFalse(file_exists($this->dir . '/sorriso'));
        $this->assertSame(['1-ffff0000'], $this->p->listar('sorriso'));
    }

    /** Hospedagem que bloqueia symlink(): a release no ar fica anotada em .no-ar. */
    public function testSemLinksSimbolicosUsaOArquivoNoAr(): void
    {
        $p = new Publicador($this->dir, false);
        $servidor = new \Rankly\Gerador\ServidorEstatico($this->dir);
        $p->gravar('sorriso', '1-aaaa0001', ['index.html' => 'v1'], []);
        $p->gravar('sorriso', '2-aaaa0002', ['index.html' => 'v2'], []);
        $this->assertNull($servidor->pastaDoSite('sorriso'));

        $this->assertSame(['anterior' => null, 'legado' => null], $p->apontar('sorriso', '1-aaaa0001'));
        $this->assertFalse(file_exists($this->dir . '/sorriso') || is_link($this->dir . '/sorriso'), 'nenhum link criado');
        $this->assertSame('1-aaaa0001', $p->releaseAtual('sorriso'));
        $this->assertSame("1-aaaa0001\n", file_get_contents($this->dir . '/.releases/sorriso/.no-ar'));
        $this->assertStringEndsWith('/1-aaaa0001', (string) $servidor->pastaDoSite('sorriso'));
        $this->assertSame(200, $servidor->responder('sorriso', 'GET', '/')->status);

        $this->assertSame('1-aaaa0001', $p->apontar('sorriso', '2-aaaa0002')['anterior']);
        $this->assertStringEndsWith('/2-aaaa0002', (string) $servidor->pastaDoSite('sorriso'));
        $this->assertSame([], glob($this->dir . '/.releases/sorriso/.no-ar.tmp-*') ?: [], 'nenhum temporário sobra');
        $this->assertSame(['2-aaaa0002', '1-aaaa0001'], $p->listar('sorriso'), '.no-ar não é release');

        $p->gravar('sorriso', '3-aaaa0003', ['index.html' => 'v3'], []);
        $p->apontar('sorriso', '1-aaaa0001'); // reverter para uma antiga
        $this->assertSame(['2-aaaa0002'], $p->limpar('sorriso', 1), 'a mais nova e a do ar ficam');
        $p->restaurar('sorriso', '3-aaaa0003');
        $this->assertSame('3-aaaa0003', $p->releaseAtual('sorriso'));

        $p->despublicar('sorriso');
        $this->assertNull($p->releaseAtual('sorriso'));
        $this->assertNull($servidor->pastaDoSite('sorriso'));
        $this->assertSame(['3-aaaa0003', '1-aaaa0001'], $p->listar('sorriso'));
    }

    /** Instalação que muda de servidor: de link para .no-ar e de volta, sem deixar sobra que confunda. */
    public function testTrocaEntreLinkEArquivoNoAr(): void
    {
        $this->p->gravar('sorriso', '1-bbbb0001', ['index.html' => 'v1'], []);
        $this->p->gravar('sorriso', '2-bbbb0002', ['index.html' => 'v2'], []);
        $this->p->gravar('sorriso', '3-bbbb0003', ['index.html' => 'v3'], []);
        $this->p->apontar('sorriso', '1-bbbb0001');
        $semLinks = new Publicador($this->dir, false);
        $this->assertSame('1-bbbb0001', $semLinks->apontar('sorriso', '2-bbbb0002')['anterior']);
        $this->assertFalse(is_link($this->dir . '/sorriso'), 'o link antigo sai');
        $this->assertSame('2-bbbb0002', $this->p->releaseAtual('sorriso'));

        $this->assertSame('2-bbbb0002', $this->p->apontar('sorriso', '3-bbbb0003')['anterior']);
        $this->assertTrue(is_link($this->dir . '/sorriso'));
        $this->assertFileDoesNotExist($this->dir . '/.releases/sorriso/.no-ar', 'o .no-ar velho sai');
        $this->assertSame('v3', file_get_contents($this->dir . '/sorriso/index.html'));
    }

    public function testSemLinksMigraPastaLegada(): void
    {
        mkdir($this->dir . '/sorriso');
        file_put_contents($this->dir . '/sorriso/index.html', 'legado');
        $p = new Publicador($this->dir, false);
        $p->gravar('sorriso', '3-cccc0003', ['index.html' => 'novo'], []);
        $r = $p->apontar('sorriso', '3-cccc0003', 2);
        $this->assertFalse(file_exists($this->dir . '/sorriso'));
        $this->assertSame('3-cccc0003', $p->releaseAtual('sorriso'));
        $p->restaurar('sorriso', $r['legado']);
        $this->assertSame('legado', file_get_contents($p->pastaNoAr('sorriso') . '/index.html'));
    }
}
