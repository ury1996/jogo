<?php

/**
 * Atualização da hospedagem pelo GitHub (Lib\Atualizador), sem esperar o cron.
 * Uso: php app/cli/atualizar.php            confere e, se houver versão nova, troca o código
 *      php app/cli/atualizar.php --estado   mostra a versão instalada e a última verificação
 * Depois de trocar o código, rode de novo (ou espere o cron) para migrar o banco e republicar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use Rankly\Lib\Atualizador;

$at = new Atualizador($app);
if (in_array('--estado', $argv, true)) {
    $e = $at->estado();
    echo 'Versão instalada: ' . ($at->versaoInstalada() ?: '(desconhecida)') . "\n";
    echo 'Configurada: ' . ($at->configurado() ? 'sim' : 'não (falta repositório, token ou pasta pública)') . "\n";
    echo 'Última verificação: ' . (isset($e['verificadoEm']) ? gmdate('Y-m-d H:i', (int) $e['verificadoEm']) . ' UTC' : 'nunca') . "\n";
    echo 'Último erro: ' . ($e['ultimoErro'] ?? 'nenhum') . "\n";
    echo 'Pós-atualização pendente: ' . (!empty($e['posPendente']) ? 'sim' : 'não') . "\n";
    exit(0);
}
if (!$at->configurado() && !$at->posPendente()) {
    fwrite(STDERR, "Atualização automática não configurada (rode o instalar.php e informe o token do GitHub).\n");
    exit(1);
}
$msg = $at->talvezAtualizar(true);
echo ($msg ?? 'Já está na versão mais nova (' . $at->versaoInstalada() . ').') . "\n";
exit($msg !== null && str_starts_with($msg, 'Falha') ? 1 : 0);
