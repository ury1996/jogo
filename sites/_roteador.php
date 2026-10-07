<?php

/**
 * Roteador PHP dos sites publicados ([M11], contrato §7.1):
 * - domínio próprio (fase 2): consulta a tabela dominios (com cache em var/cache/dominios.json)
 *   e entrega o arquivo estático da release no ar, com tipo e cache corretos;
 * - página 404 de cada site (ErrorDocument 404 do .htaccess também cai aqui);
 * - no desenvolvimento, é chamado pelo router-dev.php para todos os hosts.
 * Só entrega arquivos de dentro da pasta do site (sem "..", sem ocultos).
 * Se a pasta sites/ não estiver dentro do projeto, defina RANKLY_RAIZ com a raiz do projeto.
 */

declare(strict_types=1);

header_remove('X-Powered-By');

$raizProjeto = getenv('RANKLY_RAIZ') ?: dirname(__DIR__);

try {
    /** @var \Rankly\Aplicacao $app */
    $app = require $raizProjeto . '/app/bootstrap.php';
} catch (\Throwable $e) {
    error_log('Rankly: falha ao iniciar o roteador dos sites: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo "Site temporariamente indisponível.\n";
    exit;
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$caminho = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
$cabecalhos = [];
if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    $cabecalhos['If-None-Match'] = (string) $_SERVER['HTTP_IF_NONE_MATCH'];
}

try {
    $slug = \Rankly\Gerador\ServidorEstatico::slugDoHost($app, (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $servidor = new \Rankly\Gerador\ServidorEstatico($app->dir('sites'));
    $resposta = $servidor->responder($slug, $metodo, $caminho, $query, $cabecalhos);
} catch (\Throwable $e) {
    $app->log()->excecao($e, ['etapa' => 'roteador dos sites']);
    $resposta = \Rankly\Http\Resposta::texto("Site temporariamente indisponível.\n", 503)->cabecalho('Retry-After', '60');
}
$resposta->enviar($metodo === 'HEAD');
