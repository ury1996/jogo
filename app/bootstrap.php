<?php

/**
 * Inicialização comum (API, CLI, roteadores): autoload, configuração e tratamento de erros.
 * Uso: $app = require __DIR__ . '/../app/bootstrap.php';
 * A configuração vem de config/config.php, ou do arquivo indicado na variável RANKLY_CONFIG.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__);
if (!is_file($raiz . '/vendor/autoload.php')) {
    $aviso = "Dependências ausentes: rode \"composer install\" na raiz do projeto.\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $aviso);
    } else {
        http_response_code(500);
        error_log($aviso);
    }
    exit(1);
}
require_once $raiz . '/vendor/autoload.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$app = \Rankly\Aplicacao::iniciar();

// Em produção nenhum erro vai para a tela: tudo para var/logs.
if ($app->producao()) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
try {
    ini_set('error_log', $app->dirVar('logs') . '/php-erros.log');
} catch (\Throwable) {
    // var/ não gravável: mantém o log padrão do PHP
}

return $app;
