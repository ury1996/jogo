<?php

/**
 * Roteador para o servidor embutido do PHP (somente desenvolvimento):
 *   php -S localhost:8080 -t public_html public_html/router-dev.php
 * Reproduz o .htaccess: /api/* → api/index.php, nega arquivos ocultos, serve o editor
 * estático com os mesmos cabeçalhos de segurança e tipos MIME corretos (.mjs).
 */

declare(strict_types=1);

// Só para o servidor embutido: no Apache/LiteSpeed um GET /router-dev.php não pode virar um
// segundo roteador (com regras próprias) por fora do .htaccess.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$raizWeb = __DIR__;
$caminho = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$caminho = rawurldecode($caminho);

$cabecalhosBase = static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
};

// Ocultos e travessia de pastas.
if (preg_match('#(^|/)\.(?!well-known/)#', $caminho) || str_contains($caminho, "\0")) {
    $cabecalhosBase();
    http_response_code(403);
    echo 'Proibido';
    return true;
}

// API.
if ($caminho === '/api' || str_starts_with($caminho, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $raizWeb . '/api/index.php';
    return true;
}

if ($caminho === '/' || $caminho === '/index.php') {
    header('Location: /editor/', true, 302);
    return true;
}
if ($caminho === '/editor') {
    header('Location: /editor/', true, 301);
    return true;
}

// Arquivos estáticos (só dentro de public_html, nunca .php).
$arquivo = realpath($raizWeb . $caminho);
if ($arquivo !== false && is_dir($arquivo)) {
    $arquivo = realpath($arquivo . '/index.html');
}
if ($arquivo === false || !str_starts_with($arquivo, realpath($raizWeb) . DIRECTORY_SEPARATOR) || !is_file($arquivo)
    || preg_match('/\.(php|phtml|phar|htaccess|ini)$/i', $arquivo)) {
    $cabecalhosBase();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado';
    return true;
}

$tipos = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8', 'map' => 'application/json',
];
$ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
$cabecalhosBase();
header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($arquivo));
header('Cache-Control: no-cache');
if (str_starts_with($caminho, '/editor/')) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
        . "font-src 'self' data:; connect-src 'self' https://viacep.com.br; frame-src 'self' https://www.google.com; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($arquivo);
}
return true;
