<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Lib\Imagem;

final class ImagemTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rankly-teste-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_dir($f) ? null : @unlink($f);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testLargurasDasVariantes(): void
    {
        $this->assertSame([480, 960, 1600], Imagem::larguras(4000, 'foto'));
        $this->assertSame([480, 960, 1600], Imagem::larguras(1600, 'foto'));
        $this->assertSame([480, 960, 1200], Imagem::larguras(1200, 'foto'));
        $this->assertSame([480, 960], Imagem::larguras(960, 'foto'));
        $this->assertSame([300], Imagem::larguras(300, 'foto'));
        $this->assertSame([160, 320, 640], Imagem::larguras(2000, 'logo'));
        $this->assertSame([160, 200], Imagem::larguras(200, 'logo'));
        $this->assertSame([100], Imagem::larguras(100, 'logo'));
    }

    public function testJpegComOrientacaoExif6EGirado(): void
    {
        $arquivo = Imagens::jpeg($this->dir . '/girada.jpg', 200, 100, 6);
        $this->assertSame(6, Imagem::orientacaoExif($arquivo));
        $info = Imagem::processar($arquivo, 'foto', $this->dir . '/saida', 40);
        // 200×100 deitada com Orientation 6 → 100×200 em pé.
        $this->assertSame(100, $info['largura']);
        $this->assertSame(200, $info['altura']);
        $this->assertSame([100], $info['variantes']);
        // Giro de 90° no sentido horário: a metade esquerda (vermelha) vai para cima.
        [$r, , $b] = Imagens::cor($this->dir . '/saida/orig.jpg', 50, 30);
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(60, $b);
        [$r, , $b] = Imagens::cor($this->dir . '/saida/orig.jpg', 50, 170);
        $this->assertLessThan(60, $r);
        $this->assertGreaterThan(200, $b);
        // O original recodificado não carrega EXIF (orientação, GPS…).
        $this->assertSame(1, Imagem::orientacaoExif($this->dir . '/saida/orig.jpg'));
        $this->assertStringNotContainsString('Exif', (string) file_get_contents($this->dir . '/saida/orig.jpg'));
    }

    public function testOrientacao8Gira90AntiHorario(): void
    {
        $arquivo = Imagens::jpeg($this->dir . '/o8.jpg', 200, 100, 8);
        Imagem::processar($arquivo, 'foto', $this->dir . '/o8', 40);
        // Anti-horário: a metade esquerda (vermelha) vai para baixo.
        [$r] = Imagens::cor($this->dir . '/o8/orig.jpg', 50, 170);
        $this->assertGreaterThan(200, $r);
    }

    public function testMegapixelAcimaDoLimiteRecusadoSemDecodificar(): void
    {
        // 20000×20000 = 400 MP declarados no cabeçalho; decodificar exigiria ~1,6 GB.
        $arquivo = Imagens::jpegGigante($this->dir . '/gigante.jpg', 20000, 20000);
        $this->assertSame('image/jpeg', Imagem::detectarMime($arquivo));
        $memoriaAntes = memory_get_usage();
        try {
            Imagem::processar($arquivo, 'foto', $this->dir . '/g', 40);
            $this->fail('Deveria recusar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('40 megapixels', $e->getMessage());
        }
        $this->assertLessThan(5 * 1024 * 1024, memory_get_usage() - $memoriaAntes);
        $this->assertDirectoryDoesNotExist($this->dir . '/g');
    }

    public function testVariantesWebpEFotoGrande(): void
    {
        $arquivo = Imagens::jpeg($this->dir . '/grande.jpg', 2000, 1000);
        $info = Imagem::processar($arquivo, 'foto', $this->dir . '/v', 40);
        $this->assertSame([480, 960, 1600], $info['variantes']);
        $this->assertSame('orig.jpg', $info['arquivo']);
        foreach ([480 => 240, 960 => 480, 1600 => 800] as $w => $h) {
            $dim = getimagesize($this->dir . "/v/{$w}.webp");
            $this->assertSame([$w, $h], [$dim[0], $dim[1]]);
            $this->assertSame('image/webp', $dim['mime']);
        }
        $this->assertFileDoesNotExist($this->dir . '/v/2000.webp');
    }

    public function testLogoTransparenteViraPngEFotoTransparenteViraJpeg(): void
    {
        $png = Imagens::png($this->dir . '/logo.png', 800, 400, true);
        $logo = Imagem::processar($png, 'logo', $this->dir . '/logo', 40);
        $this->assertSame('orig.png', $logo['arquivo']);
        $this->assertSame('image/png', $logo['mime']);
        $this->assertSame([160, 320, 640], $logo['variantes']);
        $webp = imagecreatefromwebp($this->dir . '/logo/320.webp');
        $this->assertSame(127, (imagecolorat($webp, 1, 1) >> 24) & 0x7F, 'A variante WebP do logo mantém a transparência');

        $foto = Imagem::processar($png, 'foto', $this->dir . '/foto', 40);
        $this->assertSame('orig.jpg', $foto['arquivo']);
        [$r, $g, $b] = Imagens::cor($this->dir . '/foto/orig.jpg', 2, 2);
        $this->assertGreaterThan(240, min($r, $g, $b), 'Transparência achatada sobre branco');

        $opaco = Imagens::png($this->dir . '/opaco.png', 300, 300, false);
        $this->assertSame('orig.jpg', Imagem::processar($opaco, 'logo', $this->dir . '/opaco', 40)['arquivo']);
    }

    public function testFormatosRecusados(): void
    {
        $casos = [
            'heic' => [Imagens::heic($this->dir . '/foto.heic'), 'Formato HEIC não suportado. No iPhone, ajuste Câmera > Formatos > Mais compatível, ou envie JPG.'],
            'gif' => [Imagens::gif($this->dir . '/a.gif'), 'GIF'],
            'texto' => [$this->criar('a.jpg', 'isto não é imagem'), 'não é uma imagem aceita'],
            'php disfarçado' => [$this->criar('x.jpg', "<?php echo 'oi'; ?>"), 'não é uma imagem aceita'],
        ];
        foreach ($casos as $nome => [$arquivo, $mensagem]) {
            try {
                Imagem::processar($arquivo, 'foto', $this->dir . '/x', 40);
                $this->fail("Deveria recusar: {$nome}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensagem, $e->getMessage(), $nome);
            }
        }
    }

    public function testJpegTruncadoERecusado(): void
    {
        $bom = (string) file_get_contents(Imagens::jpeg($this->dir . '/b.jpg', 100, 100));
        $arquivo = $this->criar('truncado.jpg', substr($bom, 0, 200));
        $this->expectException(\InvalidArgumentException::class);
        Imagem::processar($arquivo, 'foto', $this->dir . '/t', 40);
    }

    private function criar(string $nome, string $conteudo): string
    {
        file_put_contents($this->dir . '/' . $nome, $conteudo);
        return $this->dir . '/' . $nome;
    }
}
