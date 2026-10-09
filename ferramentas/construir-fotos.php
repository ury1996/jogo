<?php

/**
 * Gera as fotos de exemplo da biblioteca (biblioteca/fotos/) a partir de ferramentas/fotos-exemplo.json:
 * baixa cada original do Unsplash (cache em var/cache/fotos-origem/), gera as variantes WebP
 * 480/960/1600 e escreve biblioteca/fotos/fotos.json (dimensões, variantes, alt, pools por nicho).
 *
 * Uso: php ferramentas/construir-fotos.php [--forcar]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
$fonte = json_decode((string) file_get_contents(__DIR__ . '/fotos-exemplo.json'), true, 512, JSON_THROW_ON_ERROR);
$saida = $raiz . '/biblioteca/fotos';
$cache = $raiz . '/var/cache/fotos-origem';
@mkdir($saida, 0775, true);
@mkdir($cache, 0775, true);
$forcar = in_array('--forcar', $argv, true);

const LARGURAS = [480, 960, 1600];
const QUALIDADE = 72;

$fotos = [];
foreach ($fonte['fotos'] as $id => [$unsplash, $alt]) {
    if (!preg_match('/^[a-z0-9-]+$/', $id)) {
        throw new RuntimeException("id inválido: {$id}");
    }
    $original = "{$cache}/{$unsplash}.jpg";
    if (!is_file($original)) {
        $url = "https://images.unsplash.com/photo-{$unsplash}?w=2000&q=85&fm=jpg";
        $ch = curl_init($url);
        $h = fopen($original . '.tmp', 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_FAILONERROR => true]);
        $ok = curl_exec($ch);
        fclose($h);
        if (!$ok) {
            @unlink($original . '.tmp');
            throw new RuntimeException("Falha ao baixar {$id} ({$unsplash}): " . curl_error($ch));
        }
        rename($original . '.tmp', $original);
    }
    $img = imagecreatefromjpeg($original);
    if ($img === false) {
        throw new RuntimeException("JPEG inválido: {$original}");
    }
    $w0 = imagesx($img);
    $h0 = imagesy($img);
    $variantes = [];
    foreach (LARGURAS as $w) {
        if ($w > $w0) {
            continue;
        }
        $variantes[] = $w;
        $arquivo = "{$saida}/{$id}-{$w}.webp";
        if (is_file($arquivo) && !$forcar) {
            continue;
        }
        $h = (int) round($h0 * $w / $w0);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, $w0, $h0);
        imagewebp($dst, $arquivo, QUALIDADE);
        imagedestroy($dst);
    }
    $maior = end($variantes);
    $fotos[$id] = [
        'largura' => $maior,
        'altura' => (int) round($h0 * $maior / $w0),
        'variantes' => $variantes,
        'alt' => $alt,
        'fonte' => 'Unsplash',
    ];
    imagedestroy($img);
    echo "ok {$id}\n";
}

// Remove variantes de fotos que saíram da lista.
foreach (glob($saida . '/*.webp') ?: [] as $arquivo) {
    if (!preg_match('#/([a-z0-9-]+)-(\d+)\.webp$#', $arquivo, $m) || !isset($fotos[$m[1]])) {
        unlink($arquivo);
    }
}

foreach ($fonte['nichos'] as $nicho => $pools) {
    foreach ($pools as $pool => $ids) {
        foreach ($ids as $id) {
            if (!isset($fotos[$id])) {
                throw new RuntimeException("nichos.{$nicho}.{$pool}: foto desconhecida {$id}");
            }
        }
    }
}

$json = [
    'licenca' => 'Fotos do Unsplash (unsplash.com/license): uso livre, inclusive comercial, sem atribuição obrigatória.',
    'fotos' => $fotos,
    'nichos' => $fonte['nichos'],
];
file_put_contents($saida . '/fotos.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($fotos) . " fotos em {$saida}\n";
