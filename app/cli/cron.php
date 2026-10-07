<?php

/**
 * Processa a fila de tarefas [M14]. Agende no cron a cada minuto (ou a cada 5):
 *   * * * * * php /caminho/app/cli/cron.php >> /caminho/var/logs/cron.log 2>&1
 * Opções: --limite=N (padrão 50), --republicar-todos (enfileira republicação dos sites publicados).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use Rankly\Lib\Executores;

$opcoes = getopt('', ['limite:', 'republicar-todos']);
$limite = isset($opcoes['limite']) ? max(1, (int) $opcoes['limite']) : 50;

// Uma execução por vez (o cron pode disparar antes de a anterior terminar).
$trava = fopen($app->dirVar('locks') . '/cron.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    echo "Outra execução do cron ainda está rodando.\n";
    exit(0);
}

$codigo = 0;
try {
    Executores::registrarTodos($app);
    Executores::agendarLimpeza($app);
    if (isset($opcoes['republicar-todos'])) {
        $ids = $app->db()->todos("SELECT id FROM sites WHERE status = 'publicado' ORDER BY id");
        foreach ($ids as $l) {
            $app->tarefas()->enfileirar('republicar', ['site_id' => (int) $l['id']]);
        }
        echo count($ids) . " site(s) enfileirado(s) para republicar.\n";
    }
    $feitas = $app->tarefas()->processar($limite);
    if ($feitas > 0) {
        echo gmdate('Y-m-d H:i:s') . " UTC · {$feitas} tarefa(s) processada(s).\n";
    }
} catch (\Throwable $e) {
    $app->log()->excecao($e, ['etapa' => 'cron']);
    fwrite(STDERR, 'Erro no cron: ' . $e->getMessage() . "\n");
    $codigo = 1;
} finally {
    flock($trava, LOCK_UN);
    fclose($trava);
}
exit($codigo);
