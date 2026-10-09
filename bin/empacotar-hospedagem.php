<?php

/**
 * Monta o pacote para hospedagem compartilhada (sem SSH):
 *   dist/rankly-hospedagem.zip  código + biblioteca + dependências (sem as de teste), sem dados e sem segredos
 *   dist/instalar.php           o instalador (ferramentas/hospedagem/instalar.php)
 *
 * Uso:  php bin/empacotar-hospedagem.php
 *       (sem PHP/Composer no computador: docker compose run --rm --no-deps rankly php bin/empacotar-hospedagem.php)
 *
 * Os dois arquivos vão para a pasta pública de um subdomínio vazio; depois é só abrir
 * https://subdominio/instalar.php. Passo a passo: docs/HOSPEDAGEM.md.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
$dist = $raiz . '/dist';
$palco = sys_get_temp_dir() . '/rankly-pacote-' . bin2hex(random_bytes(4));
$destino = $palco . '/rankly';

/** O que vai no pacote (o resto — testes, var/, media/, sites publicados, .env, config.php — fica de fora). */
$itens = [
    'app', 'biblioteca', 'public_html', 'composer.json', 'composer.lock', 'config/config.exemplo.php',
    'sites/.htaccess', 'sites/_lead.php', 'sites/_roteador.php', 'media/.htaccess',
];
$fora = ['public_html/router-dev.php'];

function copiar(string $de, string $para, array $fora, string $raiz): void
{
    $rel = substr($de, strlen($raiz) + 1);
    if (in_array($rel, $fora, true) || basename($de) === '.DS_Store') {
        return;
    }
    if (is_dir($de)) {
        @mkdir($para, 0755, true);
        foreach (scandir($de) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                copiar($de . '/' . $f, $para . '/' . $f, $fora, $raiz);
            }
        }
        return;
    }
    @mkdir(dirname($para), 0755, true);
    if (!copy($de, $para)) {
        throw new RuntimeException("Falha ao copiar {$rel}");
    }
}

function apagar(string $p): void
{
    if (is_dir($p) && !is_link($p)) {
        foreach (scandir($p) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                apagar($p . '/' . $f);
            }
        }
        rmdir($p);
    } elseif (file_exists($p) || is_link($p)) {
        unlink($p);
    }
}

try {
    foreach ($itens as $item) {
        if (!file_exists($raiz . '/' . $item)) {
            throw new RuntimeException("Falta {$item} no projeto.");
        }
        copiar($raiz . '/' . $item, $destino . '/' . $item, $fora, $raiz);
    }
    foreach (['config', 'media', 'sites', 'var'] as $pasta) {
        @mkdir($destino . '/' . $pasta, 0755, true);
    }

    // Dependências de produção (só o Mustache), com autoload otimizado.
    $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') {
        throw new RuntimeException('Composer não encontrado. Rode pelo Docker: docker compose run --rm --no-deps rankly php bin/empacotar-hospedagem.php');
    }
    $saida = [];
    exec(escapeshellarg($composer) . ' install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet -d '
        . escapeshellarg($destino) . ' 2>&1', $saida, $codigo);
    if ($codigo !== 0 || !is_file($destino . '/vendor/autoload.php')) {
        throw new RuntimeException("composer install falhou:\n" . implode("\n", $saida));
    }

    // Versão (aparece no relatório do instalador e ajuda o suporte).
    require_once $raiz . '/vendor/autoload.php';
    $versaoLib = substr(\Rankly\Preparo\Biblioteca::versao($raiz . '/biblioteca'), 0, 8);
    $commit = trim((string) @shell_exec('git -C ' . escapeshellarg($raiz) . ' rev-parse --short HEAD 2>/dev/null'));
    file_put_contents($destino . '/VERSAO.txt', 'Pacote: ' . gmdate('Y-m-d H:i') . " UTC\nBiblioteca: {$versaoLib}\n" . ($commit !== '' ? "Código: {$commit}\n" : ''));

    @mkdir($dist, 0755, true);
    $zipArq = $dist . '/rankly-hospedagem.zip';
    @unlink($zipArq);
    $zip = new ZipArchive();
    if ($zip->open($zipArq, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Não consegui criar {$zipArq}");
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($palco, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $n = 0;
    foreach ($iter as $arq) {
        $rel = substr($arq->getPathname(), strlen($palco) + 1);
        if ($arq->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($arq->getPathname(), $rel);
            $n++;
        }
    }
    $zip->close();
    copy($raiz . '/ferramentas/hospedagem/instalar.php', $dist . '/instalar.php');
    apagar($palco);

    printf("Pronto: %d arquivos.\n  %s (%.1f MB)\n  %s\nBiblioteca %s. Envie os dois para a pasta pública do subdomínio e abra /instalar.php.\n",
        $n, $zipArq, filesize($zipArq) / 1048576, $dist . '/instalar.php', $versaoLib);
} catch (Throwable $e) {
    apagar($palco);
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
