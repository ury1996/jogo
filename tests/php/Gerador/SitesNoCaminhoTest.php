<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Gerador\Gerador;
use Rankly\Gerador\SitesNoCaminho;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Modo demonstração (sites_no_caminho): sites publicados em {url_editor}/s/{slug}/, servidos
 * pelo mesmo php -S do editor (public_html/router-dev.php → s.php), com os caminhos absolutos
 * da release prefixados e o formulário de contato funcionando.
 */
final class SitesNoCaminhoTest extends TestCase
{
    private static ?string $dir = null;
    /** @var resource|null */
    private static $proc = null;
    private static int $porta = 0;
    private static Aplicacao $app;
    private static array $site;

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$porta = (int) parse_url('tcp://' . stream_socket_get_name($sock, false), PHP_URL_PORT);
        fclose($sock);
        $dir = null;
        $extra = ['sites_no_caminho' => true, 'url_editor' => 'http://127.0.0.1:' . self::$porta];
        self::$app = SiteExemplo::app($extra, $dir);
        self::$dir = $dir;
        $config = AmbienteTeste::config($dir, $extra + ['dir_biblioteca' => SiteExemplo::biblioteca()] + AmbienteTeste::extraBanco());
        file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');

        $app = self::$app;
        $u = AmbienteTeste::usuario($app);
        self::$site = SiteExemplo::site($app, SiteExemplo::documento($app, 'clinicas', 'direto'), 'sorrisovivo');
        $midias = SiteExemplo::midias($app, (int) self::$site['id'], $dir);
        $doc = self::$site['documento'];
        $doc['dados']['logo'] = $midias['logo'];
        self::$site = SiteExemplo::salvar($app, (int) self::$site['id'], $doc);
        (new Gerador($app))->publicar(self::$site, (int) $u['id']);

        $raiz = dirname(__DIR__, 3);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$porta, '-t', $raiz . '/public_html', $raiz . '/public_html/router-dev.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/servidor.log', 'a'], 2 => ['file', $dir . '/servidor.log', 'a']],
            $pipes,
            $raiz,
            ['RANKLY_CONFIG' => $dir . '/config.php'] + getenv(),
        );
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', self::$porta, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50000);
        }
        self::fail('O servidor php -S não subiu: ' . @file_get_contents($dir . '/servidor.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        AmbienteTeste::remover(self::$dir);
    }

    private function http(string $metodo, string $caminho, array $op = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$porta . $caminho);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOPROXY => '*',
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => $op['cabecalhos'] ?? [],
        ]);
        if (isset($op['campos'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($op['campos']));
        }
        $resposta = (string) curl_exec($ch);
        $tamanho = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $cabecalhos = [];
        foreach (explode("\r\n", substr($resposta, 0, $tamanho)) as $linha) {
            if (str_contains($linha, ':')) {
                [$n, $v] = explode(':', $linha, 2);
                $cabecalhos[strtolower(trim($n))] = trim($v);
            }
        }
        return ['status' => $status, 'cabecalhos' => $cabecalhos, 'corpo' => substr($resposta, $tamanho)];
    }

    public function testEnderecoDoSiteNoCaminho(): void
    {
        $this->assertSame('http://127.0.0.1:' . self::$porta . '/s/sorrisovivo', self::$app->urlSite('sorrisovivo'));
        $this->assertSame(['sorrisovivo', '/img/a.webp'], SitesNoCaminho::separar('/s/sorrisovivo/img/a.webp'));
        $this->assertSame(['sorrisovivo', ''], SitesNoCaminho::separar('/s/sorrisovivo'));
        $this->assertNull(SitesNoCaminho::separar('/s/'));
        $this->assertNull(SitesNoCaminho::separar('/s/../config'));
        $this->assertNull(SitesNoCaminho::separar('/editor/'));
    }

    public function testReescreveCaminhosAbsolutosSemTocarScripts(): void
    {
        $html = '<link rel="stylesheet" href="/assets/s.css"><link rel="canonical" href="https://x.com/">'
            . '<a href="//cdn.x/y">c</a><img src="/img/a-480.webp" srcset="/img/a-480.webp 480w, /img/a-960.webp 960w">'
            . '<form action="/_lead"></form><div style="background:url(/img/f.webp)"></div>'
            . '<style>@font-face{src:url("/fontes/a.woff2")}</style>'
            . '<script>fetch(f.getAttribute("action")||"/_lead");var a="href=\"/x\"";</script>';
        $r = SitesNoCaminho::html($html, '/s/abc');
        $this->assertStringContainsString('href="/s/abc/assets/s.css"', $r);
        $this->assertStringContainsString('href="https://x.com/"', $r);
        $this->assertStringContainsString('href="//cdn.x/y"', $r);
        $this->assertStringContainsString('src="/s/abc/img/a-480.webp"', $r);
        $this->assertStringContainsString('srcset="/s/abc/img/a-480.webp 480w, /s/abc/img/a-960.webp 960w"', $r);
        $this->assertStringContainsString('action="/s/abc/_lead"', $r);
        $this->assertStringContainsString('url(/s/abc/img/f.webp)', $r);
        $this->assertStringContainsString('url("/s/abc/fontes/a.woff2")', $r);
        $this->assertStringContainsString('<script>fetch(f.getAttribute("action")||"/_lead");var a="href=\"/x\"";</script>', $r, 'script intacto (hash da CSP)');
        $this->assertSame('a{background:url(/s/abc/img/x.webp)} b{background:url(\'/s/abc/y.png\')} c{background:url(//cdn/z.png)}',
            SitesNoCaminho::css('a{background:url(/img/x.webp)} b{background:url(\'/y.png\')} c{background:url(//cdn/z.png)}', '/s/abc'));
    }

    public function testServeOSitePublicadoNoCaminho(): void
    {
        $sem = $this->http('GET', '/s/sorrisovivo');
        $this->assertSame(301, $sem['status']);
        $this->assertSame('/s/sorrisovivo/', $sem['cabecalhos']['location']);

        $pagina = $this->http('GET', '/s/sorrisovivo/');
        $this->assertSame(200, $pagina['status']);
        $this->assertStringStartsWith('text/html', $pagina['cabecalhos']['content-type']);
        $this->assertSame((string) strlen($pagina['corpo']), $pagina['cabecalhos']['content-length']);
        $this->assertStringContainsString('action="/s/sorrisovivo/_lead"', $pagina['corpo']);
        $this->assertMatchesRegularExpression('#href="/s/sorrisovivo/assets/site\.[0-9a-f]+\.css"#', $pagina['corpo']);
        $this->assertDoesNotMatchRegularExpression('#(?:href|src|action)="/(?!/|s/sorrisovivo/)#', $pagina['corpo']);
        $this->assertStringContainsString('http://127.0.0.1:' . self::$porta . '/s/sorrisovivo/', $pagina['corpo'], 'canonical no endereço do caminho');

        preg_match('#href="(/s/sorrisovivo/assets/[^"]+)"#', $pagina['corpo'], $m);
        $css = $this->http('GET', $m[1]);
        $this->assertSame(200, $css['status']);
        $this->assertStringContainsString('url(/s/sorrisovivo/fontes/', $css['corpo']);
        $this->assertStringNotContainsString('url(/fontes/', $css['corpo']);

        $pasta = $this->http('GET', '/s/sorrisovivo/privacidade');
        $this->assertSame(301, $pasta['status']);
        $this->assertSame('/s/sorrisovivo/privacidade/', $pasta['cabecalhos']['location']);
        $this->assertSame(404, $this->http('GET', '/s/sorrisovivo/nao-existe')['status']);
        $this->assertSame(404, $this->http('GET', '/s/outro/')['status']);
        $this->assertSame(403, $this->http('GET', '/s/sorrisovivo/.htaccess')['status']);
    }

    public function testFormularioDeContatoNoCaminho(): void
    {
        $origem = 'http://127.0.0.1:' . self::$porta;
        $json = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'Origin: ' . $origem];
        $ok = $this->http('POST', '/s/sorrisovivo/_lead', ['cabecalhos' => $json, 'campos' => ['nome' => 'Maria Caminho', 'telefone' => '11912345678', '_t' => '5200']]);
        $this->assertSame(200, $ok['status'], $ok['corpo']);
        $this->assertSame(['ok' => true], json_decode($ok['corpo'], true));
        $this->assertNotNull(self::$app->db()->um("SELECT id FROM leads WHERE nome = 'Maria Caminho'"));

        $fora = $this->http('POST', '/s/sorrisovivo/_lead', ['cabecalhos' => ['Accept: application/json', 'Origin: https://outro.exemplo'], 'campos' => ['nome' => 'X', 'telefone' => '11912345678']]);
        $this->assertSame(403, $fora['status']);

        $semJs = $this->http('POST', '/s/sorrisovivo/_lead', ['campos' => ['nome' => 'Sem JS', 'telefone' => '(11) 91234-5678', '_t' => '']]);
        $this->assertSame(303, $semJs['status']);
        $this->assertSame('/s/sorrisovivo/obrigado/', $semJs['cabecalhos']['location']);
    }
}
