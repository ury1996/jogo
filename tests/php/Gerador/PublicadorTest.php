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
}
