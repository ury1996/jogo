<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Lib\ErroBancoImagens;
use Rankly\Lib\Pixabay;

/** Cliente do Pixabay com transporte falso: busca normalizada, cache de 24 h, download só dos hosts permitidos, erros. */
final class PixabayTest extends TestCase
{
    /** Foto como a API do Pixabay devolve. */
    public static function fotoBruta(int $id = 3845810, int $w = 1280, int $h = 853): array
    {
        return [
            'id' => $id, 'pageURL' => "https://pixabay.com/photos/dentist-teeth-{$id}/", 'type' => 'photo',
            'tags' => 'dentist, teeth, dental',
            'previewURL' => "https://cdn.pixabay.com/photo/2020/01/01/00/00/dentist-{$id}_150.jpg",
            'webformatURL' => "https://pixabay.com/get/g{$id}_640.jpg",
            'largeImageURL' => "https://pixabay.com/get/g{$id}_1280.jpg",
            'imageWidth' => $w, 'imageHeight' => $h,
            'user' => 'AnaSouza', 'user_id' => 99,
        ];
    }

    public function testBuscaNormalizaAsFotosEUsaParametrosDoPixabay(): void
    {
        $pedidos = [];
        $estranha = self::fotoBruta(2);
        $estranha['webformatURL'] = 'http://169.254.169.254/latest/meta-data';
        $px = new Pixabay('chave-teste', null, 10, function (string $url) use (&$pedidos, $estranha): array {
            $pedidos[] = $url;
            return [200, json_encode(['total' => 900, 'totalHits' => 80, 'hits' => [self::fotoBruta(), $estranha, ['id' => 'x']]])];
        });
        $r = $px->buscar("  consultório \n odontológico ", 2, 'retrato');

        $this->assertStringStartsWith('https://pixabay.com/api/?', $pedidos[0]);
        parse_str((string) parse_url($pedidos[0], PHP_URL_QUERY), $q);
        $this->assertSame([
            'key' => 'chave-teste', 'image_type' => 'photo', 'lang' => 'pt', 'orientation' => 'vertical', 'page' => '2',
            'per_page' => '24', 'q' => 'consultório odontológico', 'safesearch' => 'true',
        ], $q);

        $this->assertSame(2, $r['pagina']);
        $this->assertSame(80, $r['total']);
        $this->assertTrue($r['temMais']);
        $this->assertCount(1, $r['fotos'], 'Fotos com imagens fora do Pixabay são descartadas');
        $f = $r['fotos'][0];
        $this->assertSame(['id', 'largura', 'altura', 'miniatura', 'media', 'alt', 'autor', 'autorUrl', 'url', 'cor'], array_keys($f));
        $this->assertSame(3845810, $f['id']);
        $this->assertSame([1280, 853], [$f['largura'], $f['altura']]);
        $this->assertSame('https://pixabay.com/get/g3845810_640.jpg', $f['miniatura']);
        $this->assertSame('AnaSouza', $f['autor']);
        $this->assertSame('https://pixabay.com/users/AnaSouza-99/', $f['autorUrl']);
        $this->assertSame('https://pixabay.com/photos/dentist-teeth-3845810/', $f['url']);

        $ultima = (new Pixabay('k', null, 10, fn (): array => [200, json_encode(['totalHits' => 48, 'hits' => []])]))->buscar('x', 2);
        $this->assertFalse($ultima['temMais']);
    }

    public function testRespostasFicamEmCachePor24Horas(): void
    {
        $pasta = sys_get_temp_dir() . '/pixabay-teste-' . bin2hex(random_bytes(4));
        $pedidos = 0;
        $px = new Pixabay('k', $pasta, 10, function () use (&$pedidos): array {
            $pedidos++;
            return [200, json_encode(['totalHits' => 1, 'hits' => [self::fotoBruta()]])];
        });
        try {
            $px->buscar('dentista');
            $px->buscar('dentista');
            $this->assertSame(1, $pedidos, 'Mesma busca não vai de novo ao Pixabay');
            $px->buscar('dentista', 2);
            $this->assertSame(2, $pedidos);
            foreach (glob($pasta . '/*.json') ?: [] as $arquivo) {
                $this->assertStringNotContainsString('"k"', (string) file_get_contents($arquivo));
                touch($arquivo, time() - Pixabay::CACHE_SEGUNDOS - 10);
            }
            $px->buscar('dentista');
            $this->assertSame(3, $pedidos, 'Depois de 24 h, busca de novo');
        } finally {
            array_map('unlink', glob($pasta . '/*') ?: []);
            @rmdir($pasta);
        }
    }

    public function testFotoDevolveAVersaoGrande(): void
    {
        $px = new Pixabay('k', null, 10, fn (string $url): array => str_contains($url, 'id=77')
            ? [200, json_encode(['totalHits' => 1, 'hits' => [self::fotoBruta(77)]])]
            : [200, json_encode(['totalHits' => 0, 'hits' => []])]);
        $this->assertSame('https://pixabay.com/get/g77_1280.jpg', $px->foto(77)['download']);
        try {
            $px->foto(78);
            $this->fail('Foto inexistente deveria falhar');
        } catch (ErroBancoImagens $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testDownloadSoDosHostsPermitidosInclusiveNosRedirecionamentos(): void
    {
        $chamadas = [];
        $px = new Pixabay('k', null, 10, function (string $url) use (&$chamadas): array {
            $chamadas[] = $url;
            return match ($url) {
                'https://pixabay.com/get/redireciona.jpg' => [302, '', '', 'https://cdn.pixabay.com/photo/a_1280.jpg'],
                'https://pixabay.com/get/fuga.jpg' => [302, '', '', 'https://169.254.169.254/latest'],
                default => [200, 'bytes-da-foto', 'image/jpeg'],
            };
        });
        foreach ([
            'http://pixabay.com/get/a.jpg',
            'https://pixabay.com.atacante.com/a.jpg',
            'https://atacante.com/pixabay.com/a.jpg',
            'https://user@pixabay.com/a.jpg',
            'https://pixabay.com:8443/a.jpg',
            'https://127.0.0.1/a.jpg',
            'file:///etc/passwd',
        ] as $url) {
            try {
                $px->baixar($url, 1024);
                $this->fail("Deveria recusar {$url}");
            } catch (ErroBancoImagens $e) {
                $this->assertSame(422, $e->status, $url);
            }
        }
        $this->assertSame([], $chamadas, 'Nenhum pedido sai para hosts não permitidos');

        $arquivo = $px->baixar('https://pixabay.com/get/redireciona.jpg', 1024);
        try {
            $this->assertSame('bytes-da-foto', file_get_contents($arquivo));
            $this->assertSame(['https://pixabay.com/get/redireciona.jpg', 'https://cdn.pixabay.com/photo/a_1280.jpg'], $chamadas);
        } finally {
            @unlink($arquivo);
        }
        try {
            $px->baixar('https://pixabay.com/get/fuga.jpg', 1024);
            $this->fail('Redirecionamento para fora do Pixabay deveria falhar');
        } catch (ErroBancoImagens $e) {
            $this->assertSame(422, $e->status);
        }

        $this->expectException(ErroBancoImagens::class);
        $px->baixar('https://cdn.pixabay.com/photo/a.jpg', 4);
    }

    public function testConteudoQueNaoEImagemERecusado(): void
    {
        $px = new Pixabay('k', null, 10, fn (): array => [200, '<html>', 'text/html']);
        $this->expectException(ErroBancoImagens::class);
        $this->expectExceptionMessage('não é foto');
        $px->baixar('https://cdn.pixabay.com/photo/a.jpg', 1024);
    }

    public function testErrosViramMensagensAmigaveis(): void
    {
        $casos = [
            [429, '', 429, 'limite de buscas'],
            [0, '', 503, 'não respondeu'],
            [503, '', 503, 'fora do ar'],
            [400, '[ERROR 400] Invalid or missing API key', 502, 'chave do Pixabay é inválida'],
        ];
        foreach ($casos as [$http, $corpo, $status, $trecho]) {
            $px = new Pixabay('k', null, 10, fn (): array => [$http, $corpo]);
            try {
                $px->buscar('dentista');
                $this->fail("HTTP {$http} deveria falhar");
            } catch (ErroBancoImagens $e) {
                $this->assertSame($status, $e->status, (string) $http);
                $this->assertStringContainsString($trecho, $e->getMessage());
            }
        }
        try {
            (new Pixabay('', null, 10, fn (): array => [200, '{}']))->buscar('dentista');
            $this->fail('Sem chave deveria falhar');
        } catch (ErroBancoImagens $e) {
            $this->assertSame(503, $e->status);
        }
        try {
            (new Pixabay('k', null, 10, fn (): array => [200, '{}']))->buscar("  \n ");
            $this->fail('Termo vazio deveria falhar');
        } catch (ErroBancoImagens $e) {
            $this->assertSame(422, $e->status);
        }
    }
}
