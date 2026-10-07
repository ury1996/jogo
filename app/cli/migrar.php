<?php

/**
 * Aplica as migrações pendentes do banco configurado.
 * Uso: php app/cli/migrar.php [--listar]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use Rankly\Lib\Migrador;

try {
    $db = $app->db();
    $migrador = new Migrador($db, Migrador::dirPadrao($db));
    if (in_array('--listar', $argv, true)) {
        $pendentes = $migrador->pendentes();
        echo $pendentes === [] ? "Nenhuma migração pendente.\n" : "Pendentes:\n  " . implode("\n  ", $pendentes) . "\n";
        exit(0);
    }
    $aplicadas = $migrador->aplicar(static function (string $nome): void {
        echo "Aplicada: {$nome}\n";
    });
    echo $aplicadas === [] ? "Banco já está atualizado ({$db->driver()}).\n" : count($aplicadas) . " migração(ões) aplicada(s).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro ao migrar: ' . $e->getMessage() . "\n");
    exit(1);
}
