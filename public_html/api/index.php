<?php

/**
 * Front controller da API (/api/*). O .htaccess (Apache/LiteSpeed) e o router-dev.php
 * (php -S) encaminham para cá.
 */

declare(strict_types=1);

try {
    /** @var \Rankly\Aplicacao $app */
    $app = require dirname(__DIR__, 2) . '/app/bootstrap.php';
} catch (\Throwable $e) {
    // Configuração ausente/inválida: responde sem expor detalhes e registra no log do PHP.
    error_log('Rankly: falha ao iniciar a API: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo '{"erro":{"codigo":"erro_interno","mensagem":"O servidor não está configurado corretamente. Avise o suporte."}}';
    exit;
}

$api = new \Rankly\Api\Api($app);
$req = \Rankly\Http\Requisicao::doGlobais();
$resposta = $api->processar($req);
$resposta->enviar($req->metodo === 'HEAD');

// Tarefas criadas nesta requisição (ex.: e-mail do lead) rodam depois de a resposta sair.
\Rankly\Lib\Executores::processarAposResposta($app);
