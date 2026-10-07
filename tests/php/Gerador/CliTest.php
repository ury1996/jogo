<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Gerador;
use Rankly\Lib\Executores;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * app/cli/republicar-todos.php (fila ou --agora) e o executor "republicar" da fila.
 */
final class CliTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function cli(array $args): array
    {
        $raiz = dirname(__DIR__, 3);
        $cmd = array_merge([PHP_BINARY, $raiz . '/app/cli/republicar-todos.php'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, ['RANKLY_CONFIG' => $this->dir . '/config.php'] + getenv());
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $saida];
    }

    public function testRepublicarTodosNaFilaEAgora(): void
    {
        $app = SiteExemplo::app([], $this->dir);
        file_put_contents($this->dir . '/config.php', '<?php return ' . var_export(
            AmbienteTeste::config($this->dir, ['dir_biblioteca' => SiteExemplo::biblioteca()] + AmbienteTeste::extraBanco()),
            true,
        ) . ';');
        $site = SiteExemplo::site($app);
        $rascunho = SiteExemplo::site($app, null, 'rascunho');
        $gerador = new Gerador($app);
        $gerador->publicar($site, (int) $site['dono_id']);

        [$codigo, $saida] = $this->cli([]);
        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('1 site(s) enfileirado(s)', $saida);
        $tarefa = $app->db()->um("SELECT * FROM tarefas WHERE tipo = 'republicar'");
        $this->assertSame(['site_id' => (int) $site['id']], json_decode((string) $tarefa['payload'], true));

        // O executor da fila (cron) republica com o gerador real.
        Executores::registrarTodos($app);
        $this->assertSame(1, $app->tarefas()->processar());
        $this->assertSame('feita', $app->db()->valor('SELECT status FROM tarefas WHERE id = ?', [(int) $tarefa['id']]));
        $this->assertSame(2, (int) $app->db()->valor('SELECT MAX(numero) FROM versoes WHERE site_id = ?', [(int) $site['id']]));

        [$codigo, $saida] = $this->cli(['--agora']);
        $this->assertSame(0, $codigo, $saida);
        $this->assertMatchesRegularExpression('/OK\s+sorrisovivo\s+versão 3/', $saida);
        $this->assertStringContainsString('1 republicado(s), 0 com problema', $saida);
        $this->assertStringNotContainsString('rascunho', $saida, 'só sites publicados');
        $this->assertSame(0, (int) $app->db()->valor('SELECT COUNT(*) FROM versoes WHERE site_id = ?', [(int) $rascunho['id']]));

        // Site com pendência: relatório e código de saída 1; a versão no ar continua.
        $doc = $site['documento'];
        $doc['dados']['whatsapp'] = '';
        SiteExemplo::salvar($app, (int) $site['id'], $doc);
        [$codigo, $saida] = $this->cli(['--agora', '--site=sorrisovivo']);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('PENDENTE', $saida);
        $this->assertStringContainsString('Falta o WhatsApp', $saida);
        $this->assertStringStartsWith('3-', basename((string) readlink($this->dir . '/sites/sorrisovivo')));
    }
}
