<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Lib\ErroBancoImagens;
use Rankly\Lib\Iconify;

/** Iconify: SVG montado só com desenho, busca (coleções, licenças, pesos do Phosphor), cache e tradução. */
final class IconifyTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rankly-iconify-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMontaSvgNoFormatoDaBibliotecaEConfereDepois(): void
    {
        $svg = Iconify::montarSvg('<g fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.6-3.7"/><circle cx="12" cy="12" r="3"/></g>', 0, 0, 24, 24);
        $this->assertSame('<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
            . '<g fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.6-3.7"/><circle cx="12" cy="12" r="3"/></g></svg>', $svg);
        $this->assertTrue(Iconify::svgValido($svg));
        $this->assertSame('<svg viewBox="-1.5 0 256 256" fill="currentColor" aria-hidden="true" focusable="false"><path opacity=".2" d="M1 1"/></svg>',
            Iconify::montarSvg('<path opacity=".2" d="M1 1"></path>', -1.5, 0, 256, 256));

        // Formato alterado depois de montado (documento adulterado) não passa.
        foreach ([
            str_replace('<svg ', '<svg onload="x()" ', $svg),
            str_replace('focusable="false">', 'focusable="false"><script>x()</script>', $svg),
            str_replace('<circle', '<circle onclick="x()"', $svg),
            str_replace('<circle cx="12" cy="12" r="3"/>', '<image href="https://x.test/a.png"/>', $svg),
            str_replace('fill="none"', 'fill="url(#a)"', $svg),
            str_replace('<circle cx="12" cy="12" r="3"/>', '<circle cx="12" cy="12" r="3"></circle>', $svg),
            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"></svg>',
            123,
        ] as $ruim) {
            $this->assertFalse(Iconify::svgValido($ruim), is_string($ruim) ? $ruim : 'não texto');
        }
    }

    public function testCorpoComQualquerCoisaAlemDeDesenhoERecusado(): void
    {
        foreach ([
            '<script>alert(1)</script>',
            '<path d="M1 1"/><style>path{fill:red}</style>',
            '<use href="#a"/>',
            '<a href="javascript:x()"><path d="M1 1"/></a>',
            '<path d="M1 1" onload="x()"/>',
            '<path d="M1 1" style="fill:red"/>',
            '<path d="M1 1" xlink:href="#a" xmlns:xlink="http://www.w3.org/1999/xlink"/>',
            '<text>oi</text>',
            'texto solto',
            '<!DOCTYPE x [<!ENTITY a "b">]><path d="&a;"/>',
            '<!-- comentário --><path d="M1 1"/>',
            '<foreignObject><div/></foreignObject>',
            '<path d="M1 1"><animate attributeName="d"/></path>',
            '<mask id="m"><path d="M1 1"/></mask>',
            '<path fill="URL(#x)" d="M1 1"/>',
            '<path d="M1 1"',
            '',
        ] as $corpo) {
            $this->assertNull(Iconify::montarSvg($corpo, 0, 0, 24, 24), $corpo);
        }
        $this->assertNull(Iconify::montarSvg('<path d="M1 1"/>', 0, 0, 0, 24), 'sem tamanho');
    }

    /** Iconify falso: busca devolve ids; JSON da coleção devolve os desenhos. */
    private function iconify(array $busca, ?callable $tradutor = null, int $status = 200): Iconify
    {
        $colecoes = [
            'ph' => ['width' => 256, 'height' => 256, 'icons' => [
                'tooth-light' => ['body' => '<path fill="currentColor" d="M1 1"/>'],
                'tooth-duotone' => ['body' => '<g fill="currentColor"><path d="M2 2" opacity=".2"/><path d="M3 3"/></g>'],
                'tooth-fill' => ['body' => '<path fill="currentColor" d="M4 4"/>'],
            ]],
            'mdi' => ['width' => 24, 'height' => 24, 'icons' => [
                'tooth' => ['body' => '<path fill="currentColor" d="M5 5"/>'],
                'ruim' => ['body' => '<path d="M1 1"/><script>x()</script>'],
                'girado' => ['body' => '<path d="M6 6"/>', 'rotate' => 1],
            ], 'aliases' => ['dente' => ['parent' => 'tooth']]],
            'tabler' => ['width' => 24, 'height' => 24, 'icons' => ['dental' => ['body' => '<path fill="none" stroke="currentColor" d="M7 7"/>']]],
        ];
        return new Iconify('https://api.teste', $this->dir, ['ph', 'mdi', 'tabler', 'nao-aceita'], 5, function (string $url) use ($busca, $colecoes, $status): array {
            $this->urls[] = $url;
            if ($status !== 200) {
                return [$status, ''];
            }
            $partes = parse_url($url);
            parse_str($partes['query'] ?? '', $q);
            if (($partes['path'] ?? '') === '/search') {
                return [200, json_encode([
                    'icons' => $busca[$q['query']] ?? [],
                    'collections' => ['ph' => ['license' => ['spdx' => 'MIT']], 'mdi' => ['license' => ['spdx' => 'Apache-2.0']], 'tabler' => ['license' => ['spdx' => 'CC-BY-4.0']]],
                ])];
            }
            $prefixo = basename((string) $partes['path'], '.json');
            $json = $colecoes[$prefixo] ?? ['icons' => []];
            $pedidos = explode(',', (string) ($q['icons'] ?? ''));
            $json['icons'] = array_intersect_key($json['icons'], array_flip($pedidos));
            return [200, json_encode($json)];
        }, $tradutor);
    }

    public function testBuscaJuntaPesosDoPhosphorFiltraColecoesELicencasEUsaCache(): void
    {
        $i = $this->iconify(['tooth' => [
            'ph:tooth', 'ph:tooth-bold', 'ph:tooth-fill', 'ph:tooth-light', 'mdi:tooth', 'mdi:dente', 'mdi:ruim', 'mdi:girado',
            'tabler:dental', 'solar:tooth-bold', 'mdi:nao-existe', 'MDI:X', 'lixo',
        ]]);
        $r = $i->buscar('  Tooth! ');
        $this->assertSame(['ph:tooth', 'mdi:tooth', 'mdi:dente'], array_column($r['icones'], 'id'), 'Phosphor uma vez; licença CC-BY, coleção fora da lista e ícone inseguro ficam de fora');
        $ph = $r['icones'][0];
        $this->assertSame(['fino', 'duotone', 'preenchido'], array_keys($ph['svg']));
        $this->assertStringContainsString('viewBox="0 0 256 256"', $ph['svg']['duotone']);
        $this->assertStringContainsString('<path d="M2 2" opacity=".2"/>', $ph['svg']['duotone']);
        $this->assertSame(['fino'], array_keys($r['icones'][1]['svg']));
        $this->assertStringContainsString('d="M5 5"', $r['icones'][2]['svg']['fino'], 'apelido segue o ícone original');
        foreach ($r['icones'] as $icone) {
            foreach ($icone['svg'] as $s) {
                $this->assertTrue(Iconify::svgValido($s));
            }
        }
        $this->assertSame(['ph' => 'Phosphor', 'mdi' => 'Material Design'], $r['colecoes']);
        $this->assertStringContainsString('prefixes=ph%2Cmdi%2Ctabler', $this->urls[0]);

        $antes = count($this->urls);
        $this->assertSame($r, $i->buscar('tooth'), 'mesma busca vem do cache');
        $this->assertCount($antes, $this->urls);
    }

    public function testPalavraInteiraVemPrimeiroETermoDentroDeOutraPalavraSai(): void
    {
        $nomes = ['bluetooth', 'cog-6-tooth', 'sawtooth-wave', 'tooth-outline', 'tooth', 'toothbrush'];
        $colecao = ['width' => 24, 'height' => 24, 'icons' => array_fill_keys($nomes, ['body' => '<path d="M1 1"/>'])];
        $poucos = new Iconify('https://api.teste', null, ['mdi'], 5, static function (string $url) use ($nomes, $colecao): array {
            return str_contains($url, '/search?')
                ? [200, json_encode(['icons' => array_map(static fn (string $n): string => "mdi:{$n}", $nomes)])]
                : [200, json_encode($colecao)];
        });
        $this->assertSame(['mdi:tooth-outline', 'mdi:tooth', 'mdi:cog-6-tooth', 'mdi:bluetooth', 'mdi:sawtooth-wave', 'mdi:toothbrush'],
            array_column($poucos->buscar('tooth')['icones'], 'id'), 'com poucos resultados bons, os parciais ficam (no fim)');

        $muitos = array_merge(array_map(static fn (int $i): string => "tooth-{$i}", range(1, 8)), $nomes);
        $colecao['icons'] = array_fill_keys($muitos, ['body' => '<path d="M1 1"/>']);
        $i = new Iconify('https://api.teste', null, ['mdi'], 5, static function (string $url) use ($muitos, $colecao): array {
            return str_contains($url, '/search?')
                ? [200, json_encode(['icons' => array_map(static fn (string $n): string => "mdi:{$n}", $muitos)])]
                : [200, json_encode($colecao)];
        });
        $ids = array_column($i->buscar('tooth')['icones'], 'id');
        $this->assertNotContains('mdi:bluetooth', $ids);
        $this->assertNotContains('mdi:toothbrush', $ids);
        $this->assertSame('mdi:cog-6-tooth', end($ids));
    }

    public function testTermoEmPortuguesUsaDicasETraducaoComCache(): void
    {
        $traduzidos = [];
        $tradutor = function (string $termo) use (&$traduzidos): array {
            $traduzidos[] = $termo;
            return ['Tooth', 'tooth', 'dente com acento é', '<script>'];
        };
        $i = $this->iconify(['tooth' => ['mdi:tooth'], 'implant' => ['ph:tooth-light']], $tradutor);
        $r = $i->buscar('dente', ['implant', 'x y!']);
        $this->assertSame(['ph:tooth', 'mdi:tooth'], array_column($r['icones'], 'id'));
        $this->assertSame(['dente'], $traduzidos);
        $i->buscar('dente');
        $this->assertSame(['dente'], $traduzidos, 'tradução fica em cache');
    }

    public function testErrosViramMensagemComStatus(): void
    {
        foreach ([[0, 503, 'não respondeu'], [429, 429, 'Muitas buscas'], [500, 503, 'fora do ar'], [403, 502, 'recusou']] as [$http, $status, $texto]) {
            try {
                $this->iconify([], null, $http)->buscar('casa' . $http);
                $this->fail('deveria falhar');
            } catch (ErroBancoImagens $e) {
                $this->assertSame($status, $e->status);
                $this->assertStringContainsString($texto, $e->getMessage());
            }
        }
        $this->expectException(ErroBancoImagens::class);
        $this->iconify([])->buscar(' !! ');
    }

    public function testLimparCacheApagaSoBuscasVelhas(): void
    {
        mkdir($this->dir);
        touch($this->dir . '/busca-velha.json', time() - Iconify::CACHE_SEGUNDOS - 10);
        touch($this->dir . '/busca-nova.json');
        touch($this->dir . '/traducao-x.json', time() - Iconify::CACHE_SEGUNDOS - 10);
        $this->assertSame(1, Iconify::limparCache($this->dir));
        $this->assertFileExists($this->dir . '/busca-nova.json');
        $this->assertFileExists($this->dir . '/traducao-x.json');
    }
}
