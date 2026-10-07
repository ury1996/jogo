<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\LimiteTaxa;
use Rankly\Lib\Sites;
use Rankly\Lib\Slug;

final class SitesLibTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['dominio_sites' => 'sitesrankly.com.br'], $this->dir);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testSlug(): void
    {
        $this->assertSame('clinicasorrisovivo', Slug::base('Clínica Sorriso Vivo'));
        $this->assertSame('joaoacoesltda', Slug::base('João & Ações Ltda.'));
        $this->assertSame('', Slug::base('!!! ???'));
        $this->assertSame(40, strlen(Slug::base(str_repeat('ab', 40))));
        $usados = ['sorrisovivo', 'sorrisovivo2'];
        $this->assertSame('sorrisovivo3', Slug::unico('Sorriso Vivo', fn (string $s): bool => in_array($s, $usados, true)));
        $this->assertSame('site', Slug::unico('***', fn (): bool => false));
        $this->assertSame('api2', Slug::unico('API', fn (): bool => false));
        $this->assertTrue(Slug::valido('abc123'));
        $this->assertFalse(Slug::valido('abc-123'));
        $this->assertFalse(Slug::valido(str_repeat('a', 41)));
    }

    public function testPorHostSubdominioEDominioProprio(): void
    {
        $site = AmbienteTeste::site($this->app, 'sorrisovivo');
        $this->assertSame('https://sorrisovivo.sitesrankly.com.br', $this->app->urlSite('sorrisovivo'));
        $this->assertSame((int) $site['id'], Sites::porHost($this->app, 'sorrisovivo.sitesrankly.com.br')['id']);
        $this->assertSame((int) $site['id'], Sites::porHost($this->app, 'SorrisoVivo.SitesRankly.com.br:443')['id']);
        $this->assertSame('Clínica Sorriso Vivo', Sites::porHost($this->app, 'sorrisovivo.sitesrankly.com.br.')['documento']['dados']['nome']);
        $this->assertNull(Sites::porHost($this->app, 'outro.sitesrankly.com.br'));
        $this->assertNull(Sites::porHost($this->app, 'a.b.sitesrankly.com.br'));
        $this->assertNull(Sites::porHost($this->app, 'sitesrankly.com.br'));
        $this->assertNull(Sites::porHost($this->app, 'sorrisovivo.sitesrankly.com.br.evil.example'));

        $this->app->db()->inserir('dominios', ['site_id' => $site['id'], 'dominio' => 'sorrisovivo.com.br', 'status' => 'pendente', 'criado_em' => $this->app->agoraSql()]);
        $this->assertNull(Sites::porHost($this->app, 'sorrisovivo.com.br'), 'Domínio pendente ainda não atende');
        $this->app->db()->atualizar('dominios', ['status' => 'ativo'], ['dominio' => 'sorrisovivo.com.br']);
        $this->assertSame((int) $site['id'], Sites::porHost($this->app, 'sorrisovivo.com.br')['id']);
        $this->assertSame((int) $site['id'], Sites::porHost($this->app, 'www.sorrisovivo.com.br')['id']);
        $this->assertNull(Sites::porHost($this->app, ''));
    }

    public function testPorHostEmDevComPorta(): void
    {
        $dir = null;
        $app = AmbienteTeste::criar(['dominio_sites' => 'localhost:8081', 'protocolo_sites' => 'http'], $dir);
        try {
            $site = AmbienteTeste::site($app, 'sorrisovivo');
            $this->assertSame('http://sorrisovivo.localhost:8081', $app->urlSite('sorrisovivo'));
            $this->assertSame((int) $site['id'], Sites::porHost($app, 'sorrisovivo.localhost:8081')['id']);
        } finally {
            AmbienteTeste::remover($dir);
        }
    }

    public function testPodeAcessar(): void
    {
        $site = AmbienteTeste::site($this->app, 'acesso');
        $admin = AmbienteTeste::usuario($this->app, 'admin');
        $cliente = AmbienteTeste::usuario($this->app, 'cliente');
        $this->assertTrue(Sites::podeAcessar($this->app, $admin, (int) $site['id']));
        $this->assertFalse(Sites::podeAcessar($this->app, $cliente, (int) $site['id']));
        $this->app->db()->inserir('site_acessos', ['site_id' => $site['id'], 'usuario_id' => $cliente['id'], 'papel' => 'dono']);
        $this->assertTrue(Sites::podeAcessar($this->app, $cliente, (int) $site['id']));
    }

    public function testLimiteTaxaComJanela(): void
    {
        $l = new LimiteTaxa($this->app);
        $chave = LimiteTaxa::chave('teste', 'a', 'b');
        $this->assertMatchesRegularExpression('/^teste:[0-9a-f]{64}$/', $chave);
        $this->assertSame(0, $l->contagem($chave, 60));
        $this->assertSame(1, $l->registrar($chave, 60));
        $this->assertSame(2, $l->registrar($chave, 60));
        $this->assertTrue($l->excedido($chave, 2, 60));
        $this->assertSame(60, $l->segundosRestantes($chave, 60));
        $this->app->definirAgora($this->app->agora()->modify('+61 seconds'));
        $this->assertFalse($l->excedido($chave, 2, 60));
        $this->assertSame(1, $l->registrar($chave, 60), 'Janela nova recomeça a contagem');
        $l->limpar($chave);
        $this->assertSame(0, $l->contagem($chave, 60));
    }

    public function testIsoEDocumentoParaJson(): void
    {
        $this->assertSame('2026-10-06T18:00:00Z', Sites::iso('2026-10-06 18:00:00'));
        $this->assertNull(Sites::iso(null));
        $json = json_encode(Sites::documentoParaJson(['textos' => [], 'listas' => ['serv' => []], 'dados' => ['horarios' => []]]));
        $this->assertSame('{"textos":{},"listas":{"serv":[]},"dados":{"horarios":{}}}', $json);
    }
}
