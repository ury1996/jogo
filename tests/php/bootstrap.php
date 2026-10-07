<?php

/**
 * Bootstrap dos testes PHP: autoload do Composer (Rankly\ → app/, Rankly\Testes\ → tests/php/).
 * Cada teste monta a própria aplicação (Aplicacao::iniciar com config de teste), sem config/config.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// O PHPUnit 11 aborta se a pasta de uma suíte não existe; as suítes são de agentes diferentes
// e podem ainda não ter testes (ex.: Gerador). Garante as pastas vazias.
foreach (['Preparo', 'Lib', 'Api', 'Gerador'] as $suite) {
    if (!is_dir(__DIR__ . '/' . $suite)) {
        @mkdir(__DIR__ . '/' . $suite, 0775, true);
    }
}
