<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Gerador\Gerador;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Sites publicados servidos de verdade: php -S com sites/router-dev.php (que emula o
 * .htaccess e usa _roteador.php e _lead.php), requisições HTTP reais e, se o Playwright
 * estiver disponível, o Chromium abrindo o site e enviando o formulário.
 */
final class SitesNoServidorTest extends TestCase
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
        $extra = ['dominio_sites' => 'localhost:' . self::$porta, 'protocolo_sites' => 'http'];
        self::$app = SiteExemplo::app($extra, $dir);
        self::$dir = $dir;
        $config = AmbienteTeste::config($dir, $extra + ['dir_biblioteca' => SiteExemplo::biblioteca()] + AmbienteTeste::extraBanco());
        file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');

        // Site com formulário e mapa (modelo Direto) e outro com rastreamento configurado.
        $app = self::$app;
        $u = AmbienteTeste::usuario($app);
        $gerador = new Gerador($app);
        self::$site = SiteExemplo::site($app, SiteExemplo::documento($app, 'clinicas', 'direto'), 'sorrisovivo');
        $midias = SiteExemplo::midias($app, (int) self::$site['id'], $dir);
        $doc = self::$site['documento'];
        $doc['dados']['logo'] = $midias['logo'];
        self::$site = SiteExemplo::salvar($app, (int) self::$site['id'], $doc);
        $gerador->publicar(self::$site, (int) $u['id']);
        $rastreado = SiteExemplo::site($app, SiteExemplo::documento($app, 'clinicas', 'moderno', [
            'rastreamento' => ['gtm' => 'GTM-TESTE12', 'ga4' => '', 'metaPixel' => ''],
        ]), 'rastreado');
        $gerador->publicar($rastreado, (int) $u['id']);

        $raiz = dirname(__DIR__, 3);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$porta, '-t', $raiz . '/sites', $raiz . '/sites/router-dev.php'],
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

    /** @return array{status: int, cabecalhos: array<string, string>, corpo: string} */
    private function http(string $metodo, string $caminho, array $op = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$porta . $caminho);
        $cab = array_merge(['Host: ' . ($op['host'] ?? 'sorrisovivo.localhost:' . self::$porta)], $op['cabecalhos'] ?? []);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOPROXY => '*',
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => $cab,
        ]);
        if (isset($op['campos'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($op['campos']));
        }
        if ($metodo === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
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

    private function contarLeads(): int
    {
        return (int) self::$app->db()->valor('SELECT COUNT(*) FROM leads WHERE site_id = ?', [(int) self::$site['id']]);
    }

    public function testArquivosEstaticosComCabecalhos(): void
    {
        $r = $this->http('GET', '/');
        $this->assertSame(200, $r['status']);
        $this->assertSame('text/html; charset=utf-8', $r['cabecalhos']['content-type']);
        $this->assertSame('no-cache', $r['cabecalhos']['cache-control']);
        $this->assertSame('nosniff', $r['cabecalhos']['x-content-type-options']);
        $this->assertArrayNotHasKey('x-powered-by', $r['cabecalhos']);
        $this->assertStringContainsString('<title>Clínica Sorriso Vivo', $r['corpo']);

        $this->assertSame(304, $this->http('GET', '/', ['cabecalhos' => ['If-None-Match: ' . $r['cabecalhos']['etag']]])['status']);

        preg_match('#/(fontes/[a-z0-9-]+\.woff2)#', $r['corpo'], $m);
        $fonte = $this->http('GET', '/' . $m[1]);
        $this->assertSame(200, $fonte['status']);
        $this->assertSame('font/woff2', $fonte['cabecalhos']['content-type']);
        $this->assertSame('public, max-age=31536000, immutable', $fonte['cabecalhos']['cache-control']);

        preg_match('#/(img/m_[0-9a-f]{8}-\d+\.webp)#', $r['corpo'], $m);
        $img = $this->http('HEAD', '/' . $m[1]);
        $this->assertSame(200, $img['status']);
        $this->assertSame('image/webp', $img['cabecalhos']['content-type']);
        $this->assertStringContainsString('immutable', $img['cabecalhos']['cache-control']);

        $pasta = $this->http('GET', '/privacidade?x=1');
        $this->assertSame(301, $pasta['status']);
        $this->assertSame('/privacidade/?x=1', $pasta['cabecalhos']['location']);
        $this->assertSame(200, $this->http('GET', '/privacidade/')['status']);
        $this->assertSame('application/xml; charset=utf-8', $this->http('GET', '/sitemap.xml')['cabecalhos']['content-type']);
    }

    public function test404DoSiteETravessia(): void
    {
        $r = $this->http('GET', '/nao/existe');
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('Página não encontrada', $r['corpo']);
        foreach (['/../../../../etc/passwd', '/%2e%2e/%2e%2e/config.php', '/img/../../banco.sqlite', '/.releases/', '/.htaccess', '/_lead.php', '/router-dev.php'] as $ruim) {
            $s = $this->http('GET', $ruim)['status'];
            $this->assertContains($s, [403, 404], "{$ruim} → {$s}");
            $this->assertStringNotContainsString('root:', $this->http('GET', $ruim)['corpo']);
        }
        $this->assertSame(405, $this->http('DELETE', '/')['status']);
        $outro = $this->http('GET', '/', ['host' => 'naoexiste.localhost:' . self::$porta]);
        $this->assertSame(404, $outro['status']);
    }

    public function testLeadSemJavascriptRedireciona303(): void
    {
        $antes = $this->contarLeads();
        $r = $this->http('POST', '/_lead', ['campos' => ['nome' => 'Maria Sem JS', 'telefone' => '(11) 91234-5678', 'mensagem' => 'Olá', 'empresa_site' => '', '_t' => '']]);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/obrigado/', $r['cabecalhos']['location']);
        $this->assertSame($antes + 1, $this->contarLeads());
    }

    public function testLeadPorFetchPoteDeMelEErros(): void
    {
        $json = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'Origin: http://sorrisovivo.localhost:' . self::$porta];
        $antes = $this->contarLeads();
        $ok = $this->http('POST', '/_lead', ['cabecalhos' => $json, 'campos' => [
            'nome' => 'João Fetch', 'telefone' => '11912345678', '_t' => '5200', 'utm_source' => 'google', 'gclid' => 'abc', 'pagina' => 'http://x/?utm_source=google',
        ]]);
        $this->assertSame(200, $ok['status'], $ok['corpo']);
        $this->assertSame(['ok' => true], json_decode($ok['corpo'], true));
        $this->assertSame('application/json; charset=utf-8', $ok['cabecalhos']['content-type']);
        $this->assertSame('no-store', $ok['cabecalhos']['cache-control']);
        $lead = self::$app->db()->um("SELECT * FROM leads WHERE nome = 'João Fetch'");
        $this->assertSame(['utm_source' => 'google', 'gclid' => 'abc', 'pagina' => 'http://x/?utm_source=google'], json_decode((string) $lead['origem'], true));

        // Pote de mel e envio rápido demais: sucesso falso, nada gravado.
        $pote = $this->http('POST', '/_lead', ['cabecalhos' => $json, 'campos' => ['nome' => 'Robô', 'telefone' => '11912345678', 'empresa_site' => 'spam']]);
        $this->assertSame(['ok' => true], json_decode($pote['corpo'], true));
        $rapido = $this->http('POST', '/_lead', ['cabecalhos' => $json, 'campos' => ['nome' => 'Robô', 'telefone' => '11912345678', '_t' => '120']]);
        $this->assertSame(['ok' => true], json_decode($rapido['corpo'], true));
        $this->assertSame($antes + 1, $this->contarLeads());

        // Erro de validação: mensagem do servidor, pronta para mostrar.
        $ruim = $this->http('POST', '/_lead', ['cabecalhos' => $json, 'campos' => ['nome' => 'Ana', 'telefone' => '123']]);
        $this->assertSame(422, $ruim['status']);
        $this->assertFalse(json_decode($ruim['corpo'], true)['ok']);
        $this->assertStringContainsString('telefone', json_decode($ruim['corpo'], true)['erro']['mensagem']);

        // De outra origem: recusado.
        $fora = $this->http('POST', '/_lead', ['cabecalhos' => ['Accept: application/json', 'Origin: https://malicioso.example'], 'campos' => ['nome' => 'X', 'telefone' => '11912345678']]);
        $this->assertSame(403, $fora['status']);
        $this->assertSame(405, $this->http('GET', '/_lead')['status']);
        $this->assertSame(404, $this->http('POST', '/_lead', ['host' => 'naoexiste.localhost:' . self::$porta, 'cabecalhos' => ['Accept: application/json'], 'campos' => ['nome' => 'X', 'telefone' => '11912345678']])['status']);
        $this->assertSame($antes + 1, $this->contarLeads());
    }

    public function testDominioProprioPeloRoteador(): void
    {
        self::$app->db()->inserir('dominios', [
            'site_id' => (int) self::$site['id'], 'dominio' => 'clinicasorriso.com.br', 'status' => 'ativo', 'criado_em' => self::$app->agoraSql(),
        ]);
        @unlink(self::$dir . '/var/cache/dominios.json');
        $r = $this->http('GET', '/', ['host' => 'www.clinicasorriso.com.br']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Clínica Sorriso Vivo', $r['corpo']);
        $this->assertFileExists(self::$dir . '/var/cache/dominios.json', 'mapa de domínios em cache');
        $this->assertSame(404, $this->http('GET', '/nada', ['host' => 'clinicasorriso.com.br'])['status']);
        $this->assertSame(404, $this->http('GET', '/', ['host' => 'outro.com.br'])['status']);
    }

    private function playwright(string $url, string $modo): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '' || !is_dir(dirname(__DIR__, 3) . '/node_modules/@playwright/test')) {
            $this->markTestSkipped('Node/Playwright indisponível.');
        }
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/navegador.mjs') . ' ' . escapeshellarg($url) . ' ' . $modo . ' 2>&1';
        $saida = (string) shell_exec($cmd);
        $r = json_decode($saida, true);
        if (!is_array($r)) {
            if (str_contains($saida, "Executable doesn't exist") || str_contains($saida, 'browserType.launch')) {
                $this->markTestSkipped('Chromium do Playwright indisponível: ' . substr($saida, 0, 200));
            }
            $this->fail('Saída inesperada do navegador: ' . $saida);
        }
        return $r;
    }

    public function testNavegadorAbreSemErrosEEnviaOFormulario(): void
    {
        $antes = $this->contarLeads();
        $r = $this->playwright('http://sorrisovivo.localhost:' . self::$porta, 'formulario');
        $this->assertSame([], $r['erros'], 'sem erros no console (inclui violações de CSP)');
        $this->assertSame([], $r['csp']);
        $this->assertSame(0, $r['externasAntes'], 'nenhuma requisição a terceiros sem rastreamento');
        $origem = json_decode((string) $r['origem'], true);
        $this->assertSame('google', $origem['utm_source']);
        $this->assertSame('abc123', $origem['gclid']);
        $this->assertSame($r['origem'], $r['origemDepois']);
        $this->assertGreaterThanOrEqual(1, $r['formularios']);
        $this->assertSame('ok', $r['estado']);
        $this->assertTrue($r['okVisivel']);
        $this->assertGreaterThanOrEqual(3000, (int) $r['ocultos']['_t']);
        $this->assertSame($antes + 1, $this->contarLeads());
        $lead = self::$app->db()->um("SELECT * FROM leads WHERE nome = 'Carla Navegador'");
        $this->assertSame('(11) 98888-7777', $lead['telefone']);
        $this->assertSame('Quero agendar uma avaliação.', $lead['mensagem']);
        $leadOrigem = json_decode((string) $lead['origem'], true);
        $this->assertSame('teste', $leadOrigem['utm_campaign']);
        $this->assertStringContainsString('utm_source=google', $leadOrigem['pagina']);
        if ($r['temMapa']) {
            $this->assertSame(0, $r['iframesAntes'], 'mapa não carrega antes do clique');
            $this->assertStringStartsWith('https://www.google.com/maps?q=', $r['iframe']['src']);
            $this->assertNotSame('', $r['iframe']['title']);
            $this->assertSame('lazy', $r['iframe']['loading']);
        }
        $this->assertLessThanOrEqual(390, $r['larguraCelular'], 'sem rolagem horizontal no celular');
        $this->assertStringStartsWith('Mensagem enviada', $r['tituloObrigado']);
    }

    public function testNavegadorConsentimentoAntesDoRastreamento(): void
    {
        $r = $this->playwright('http://rastreado.localhost:' . self::$porta, 'consentimento');
        $this->assertSame([], $r['erros']);
        $this->assertTrue($r['faixaVisivel'], 'faixa aparece na primeira visita');
        $this->assertTrue($r['negadoPorPadrao']);
        $this->assertSame([], $r['externasAntesDoAceite'], 'nada de GTM antes do aceite');
        $this->assertFalse($r['faixaDepois']);
        $this->assertSame('aceito', $r['escolha']);
        $this->assertNotEmpty(array_filter($r['externasDepoisDoAceite'], static fn (string $u): bool => str_contains($u, 'googletagmanager.com/gtm.js?id=GTM-TESTE12')));
        $this->assertContains(['event' => 'rankly_whatsapp_click', 'posicao' => 'hero'], $r['eventos']);
        $this->assertFalse($r['faixaNaVolta']);
        $this->assertNotEmpty(array_filter($r['externasNaVolta'], static fn (string $u): bool => str_contains($u, 'gtm.js')), 'escolha lembrada: GTM carrega direto');
    }
}
