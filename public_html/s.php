<?php

/**
 * Modo demonstração (config sites_no_caminho): sites publicados em /s/{slug}/ no mesmo
 * endereço do editor. GET/HEAD → arquivos da release (Gerador\SitesNoCaminho);
 * POST /s/{slug}/_lead → recebimento de contatos (sites/_lead.php com o slug do caminho).
 * Fora do modo demonstração responde 404.
 */

declare(strict_types=1);

header_remove('X-Powered-By');

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$caminho = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$partes = \Rankly\Gerador\SitesNoCaminho::separar($caminho);

if ($app->config('sites_no_caminho', false) !== true || $partes === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Página não encontrada.\n";
    exit;
}

if ($partes[1] === '/_lead') {
    $slugDoCaminho = $partes[0];
    require dirname(__DIR__) . '/sites/_lead.php';
    exit;
}

$cabecalhos = [];
if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    $cabecalhos['If-None-Match'] = (string) $_SERVER['HTTP_IF_NONE_MATCH'];
}
try {
    $resposta = \Rankly\Gerador\SitesNoCaminho::responder($app, $metodo, $uri, $cabecalhos);
} catch (\Throwable $e) {
    $app->log()->excecao($e, ['etapa' => 'sites no caminho']);
    $resposta = \Rankly\Http\Resposta::texto("Site temporariamente indisponível.\n", 503);
}
$resposta->enviar($metodo === 'HEAD');
