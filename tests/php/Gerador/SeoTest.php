<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Montagem;
use Rankly\Gerador\Seo;
use Rankly\Testes\Lib\AmbienteTeste;

final class SeoTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testTituloEDescricaoPadrao(): void
    {
        $app = SiteExemplo::app([], $this->dir);
        $site = SiteExemplo::site($app);
        $m = Montagem::criar($app, $site);
        $this->assertSame('Clínica Sorriso Vivo · Dentista em Jundiaí', Seo::titulo($m));
        $descricao = Seo::descricao($m);
        $this->assertNotSame('', $descricao);
        $this->assertLessThanOrEqual(Seo::MAX_DESCRICAO + 1, mb_strlen($descricao));

        $doc = $site['documento'];
        $doc['seo'] = ['titulo' => 'Dentista {nome}', 'descricao' => 'Atendimento em {cidade}.'];
        $m = Montagem::criar($app, SiteExemplo::salvar($app, (int) $site['id'], $doc));
        $this->assertSame('Dentista Clínica Sorriso Vivo', Seo::titulo($m));
        $this->assertSame('Atendimento em Jundiaí.', Seo::descricao($m));
    }

    public function testCortarSemQuebrarPalavra(): void
    {
        $texto = str_repeat('palavra ', 40);
        $r = Seo::cortar($texto, 155);
        $this->assertLessThanOrEqual(156, mb_strlen($r));
        $this->assertStringEndsWith('palavra…', $r);
        $this->assertSame('Curto.', Seo::cortar('  Curto.  '));
    }

    public function testHorariosAgrupados(): void
    {
        $h = Seo::horarios(['seg' => ['08:00', '18:00'], 'ter' => ['08:00', '18:00'], 'sab' => ['8:00', '12:00', '14:00', '16:00'], 'dom' => null, 'qua' => 'x']);
        $this->assertSame([
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday'], 'opens' => '08:00', 'closes' => '18:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '08:00', 'closes' => '12:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '14:00', 'closes' => '16:00'],
        ], $h);
    }

    public function testCspLiberaTerceirosSoSeConfigurados(): void
    {
        $limpa = Seo::csp(["'sha256-abc'"], ['gtm' => '', 'ga4' => '', 'metaPixel' => '']);
        $this->assertStringContainsString("script-src 'self' 'sha256-abc';", $limpa);
        $this->assertStringContainsString('frame-src https://www.google.com;', $limpa);
        $this->assertStringContainsString("form-action 'self'", $limpa);
        $this->assertStringContainsString("object-src 'none'", $limpa);
        $this->assertStringNotContainsString('googletagmanager', $limpa);
        $this->assertStringNotContainsString('facebook', $limpa);
        preg_match('/script-src ([^;]*)/', $limpa, $m);
        $this->assertStringNotContainsString('unsafe-inline', $m[1], 'scripts só pelo hash');

        $google = Seo::csp(["'sha256-abc'"], ['gtm' => 'GTM-ABCD123', 'ga4' => '', 'metaPixel' => '']);
        $this->assertStringContainsString('https://www.googletagmanager.com', $google);
        $this->assertMatchesRegularExpression('/connect-src [^;]*google-analytics\.com/', $google);
        $this->assertStringNotContainsString('facebook', $google);

        $meta = Seo::csp(["'sha256-abc'"], ['gtm' => '', 'ga4' => '', 'metaPixel' => '123456789']);
        $this->assertMatchesRegularExpression('/script-src [^;]*https:\/\/connect\.facebook\.net/', $meta);
        $this->assertStringNotContainsString('googletagmanager', $meta);
    }

    public function testSitemapERobots(): void
    {
        $xml = Seo::sitemap([['url' => 'https://a.b/', 'data' => '2026-10-06'], ['url' => 'https://a.b/privacidade/', 'data' => '2026-10-06']]);
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $this->assertCount(2, $doc->url);
        $this->assertSame("User-agent: *\nAllow: /\n\nSitemap: https://a.b/sitemap.xml\n", Seo::robots('https://a.b'));
    }

    public function testJsonSeguroParaScript(): void
    {
        $json = Seo::json(['a' => '</script> &']);
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertSame(['a' => '</script> &'], json_decode($json, true));
    }
}
