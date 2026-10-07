<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Gerador\ErroValidacao;
use Rankly\Gerador\Gerador;
use Rankly\Gerador\ScriptSite;
use Rankly\Lib\Sites;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Publicação de ponta a ponta no disco (SQLite + pasta temporária, biblioteca real).
 */
final class GeradorTest extends TestCase
{
    private ?string $dir = null;
    private Aplicacao $app;
    private array $site;
    private array $usuario;
    private array $midias;

    protected function setUp(): void
    {
        $this->app = SiteExemplo::app(['releases_mantidas' => 3], $this->dir);
        $this->app->definirAgora(new \DateTimeImmutable('2026-10-06 15:00:00', new \DateTimeZone('UTC')));
        $this->usuario = AmbienteTeste::usuario($this->app);
        $site = SiteExemplo::site($this->app);
        $this->midias = SiteExemplo::midias($this->app, (int) $site['id'], $this->dir);
        $doc = $site['documento'];
        $doc['imagens'] = ['hero.img' => $this->midias['foto'], 'sobre.img' => $this->midias['foto']];
        $doc['dados']['logo'] = $this->midias['logo'];
        $this->site = SiteExemplo::salvar($this->app, (int) $site['id'], $doc);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function publicar(): array
    {
        $site = Sites::porId($this->app, (int) $this->site['id']);
        return (new Gerador($this->app))->publicar($site, (int) $this->usuario['id']);
    }

    private function pasta(): string
    {
        return $this->dir . '/sites/sorrisovivo';
    }

    public function testPublicaArquivosCompletos(): void
    {
        $r = $this->publicar();
        $this->assertSame(['url' => 'https://sorrisovivo.sites.teste', 'versao' => 1], $r);
        $p = $this->pasta();
        foreach (['index.html', 'privacidade/index.html', 'obrigado/index.html', '404.html', 'sitemap.xml', 'robots.txt', 'favicon.png', 'apple-touch-icon.png'] as $arq) {
            $this->assertFileExists($p . '/' . $arq);
        }
        $html = (string) file_get_contents($p . '/index.html');

        // Sem atributos de edição; os de rastreamento/formulário ficam.
        foreach (['data-k=', 'data-img=', 'data-ic=', 'data-li=', 'data-it=', 'data-sec=', 'data-fundo'] as $attr) {
            $this->assertStringNotContainsString($attr, $html, $attr);
        }
        $this->assertStringContainsString('data-ev="whatsapp"', $html);
        $this->assertStringContainsString('data-pos="hero"', $html);
        $this->assertStringStartsWith("<!doctype html>\n<html lang=\"pt-BR\">", $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<title>Clínica Sorriso Vivo · Dentista em Jundiaí</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://sorrisovivo.sites.teste/">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#', $html);
        $this->assertMatchesRegularExpression('#<body class="rk k-moderno f-[a-z]+">#', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'um único h1');
        $this->assertMatchesRegularExpression('#<link rel="preload" href="/fontes/[a-z0-9-]+\.woff2" as="font" type="font/woff2" crossorigin>#', $html);

        // CSS: sem @container/cqi; embutido ou em assets/site.{hash8}.css.
        $css = $this->cssDe($html);
        $this->assertStringNotContainsString('@container', $css);
        $this->assertDoesNotMatchRegularExpression('/\dcqi\b/', $css);
        $this->assertStringContainsString('@media (max-width:760px)', $css);
        $this->assertStringContainsString('font-display:swap', $css);
        $this->assertStringContainsString('size-adjust:', $css);
        $this->assertStringNotContainsString('.k-classico', $css, 'regras de outros acabamentos podadas');

        // Fontes do par e só as variantes de foto usadas.
        $this->assertNotEmpty(glob($p . '/fontes/*.woff2'));
        preg_match_all('#/img/(m_[0-9a-f]{8}-\d+\.webp)#', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $img) {
            $this->assertFileExists($p . '/img/' . $img);
        }
        $copiadas = array_map('basename', glob($p . '/img/*') ?: []);
        sort($copiadas);
        $usadas = [];
        foreach (glob($p . '/{*.html,*/index.html}', GLOB_BRACE) ?: [] as $pagina) {
            preg_match_all('#/img/(m_[0-9a-f]{8}-(?:\d+|orig)\.(?:webp|svg))#', (string) file_get_contents($pagina), $u);
            $usadas = array_merge($usadas, $u[1]);
        }
        $usadas = array_values(array_unique($usadas));
        sort($usadas);
        $this->assertSame($usadas, $copiadas, 'nenhuma variante sobrando ou faltando');

        // Permissões.
        $this->assertSame('0644', substr(sprintf('%o', fileperms($p . '/index.html')), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms(realpath($p))), -4));
    }

    public function testCspComHashDoScriptEmTodasAsPaginas(): void
    {
        $this->publicar();
        foreach (['index.html', 'privacidade/index.html', 'obrigado/index.html', '404.html'] as $arq) {
            $html = (string) file_get_contents($this->pasta() . '/' . $arq);
            $this->assertSame(1, preg_match('#<meta http-equiv="Content-Security-Policy" content="([^"]+)">#', $html, $csp), $arq);
            $this->assertSame(1, preg_match_all('#<script>(.*?)</script>#s', $html, $scripts), "{$arq}: um script embutido");
            $hash = ScriptSite::hashCsp($scripts[1][0]);
            $politica = html_entity_decode($csp[1], ENT_QUOTES | ENT_HTML5);
            $this->assertStringContainsString("script-src 'self' {$hash}", $politica, $arq);
            $this->assertStringContainsString('frame-src https://www.google.com', $politica);
            $this->assertStringContainsString("form-action 'self'", $politica);
            $this->assertStringNotContainsString('googletagmanager', $politica, 'sem rastreamento configurado');
            $this->assertDoesNotMatchRegularExpression('/<[^>]+\son[a-z]+=/i', $html, "{$arq}: sem manipuladores inline");
        }
        $obrigado = (string) file_get_contents($this->pasta() . '/obrigado/index.html');
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $obrigado);
        $this->assertStringContainsString('data-pos="obrigado"', $obrigado);
    }

    public function testJsonLdValidoSemAggregateRating(): void
    {
        $this->publicar();
        $html = (string) file_get_contents($this->pasta() . '/index.html');
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));
        $ld = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $ld['@context']);
        $negocio = $ld['@graph'][0];
        $this->assertSame('Dentist', $negocio['@type']);
        $this->assertSame('Clínica Sorriso Vivo', $negocio['name']);
        $this->assertSame('https://sorrisovivo.sites.teste/', $negocio['url']);
        $this->assertSame('+55 11 4521-3080', $negocio['telephone']);
        $this->assertSame(['@type' => 'PostalAddress', 'streetAddress' => 'Rua Barão de Jundiaí, 1100 - Sala 4', 'addressLocality' => 'Jundiaí',
            'addressRegion' => 'SP', 'postalCode' => '13201-005', 'addressCountry' => 'BR'], $negocio['address']);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday'], $negocio['openingHoursSpecification'][0]['dayOfWeek']);
        $this->assertContains('https://www.instagram.com/sorrisovivo', $negocio['sameAs']);
        $this->assertStringContainsString('/img/' . $this->midias['foto'] . '-1600.webp', $negocio['image']);
        $this->assertStringNotContainsString('AggregateRating', $m[1]);
        $this->assertStringNotContainsString('aggregateRating', $m[1]);
        $tipos = array_column($ld['@graph'], '@type');
        $this->assertContains('WebSite', $tipos);
        if (str_contains($html, 'rk-sec--faq')) {
            $this->assertContains('FAQPage', $tipos);
        }
        // Open Graph com a foto do destaque.
        $this->assertStringContainsString('<meta property="og:image" content="https://sorrisovivo.sites.teste/img/' . $this->midias['foto'] . '-1600.webp">', $html);
        $sitemap = simplexml_load_string((string) file_get_contents($this->pasta() . '/sitemap.xml'));
        $this->assertSame('https://sorrisovivo.sites.teste/', (string) $sitemap->url[0]->loc);
        $this->assertSame('2026-10-06', (string) $sitemap->url[0]->lastmod);
        $this->assertStringContainsString('Sitemap: https://sorrisovivo.sites.teste/sitemap.xml', (string) file_get_contents($this->pasta() . '/robots.txt'));
    }

    public function testPaginasExtrasComCabecalhoERodape(): void
    {
        $this->publicar();
        $priv = (string) file_get_contents($this->pasta() . '/privacidade/index.html');
        $this->assertStringContainsString('Política de privacidade', $priv);
        $this->assertStringContainsString('12 meses', $priv);
        $this->assertStringContainsString('Clínica Sorriso Vivo', $priv);
        $this->assertStringContainsString('contato@sorrisovivo.com.br', $priv);
        $this->assertStringContainsString('6 de outubro de 2026', $priv);
        $this->assertStringContainsString('não usa cookies de publicidade', $priv);
        $this->assertStringContainsString('<header', $priv);
        $this->assertStringContainsString('<footer', $priv);
        $this->assertStringContainsString('href="/#', $priv, 'âncoras do menu levam à página inicial');
        $this->assertStringNotContainsString('data-k=', $priv);
        $nao = (string) file_get_contents($this->pasta() . '/404.html');
        $this->assertStringContainsString('Página não encontrada', $nao);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $nao);
    }

    public function testRastreamentoConfiguradoListaNaPrivacidadeELiberaNaCsp(): void
    {
        $doc = $this->site['documento'];
        $doc['rastreamento'] = ['gtm' => 'GTM-ABCD123', 'ga4' => '', 'metaPixel' => '123456789012'];
        SiteExemplo::salvar($this->app, (int) $this->site['id'], $doc);
        $this->publicar();
        $html = (string) file_get_contents($this->pasta() . '/index.html');
        $this->assertStringContainsString('https://www.googletagmanager.com', $html);
        $this->assertStringContainsString('https://connect.facebook.net', $html);
        $this->assertStringContainsString('"gtm":"GTM-ABCD123"', $html);
        $priv = (string) file_get_contents($this->pasta() . '/privacidade/index.html');
        $this->assertStringContainsString('Google Tag Manager', $priv);
        $this->assertStringContainsString('Meta Pixel', $priv);
        $this->assertStringContainsString('data-consentimento', $priv);
    }

    public function testVersaoRegistradaComResolvidos(): void
    {
        $this->publicar();
        $v = $this->app->db()->um('SELECT * FROM versoes WHERE site_id = ?', [(int) $this->site['id']]);
        $this->assertSame(1, (int) $v['numero']);
        $this->assertSame((int) $this->usuario['id'], (int) $v['publicado_por']);
        $this->assertSame('2026-10-06 15:00:00', $v['publicado_em']);
        $this->assertSame($this->app->biblioteca()['versao'], $v['biblioteca_versao']);
        $this->assertSame(basename((string) readlink($this->pasta())), $v['release']);
        $doc = json_decode((string) $v['documento'], true);
        $this->assertSame('Clínica Sorriso Vivo', $doc['dados']['nome']);
        $resolvidos = json_decode((string) $v['resolvidos'], true);
        $this->assertArrayHasKey('hero.titulo', $resolvidos);
        $this->assertArrayNotHasKey('dep.1.t', $resolvidos, 'texto editado não é padrão');
        $site = Sites::porId($this->app, (int) $this->site['id']);
        $this->assertSame('publicado', $site['status']);
        $this->assertSame(1, $site['publicado_versao']);
        $evento = $this->app->db()->um("SELECT * FROM eventos WHERE tipo = 'site.release'");
        $this->assertNotNull($evento);
        $this->assertSame('publicar', json_decode((string) $evento['detalhe'], true)['acao']);
    }

    public function testTrocaAtomicaReverterELimiteDeReleases(): void
    {
        $this->publicar();
        $r1 = basename((string) readlink($this->pasta()));
        $doc = $this->site['documento'];
        $doc['textos']['hero.titulo'] = 'Título da segunda versão';
        SiteExemplo::salvar($this->app, (int) $this->site['id'], $doc);
        $this->assertSame(2, $this->publicar()['versao']);
        $r2 = basename((string) readlink($this->pasta()));
        $this->assertNotSame($r1, $r2);
        $this->assertStringStartsWith('2-', $r2);
        $this->assertStringContainsString('Título da segunda versão', (string) file_get_contents($this->pasta() . '/index.html'));
        $this->assertDirectoryExists($this->dir . '/sites/.releases/sorrisovivo/' . $r1, 'release antiga guardada');

        $gerador = new Gerador($this->app);
        $volta = $gerador->reverter(Sites::porId($this->app, (int) $this->site['id']), (int) $this->usuario['id']);
        $this->assertSame(['url' => 'https://sorrisovivo.sites.teste', 'versao' => 1], $volta);
        $this->assertSame($r1, basename((string) readlink($this->pasta())));
        $this->assertStringNotContainsString('Título da segunda versão', (string) file_get_contents($this->pasta() . '/index.html'));
        $this->assertSame(1, Sites::porId($this->app, (int) $this->site['id'])['publicado_versao']);

        try {
            $gerador->reverter(Sites::porId($this->app, (int) $this->site['id']), (int) $this->usuario['id']);
            $this->fail('não há anterior à versão 1');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('anterior', $e->getMessage());
        }

        // Mais publicações: só ficam releases_mantidas (3) + a que estiver no ar.
        for ($i = 0; $i < 3; $i++) {
            $this->publicar();
        }
        $releases = array_map('basename', glob($this->dir . '/sites/.releases/sorrisovivo/*', GLOB_ONLYDIR) ?: []);
        $this->assertCount(3, $releases);
        $this->assertSame('5-', substr(basename((string) readlink($this->pasta())), 0, 2));
        $this->assertNotContains($r1, $releases);
    }

    public function testErroDeValidacaoNaoPublica(): void
    {
        $doc = $this->site['documento'];
        $doc['dados']['whatsapp'] = '123';
        SiteExemplo::salvar($this->app, (int) $this->site['id'], $doc);
        try {
            $this->publicar();
            $this->fail('deveria lançar ErroValidacao');
        } catch (ErroValidacao $e) {
            $this->assertContains('whatsapp_invalido', array_column($e->erros, 'codigo'));
        }
        $this->assertFalse(file_exists($this->pasta()));
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM versoes'));
    }

    public function testFalhaNoBancoNaoMudaOQueEstaNoAr(): void
    {
        $this->publicar();
        $noAr = readlink($this->pasta());
        $pdo = $this->app->db()->pdo();
        // Gatilho que faz o UPDATE de sites falhar depois do INSERT da versão.
        $pdo->exec($this->app->db()->driver() === 'sqlite'
            ? "CREATE TRIGGER falha_teste BEFORE UPDATE ON sites BEGIN SELECT RAISE(ABORT, 'falha simulada'); END"
            : "CREATE TRIGGER falha_teste BEFORE UPDATE ON sites FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha simulada'");
        try {
            $this->publicar();
            $this->fail('deveria falhar');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('falha simulada', $e->getMessage());
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS falha_teste');
        }
        $this->assertSame($noAr, readlink($this->pasta()), 'o link continua na versão anterior');
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM versoes'), 'versão 2 desfeita');
        $this->assertCount(1, glob($this->dir . '/sites/.releases/sorrisovivo/*', GLOB_ONLYDIR) ?: [], 'release nova apagada');
    }

    public function testFalhaNaTrocaDesfazOBanco(): void
    {
        mkdir($this->dir . '/sites/.releases/sorrisovivo', 0755, true);
        file_put_contents($this->pasta(), 'arquivo no lugar do link');
        try {
            $this->publicar();
            $this->fail('deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sorrisovivo', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM versoes'));
        $this->assertSame('rascunho', Sites::porId($this->app, (int) $this->site['id'])['status']);
        $this->assertSame([], glob($this->dir . '/sites/.releases/sorrisovivo/*', GLOB_ONLYDIR) ?: []);
    }

    public function testMigraPublicacaoLegadaEmPasta(): void
    {
        $this->publicar();
        // Simula o formato antigo: uma pasta real em sites/{slug} com a versão 1.
        $release = (string) readlink($this->pasta());
        unlink($this->pasta());
        rename($this->dir . '/sites/' . $release, $this->pasta());
        $this->assertTrue(is_dir($this->pasta()) && !is_link($this->pasta()));

        $this->assertSame(2, $this->publicar()['versao']);
        $this->assertTrue(is_link($this->pasta()));
        $legado = (string) $this->app->db()->valor('SELECT `release` FROM versoes WHERE numero = 1');
        $this->assertMatchesRegularExpression('/^1-legado[0-9a-f]{6}$/', $legado);
        $this->assertDirectoryExists($this->dir . '/sites/.releases/sorrisovivo/' . $legado);
        $volta = (new Gerador($this->app))->reverter(Sites::porId($this->app, (int) $this->site['id']), (int) $this->usuario['id']);
        $this->assertSame(1, $volta['versao']);
        $this->assertSame($legado, basename((string) readlink($this->pasta())));
    }

    /** CSS da página: o <style> embutido ou o arquivo assets/site.{hash8}.css. */
    private function cssDe(string $html): string
    {
        if (preg_match('#<link rel="stylesheet" href="/(assets/site\.[0-9a-f]{8}\.css)">#', $html, $m)) {
            $css = (string) file_get_contents($this->pasta() . '/' . $m[1]);
            $this->assertSame(substr(hash('sha256', $css), 0, 8), substr($m[1], 12, 8), 'hash do nome = conteúdo');
            $this->assertGreaterThan(30 * 1024, strlen($css));
            return $css;
        }
        $this->assertSame(1, preg_match('#<style>(.*?)</style>#s', $html, $m));
        $this->assertLessThanOrEqual(30 * 1024, strlen($m[1]));
        return $m[1];
    }
}
