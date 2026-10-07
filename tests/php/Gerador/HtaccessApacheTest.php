<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Gerador;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * sites/.htaccess no Apache de verdade (mod_rewrite + mod_php), com um site publicado.
 * Só roda com RANKLY_TESTE_APACHE=1 e o apache2 instalado (Debian/Ubuntu: apache2 e
 * libapache2-mod-php): RANKLY_TESTE_APACHE=1 vendor/bin/phpunit -c tests/php/phpunit.xml --filter HtaccessApacheTest
 */
final class HtaccessApacheTest extends TestCase
{
    private const DOMINIO = 'sitesteste.com.br';

    private static ?string $dir = null;
    private static int $porta = 0;
    private static string $conf = '';

    public static function setUpBeforeClass(): void
    {
        if (getenv('RANKLY_TESTE_APACHE') !== '1') {
            return;
        }
        $apache = trim((string) shell_exec('command -v apache2 2>/dev/null'));
        $modulos = '/usr/lib/apache2/modules';
        if ($apache === '' || !is_file($modulos . '/libphp' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.so')) {
            return;
        }
        $dir = null;
        $extra = ['dominio_sites' => self::DOMINIO, 'protocolo_sites' => 'http'];
        $app = SiteExemplo::app($extra, $dir);
        self::$dir = $dir;
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(AmbienteTeste::config($dir, $extra + ['dir_biblioteca' => SiteExemplo::biblioteca()]), true) . ';');
        $site = SiteExemplo::site($app, SiteExemplo::documento($app, 'clinicas', 'direto'), 'sorrisovivo');
        (new Gerador($app))->publicar($site, (int) $site['dono_id']);
        $app->db()->inserir('dominios', ['site_id' => (int) $site['id'], 'dominio' => 'clinicasorriso.com.br', 'status' => 'ativo', 'criado_em' => $app->agoraSql()]);

        $raiz = dirname(__DIR__, 3);
        $htaccess = str_replace('SEU-DOMINIO\.com\.br', str_replace('.', '\.', self::DOMINIO), (string) file_get_contents($raiz . '/sites/.htaccess'));
        file_put_contents($dir . '/sites/.htaccess', $htaccess);
        foreach (['_lead.php', '_roteador.php', 'router-dev.php'] as $arq) {
            copy($raiz . '/sites/' . $arq, $dir . '/sites/' . $arq);
        }
        $usuario = function_exists('posix_geteuid') && posix_geteuid() === 0 ? 'www-data' : (string) get_current_user();
        if ($usuario === 'www-data') {
            exec('chmod -R a+rwX ' . escapeshellarg($dir . '/var') . ' && chmod a+rwx ' . escapeshellarg($dir) . ' && chmod a+rw ' . escapeshellarg($dir . '/banco.sqlite'));
        }
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$porta = (int) parse_url('tcp://' . stream_socket_get_name($sock, false), PHP_URL_PORT);
        fclose($sock);
        $php = 'libphp' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.so';
        self::$conf = $dir . '/apache.conf';
        $carregar = '';
        foreach (['mpm_prefork' => 'mod_mpm_prefork.so', 'authz_core' => 'mod_authz_core.so', 'dir' => 'mod_dir.so', 'mime' => 'mod_mime.so',
            'rewrite' => 'mod_rewrite.so', 'headers' => 'mod_headers.so', 'env' => 'mod_env.so', 'filter' => 'mod_filter.so',
            'deflate' => 'mod_deflate.so', 'php' => $php] as $nome => $so) {
            $carregar .= "LoadModule {$nome}_module {$modulos}/{$so}\n";
        }
        file_put_contents(self::$conf, "ServerRoot \"/etc/apache2\"\nServerName localhost\nListen 127.0.0.1:" . self::$porta . "\n"
            . "PidFile {$dir}/apache.pid\nErrorLog {$dir}/apache-erro.log\nUser {$usuario}\nGroup {$usuario}\n{$carregar}"
            . "TypesConfig /etc/mime.types\nDocumentRoot \"{$dir}/sites\"\n"
            . "<Directory />\n  AllowOverride None\n  Require all denied\n</Directory>\n"
            . "<Directory \"{$dir}/sites\">\n  AllowOverride All\n  Require all granted\n</Directory>\n"
            . "SetEnv RANKLY_RAIZ {$raiz}\nSetEnv RANKLY_CONFIG {$dir}/config.php\n"
            . "<FilesMatch \"\\.php$\">\n  SetHandler application/x-httpd-php\n</FilesMatch>\n");
        exec(escapeshellarg($apache) . ' -f ' . escapeshellarg(self::$conf) . ' -k start 2>&1', $saida, $codigo);
        for ($i = 0; $i < 50 && $codigo === 0; $i++) {
            $c = @fsockopen('127.0.0.1', self::$porta, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(100000);
        }
        self::$porta = 0;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$conf !== '' && is_file(self::$conf)) {
            exec('apache2 -f ' . escapeshellarg(self::$conf) . ' -k stop 2>&1');
            usleep(500000);
        }
        AmbienteTeste::remover(self::$dir);
    }

    protected function setUp(): void
    {
        if (self::$porta === 0) {
            $this->markTestSkipped('Defina RANKLY_TESTE_APACHE=1 (com apache2 + mod_php instalados) para testar o .htaccess no Apache.');
        }
    }

    private function http(string $host, string $caminho, array $campos = [], array $cab = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$porta . $caminho);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOPROXY => '*', CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => array_merge(['Host: ' . $host], $cab), CURLOPT_TIMEOUT => 20]);
        if ($campos !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
        }
        $r = (string) curl_exec($ch);
        $t = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $cabecalhos = [];
        foreach (explode("\r\n", substr($r, 0, $t)) as $l) {
            if (str_contains($l, ':')) {
                [$n, $v] = explode(':', $l, 2);
                $cabecalhos[strtolower(trim($n))] = trim($v);
            }
        }
        return ['status' => $status, 'cab' => $cabecalhos, 'corpo' => substr($r, $t)];
    }

    public function testSubdominioServeAReleaseNoAr(): void
    {
        $h = 'sorrisovivo.' . self::DOMINIO;
        $r = $this->http($h, '/');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<title>Clínica Sorriso Vivo', $r['corpo']);
        $this->assertSame('no-cache', $r['cab']['cache-control']);
        $this->assertSame('nosniff', $r['cab']['x-content-type-options']);
        // HTML comprimido quando o navegador aceita (meta de peso transferido, PDF §9.5).
        $gzip = $this->http($h, '/', [], ['Accept-Encoding: gzip']);
        $this->assertSame('gzip', $gzip['cab']['content-encoding'] ?? null);
        $this->assertStringContainsString('<title>Clínica Sorriso Vivo', (string) gzdecode($gzip['corpo']));
        $pasta = $this->http($h, '/privacidade');
        $this->assertSame(301, $pasta['status']);
        $this->assertStringEndsWith('/privacidade/', $pasta['cab']['location']);
        $this->assertStringNotContainsString('/sorrisovivo/', $pasta['cab']['location'], 'sem expor a pasta interna');
        $this->assertSame(200, $this->http($h, '/privacidade/')['status']);
        preg_match('#/(fontes/[a-z0-9-]+\.woff2)#', $r['corpo'], $m);
        $fonte = $this->http($h, '/' . $m[1]);
        $this->assertSame('public, max-age=31536000, immutable', $fonte['cab']['cache-control']);
        $nada = $this->http($h, '/nao-existe');
        $this->assertSame(404, $nada['status']);
        $this->assertStringContainsString('Página não encontrada', $nada['corpo'], '404 do próprio site');
        foreach (['/.releases/', '/.htaccess', '/_lead.php', '/_roteador.php', '/router-dev.php'] as $proibido) {
            $this->assertContains($this->http($h, $proibido)['status'], [403, 404], $proibido);
        }
    }

    public function testLeadEDominioProprio(): void
    {
        $h = 'sorrisovivo.' . self::DOMINIO;
        $r = $this->http($h, '/_lead', ['nome' => 'Maria', 'telefone' => '(11) 91234-5678', '_t' => '']);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/obrigado/', $r['cab']['location']);
        $j = $this->http($h, '/_lead', ['nome' => 'João', 'telefone' => '11912345678', '_t' => '4000'], ['Accept: application/json']);
        $this->assertSame(['ok' => true], json_decode($j['corpo'], true));

        $d = $this->http('www.clinicasorriso.com.br', '/');
        $this->assertSame(200, $d['status']);
        $this->assertStringContainsString('Clínica Sorriso Vivo', $d['corpo']);
        $this->assertSame(404, $this->http('clinicasorriso.com.br', '/xyz')['status']);
        $this->assertSame(404, $this->http('desconhecido.com', '/')['status']);
    }
}
