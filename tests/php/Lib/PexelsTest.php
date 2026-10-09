<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Lib\ErroPexels;
use Rankly\Lib\Pexels;

/** Cliente do Pexels com transporte falso: busca normalizada, download só do host permitido, erros. */
final class PexelsTest extends TestCase
{
    /** Foto como a API do Pexels devolve. */
    public static function fotoBruta(int $id = 3845810, int $w = 4000, int $h = 6000): array
    {
        $base = "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg";
        return [
            'id' => $id, 'width' => $w, 'height' => $h,
            'url' => "https://www.pexels.com/pt-br/foto/dentista-{$id}/",
            'photographer' => 'Ana Souza', 'photographer_url' => 'https://www.pexels.com/@ana-souza',
            'photographer_id' => 99, 'avg_color' => '#A0B1C2', 'liked' => false,
            'alt' => "Dentista sorrindo no consultório\n",
            'src' => [
                'original' => $base,
                'large2x' => $base . '?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940',
                'large' => $base . '?auto=compress&cs=tinysrgb&h=650&w=940',
                'medium' => $base . '?auto=compress&cs=tinysrgb&h=350',
                'small' => $base . '?auto=compress&cs=tinysrgb&h=130',
            ],
        ];
    }

    public function testBuscaNormalizaAsFotosEMandaChaveNoCabecalho(): void
    {
        $pedidos = [];
        $estranha = self::fotoBruta(2);
        $estranha['src']['medium'] = 'http://169.254.169.254/latest/meta-data';
        $px = new Pexels('chave-teste', 10, function (string $url, array $cab) use (&$pedidos, $estranha): array {
            $pedidos[] = [$url, $cab];
            return [200, json_encode([
                'page' => 2, 'per_page' => 24, 'total_results' => 80, 'next_page' => 'https://api.pexels.com/v1/search?page=3',
                'photos' => [self::fotoBruta(), $estranha, ['id' => 'x']],
            ])];
        });
        $r = $px->buscar("  consultório \n odontológico ", 2, 'retrato');

        [$url, $cab] = $pedidos[0];
        $this->assertStringStartsWith('https://api.pexels.com/v1/search?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['query' => 'consultório odontológico', 'page' => '2', 'per_page' => '24', 'locale' => 'pt-BR', 'orientation' => 'portrait'], $q);
        $this->assertContains('Authorization: chave-teste', $cab);
        $this->assertStringNotContainsString('chave-teste', $url);

        $this->assertSame(2, $r['pagina']);
        $this->assertSame(80, $r['total']);
        $this->assertTrue($r['temMais']);
        $this->assertCount(1, $r['fotos'], 'Fotos com imagens fora de images.pexels.com são descartadas');
        $f = $r['fotos'][0];
        $this->assertSame(['id', 'largura', 'altura', 'miniatura', 'media', 'alt', 'autor', 'autorUrl', 'url', 'cor'], array_keys($f));
        $this->assertSame(3845810, $f['id']);
        $this->assertSame([4000, 6000], [$f['largura'], $f['altura']]);
        $this->assertStringEndsWith('&h=350', $f['miniatura']);
        $this->assertStringEndsWith('&h=650&w=940', $f['media']);
        $this->assertSame('Dentista sorrindo no consultório', $f['alt']);
        $this->assertSame('Ana Souza', $f['autor']);
        $this->assertSame('https://www.pexels.com/@ana-souza', $f['autorUrl']);
        $this->assertSame('#a0b1c2', $f['cor']);
    }

    public function testFotoDevolveUrlDeDownloadLimitadaA2400(): void
    {
        $px = new Pexels('k', 10, fn (string $url): array => str_ends_with($url, '/v1/photos/77')
            ? [200, json_encode(self::fotoBruta(77, 4000, 6000))]
            : [404, '{}']);
        $f = $px->foto(77);
        $this->assertSame('https://images.pexels.com/photos/77/pexels-photo-77.jpeg?auto=compress&cs=tinysrgb&h=2400', $f['download']);

        $pequena = Pexels::urlDownload(self::fotoBruta(5, 1600, 1000));
        $this->assertSame('https://images.pexels.com/photos/5/pexels-photo-5.jpeg?auto=compress&cs=tinysrgb', $pequena);

        try {
            $px->foto(78);
            $this->fail('Foto inexistente deveria falhar');
        } catch (ErroPexels $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testDownloadSoDoHostPermitido(): void
    {
        $chamadas = 0;
        $px = new Pexels('k', 10, function () use (&$chamadas): array {
            $chamadas++;
            return [200, 'bytes-da-foto', 'image/jpeg'];
        });
        foreach ([
            'http://images.pexels.com/photos/1/a.jpeg',
            'https://images.pexels.com.atacante.com/a.jpeg',
            'https://atacante.com/images.pexels.com/a.jpeg',
            'https://user@images.pexels.com/a.jpeg',
            'https://images.pexels.com:8443/a.jpeg',
            'https://127.0.0.1/a.jpeg',
            'file:///etc/passwd',
        ] as $url) {
            try {
                $px->baixar($url, 1024);
                $this->fail("Deveria recusar {$url}");
            } catch (ErroPexels $e) {
                $this->assertSame(422, $e->status, $url);
            }
        }
        $this->assertSame(0, $chamadas, 'Nenhum pedido sai para hosts não permitidos');

        $arquivo = $px->baixar('https://images.pexels.com/photos/1/a.jpeg?w=2400', 1024);
        try {
            $this->assertSame('bytes-da-foto', file_get_contents($arquivo));
        } finally {
            @unlink($arquivo);
        }

        // Acima do limite de tamanho, ou algo que não é imagem: recusado.
        $this->expectException(ErroPexels::class);
        $px->baixar('https://images.pexels.com/photos/1/a.jpeg', 4);
    }

    public function testConteudoQueNaoEImagemERecusado(): void
    {
        $px = new Pexels('k', 10, fn (): array => [200, '<html>', 'text/html']);
        $this->expectException(ErroPexels::class);
        $this->expectExceptionMessage('não é foto');
        $px->baixar('https://images.pexels.com/photos/1/a.jpeg', 1024);
    }

    public function testErrosViramMensagensAmigaveis(): void
    {
        $casos = [
            [429, 429, 'limite de buscas'],
            [0, 503, 'não respondeu'],
            [503, 503, 'fora do ar'],
            [401, 502, 'chave do Pexels é inválida'],
        ];
        foreach ($casos as [$http, $status, $trecho]) {
            $px = new Pexels('k', 10, fn (): array => [$http, '']);
            try {
                $px->buscar('dentista');
                $this->fail("HTTP {$http} deveria falhar");
            } catch (ErroPexels $e) {
                $this->assertSame($status, $e->status, (string) $http);
                $this->assertStringContainsString($trecho, $e->getMessage());
            }
        }
        try {
            (new Pexels('', 10, fn (): array => [200, '{}']))->buscar('dentista');
            $this->fail('Sem chave deveria falhar');
        } catch (ErroPexels $e) {
            $this->assertSame(503, $e->status);
        }
        try {
            (new Pexels('k', 10, fn (): array => [200, '{}']))->buscar("  \n ");
            $this->fail('Termo vazio deveria falhar');
        } catch (ErroPexels $e) {
            $this->assertSame(422, $e->status);
        }
    }
}
