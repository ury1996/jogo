<?php

/**
 * Roteador dos sites para o servidor embutido do PHP (somente desenvolvimento):
 *   php -S localhost:8081 -t sites sites/router-dev.php
 * Abra http://{slug}.localhost:8081 (o Chrome e o Firefox resolvem *.localhost sozinhos;
 * no curl use -H "Host: {slug}.localhost:8081"). Emula o sites/.htaccess:
 * POST /_lead → _lead.php; ocultos bloqueados; subdomínio → release no ar; domínio próprio
 * → tabela dominios; 404 do próprio site; mesmos cabeçalhos de cache.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$caminho = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($caminho === '/_lead') {
    require __DIR__ . '/_lead.php';
    return true;
}

require __DIR__ . '/_roteador.php';
return true;
