<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Limites de tentativa sob requisições simultâneas (servidor php -S com vários workers).
 * Regressão: o limite era conferido antes do password_verify (lento) e só contado depois;
 * disparando dezenas de tentativas ao mesmo tempo, todas passavam pela conferência antes de
 * qualquer uma ser contada — muito mais que 5 senhas testadas por janela.
 */
final class ConcorrenciaTest extends TestCase
{
    private static ?string $dir = null;
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static Aplicacao $app;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('curl_multi_init')) {
            self::markTestSkipped('Extensão curl indisponível.');
        }
        $dir = null;
        self::$app = AmbienteTeste::criar([], $dir);
        self::$dir = $dir;
        AmbienteTeste::usuario(self::$app, 'admin', 'admin@rankly.teste');
        $site = AmbienteTeste::site(self::$app, 'sorrisovivo');
        self::$app->db()->executar("UPDATE sites SET status = 'publicado' WHERE id = ?", [(int) $site['id']]);
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(AmbienteTeste::config($dir, AmbienteTeste::extraBanco()), true) . ';');

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) parse_url('tcp://' . stream_socket_get_name($sock, false), PHP_URL_PORT);
        fclose($sock);
        $raiz = dirname(__DIR__, 3);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $raiz . '/public_html', $raiz . '/public_html/router-dev.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/servidor.log', 'a'], 2 => ['file', $dir . '/servidor.log', 'a']],
            $pipes,
            $raiz,
            ['RANKLY_CONFIG' => $dir . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '12'] + getenv(),
        );
        self::$base = "http://127.0.0.1:{$porta}";
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $porta, $e, $s, 0.1);
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

    /**
     * Dispara $n POSTs JSON ao mesmo tempo e devolve a contagem por status.
     *
     * @return array<int, int>
     */
    private function simultaneas(int $n, string $caminho, array $json): array
    {
        $multi = curl_multi_init();
        $hs = [];
        for ($i = 0; $i < $n; $i++) {
            $h = curl_init(self::$base . $caminho);
            curl_setopt_array($h, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($json),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
            ]);
            curl_multi_add_handle($multi, $h);
            $hs[] = $h;
        }
        do {
            $st = curl_multi_exec($multi, $ativos);
            if ($ativos) {
                curl_multi_select($multi, 1.0);
            }
        } while ($ativos && $st === CURLM_OK);
        $contagem = [];
        foreach ($hs as $h) {
            $codigo = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $contagem[$codigo] = ($contagem[$codigo] ?? 0) + 1;
            curl_multi_remove_handle($multi, $h);
            curl_close($h);
        }
        curl_multi_close($multi);
        ksort($contagem);
        return $contagem;
    }

    public function testLoginSimultaneoNaoPassaDoLimiteDeCincoSenhas(): void
    {
        $r = $this->simultaneas(24, '/api/auth/login', ['email' => 'admin@rankly.teste', 'senha' => 'senha-errada-000']);
        $this->assertSame(24, array_sum($r), json_encode($r));
        $this->assertLessThanOrEqual(5, $r[401] ?? 0, 'Senhas testadas além do limite: ' . json_encode($r));
        $this->assertSame(24 - ($r[401] ?? 0), $r[429] ?? 0, json_encode($r));
    }

    public function testLeadsSimultaneosNaoPassamDoLimitePorIp(): void
    {
        $lead = ['nome' => 'Maria', 'telefone' => '11988887777', '_t' => '5000', 'empresa_site' => ''];
        $r = $this->simultaneas(16, '/api/lead/sorrisovivo', $lead);
        $this->assertSame(16, array_sum($r), json_encode($r));
        $this->assertLessThanOrEqual(5, $r[200] ?? 0, 'Leads aceitos além do limite: ' . json_encode($r));
        $this->assertLessThanOrEqual(5, (int) self::$app->db()->valor('SELECT COUNT(*) FROM leads'));
    }
}
