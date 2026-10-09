<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Iconify;
use Rankly\Lib\Sites;
use Rankly\Preparo\Preparo;
use Rankly\Testes\Lib\AmbienteTeste;

/** GET /api/icones/buscar e ícones do Iconify guardados no documento (validação, limpeza e uso no site). */
final class IconesTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['iconify' => ['limite_por_hora' => 2]], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->app->definirIconify(new Iconify('https://api.teste', null, [], 5, static function (string $url): array {
            if (str_contains($url, '/search?')) {
                return [200, json_encode(['icons' => ['mdi:tooth'], 'collections' => []])];
            }
            return [200, json_encode(['width' => 24, 'height' => 24, 'icons' => ['tooth' => ['body' => '<path fill="currentColor" d="M5 5h4"/>']]])];
        }));
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testBuscaComLoginTermoELimite(): void
    {
        $this->assertSame(401, (new ClienteApi($this->app))->get('/api/icones/buscar?q=dente')->status);
        $this->assertSame(422, $this->c->get('/api/icones/buscar?q=%20')->status);
        $r = $this->c->get('/api/icones/buscar?q=Dente&dicas=tooth,X%3Cy,implant');
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame('dente', $r->dados()['termo']);
        $this->assertSame('mdi:tooth', $r->dados()['icones'][0]['id']);
        $this->assertTrue(Iconify::svgValido($r->dados()['icones'][0]['svg']['fino']));
        $this->assertSame(200, $this->c->get('/api/icones/buscar?q=casa')->status);
        $this->assertSame(429, $this->c->get('/api/icones/buscar?q=carro')->status, 'limite por hora');

        $this->app->definirIconify(null);
        $this->assertSame(503, $this->c->get('/api/icones/buscar?q=dente')->status);
    }

    public function testDocumentoGuardaSoIconesValidosEUsadosEOSiteUsaODesenho(): void
    {
        $site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso']])->dados()['site'];
        $doc = $site['documento'];
        $lista = 'serv';
        $item = Sites::porId($this->app, (int) $site['id'])['documento']['listas'][$lista][0] ?? '1';
        $svg = Iconify::montarSvg('<path fill="currentColor" d="M5 5h4"/>', 0, 0, 24, 24);
        $doc['icones'] = ["{$lista}.{$item}" => 'mdi:tooth'];
        $doc['iconesExtras'] = [
            'mdi:tooth' => ['nome' => 'tooth', 'svg' => ['fino' => $svg]],
            'mdi:sobrando' => ['nome' => 'sobrando', 'svg' => ['fino' => $svg]],
        ];
        $r = $this->c->put("/api/sites/{$site['id']}", ['revisao' => $site['revisao'], 'documento' => $doc]);
        $this->assertSame(200, $r->status, $r->corpo);
        $salvo = Sites::porId($this->app, (int) $site['id']);
        $this->assertSame(['mdi:tooth'], array_keys($salvo['documento']['iconesExtras']), 'o que nenhum item usa sai');

        // O site montado no servidor usa o desenho do Iconify no item.
        $html = json_encode(Preparo::prepararSite($salvo['documento'], $this->app->biblioteca(), ['modo' => 'publicar']));
        $this->assertStringContainsString('M5 5h4', (string) $html);

        // Desenho adulterado, id inválido ou ícone demais: recusado.
        foreach ([
            ['mdi:tooth' => ['svg' => ['fino' => str_replace('<path', '<path onload="x()"', $svg)]]],
            ['mdi:tooth' => ['svg' => ['fino' => '<svg><script>alert(1)</script></svg>']]],
            ['mdi:tooth' => ['svg' => ['negrito' => $svg]]],
            ['mdi:tooth' => ['svg' => []]],
            ['MDI:tooth' => ['svg' => ['fino' => $svg]]],
            ['mdi:tooth' => ['nome' => str_repeat('x', 101), 'svg' => ['fino' => $svg]]],
            array_fill_keys(array_map(static fn (int $i): string => "mdi:i{$i}", range(1, 61)), ['svg' => ['fino' => $svg]]),
        ] as $extras) {
            $doc['iconesExtras'] = $extras;
            $ruim = $this->c->put("/api/sites/{$site['id']}", ['revisao' => $salvo['revisao'], 'documento' => $doc]);
            $this->assertSame(422, $ruim->status, json_encode($extras));
        }
        $doc['iconesExtras'] = [];
        $doc['icones'] = ["{$lista}.{$item}" => 'mdi:x:y'];
        $this->assertSame(422, $this->c->put("/api/sites/{$site['id']}", ['revisao' => $salvo['revisao'], 'documento' => $doc])->status);
    }
}
