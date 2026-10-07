<?php

/**
 * Republica todos os sites publicados (ex.: depois de uma correção na biblioteca).
 *
 * Uso:
 *   php app/cli/republicar-todos.php            enfileira a tarefa "republicar" de cada site (o cron executa)
 *   php app/cli/republicar-todos.php --agora    republica agora, em série, com relatório
 *   php app/cli/republicar-todos.php --agora --site=sorrisovivo   só um site (slug ou id)
 *
 * Sites com pendências (ex.: WhatsApp inválido) não são republicados: a versão no ar continua.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use Rankly\Gerador\ErroValidacao;
use Rankly\Lib\Sites;

$opcoes = getopt('', ['agora', 'site:', 'ajuda']);
if (isset($opcoes['ajuda'])) {
    echo "Uso: php app/cli/republicar-todos.php [--agora] [--site=slug|id]\n";
    exit(0);
}

try {
    $sql = "SELECT id, slug, dono_id FROM sites WHERE status = 'publicado'";
    $parametros = [];
    if (isset($opcoes['site']) && is_string($opcoes['site']) && $opcoes['site'] !== '') {
        $sql .= ctype_digit($opcoes['site']) ? ' AND id = ?' : ' AND slug = ?';
        $parametros[] = ctype_digit($opcoes['site']) ? (int) $opcoes['site'] : $opcoes['site'];
    }
    $sites = $app->db()->todos($sql . ' ORDER BY id', $parametros);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro ao consultar os sites: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($sites === []) {
    echo "Nenhum site publicado encontrado.\n";
    exit(0);
}

if (!isset($opcoes['agora'])) {
    foreach ($sites as $s) {
        $app->tarefas()->enfileirar('republicar', ['site_id' => (int) $s['id']]);
    }
    echo count($sites) . " site(s) enfileirado(s) para republicar. O cron (app/cli/cron.php) executa a fila.\n";
    exit(0);
}

$ok = 0;
$falhas = 0;
$inicio = microtime(true);
foreach ($sites as $linha) {
    $rotulo = str_pad((string) $linha['slug'], 30);
    try {
        $site = Sites::porId($app, (int) $linha['id']);
        if ($site === null) {
            continue;
        }
        $r = $app->gerador()->publicar($site, (int) ($site['dono_id'] ?? 0));
        echo "OK     {$rotulo} versão {$r['versao']}  {$r['url']}\n";
        $ok++;
    } catch (ErroValidacao $e) {
        $falhas++;
        echo "PENDENTE {$rotulo} " . count($e->erros) . " pendência(s):\n";
        foreach ($e->erros as $erro) {
            echo '         - ' . ($erro['mensagem'] ?? $erro['codigo'] ?? '?') . "\n";
        }
    } catch (\Throwable $e) {
        $falhas++;
        $app->log()->excecao($e, ['etapa' => 'republicar-todos', 'site' => (int) $linha['id']]);
        echo "ERRO   {$rotulo} " . $e->getMessage() . "\n";
    }
}
printf("\n%d republicado(s), %d com problema, em %.1f s.\n", $ok, $falhas, microtime(true) - $inicio);
exit($falhas > 0 ? 1 : 0);
