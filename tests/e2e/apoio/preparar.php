<?php

/**
 * Preparo do ambiente ponta a ponta (chamado pelo global-setup do Playwright).
 *
 * Uso: RANKLY_CONFIG=/tmp/…/config.php php tests/e2e/apoio/preparar.php <acao> [args]
 *   banco <email> <senha>   aplica as migrações, esvazia as tabelas e cria o usuário admin
 *   fotos <pasta>           gera fotos JPEG de exemplo (paisagem e retrato) e um logo PNG
 *   limites                 zera a tabela de limites (os testes enviam vários leads do mesmo IP)
 *   lead <id-do-site>       último lead do site, em JSON (ou null)
 *
 * Imprime um JSON com o resultado; código de saída ≠ 0 em caso de erro.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__, 3);
$acao = $argv[1] ?? '';

try {
    if ($acao === 'fotos') {
        echo json_encode(gerarFotos((string) ($argv[2] ?? '')), JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }

    /** @var \Rankly\Aplicacao $app */
    $app = require $raiz . '/app/bootstrap.php';
    $db = $app->db();

    switch ($acao) {
        case 'banco':
            $migrador = new \Rankly\Lib\Migrador($db, \Rankly\Lib\Migrador::dirPadrao($db));
            $migrador->aplicar();
            $tabelas = ['eventos', 'leads', 'midia', 'versoes', 'dominios', 'site_acessos', 'redefinicoes_senha', 'sites', 'usuarios', 'tarefas', 'limites'];
            if ($db->driver() === 'mysql') {
                $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tabelas as $t) {
                    $db->pdo()->exec("TRUNCATE TABLE `{$t}`");
                }
                $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                foreach ($tabelas as $t) {
                    $db->pdo()->exec("DELETE FROM {$t}");
                }
            }
            $id = \Rankly\Lib\Usuarios::criar($app, 'Equipe E2E', (string) $argv[2], 'admin', (string) $argv[3]);
            echo json_encode(['driver' => $db->driver(), 'usuario' => $id]), "\n";
            break;
        case 'limites':
            $db->executar('DELETE FROM limites');
            echo "{\"ok\":true}\n";
            break;
        case 'lead':
            $lead = $db->um('SELECT * FROM leads WHERE site_id = ? ORDER BY id DESC LIMIT 1', [(int) ($argv[2] ?? 0)]);
            echo json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            break;
        default:
            fwrite(STDERR, "Ação desconhecida: {$acao}\n");
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Fotos "de verdade" o bastante para o teste: gradiente, formas e ruído (o WebP não fica
 * trivial), em tamanhos de câmera; e um logo PNG transparente.
 *
 * @return array{fotos: list<string>, logo: string}
 */
function gerarFotos(string $pasta): array
{
    if ($pasta === '' || (!is_dir($pasta) && !mkdir($pasta, 0775, true))) {
        throw new RuntimeException('Pasta inválida para as fotos.');
    }
    $paletas = [
        [[38, 84, 124], [239, 211, 175]], [[64, 112, 84], [226, 232, 214]], [[150, 72, 58], [246, 222, 200]],
        [[42, 48, 66], [180, 196, 222]], [[120, 96, 150], [240, 228, 246]], [[24, 110, 120], [214, 240, 236]],
    ];
    $tamanhos = [[2400, 1600], [1800, 2400], [2000, 1500], [1600, 1600], [2400, 1350], [1500, 2000]];
    $fotos = [];
    mt_srand(42);
    foreach ($tamanhos as $i => [$w, $h]) {
        [$a, $b] = $paletas[$i];
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += 2) {
            $t = $y / $h;
            $cor = imagecolorallocate($img, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
            imagefilledrectangle($img, 0, $y, $w, $y + 1, $cor);
        }
        for ($k = 0; $k < 14; $k++) {
            $cor = imagecolorallocatealpha($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(60, 100));
            $r = mt_rand((int) ($w / 14), (int) ($w / 4));
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), $r, $r, $cor);
        }
        for ($k = 0; $k < 4000; $k++) {
            $c = mt_rand(0, 255);
            imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocatealpha($img, $c, $c, $c, 90));
        }
        $arquivo = $pasta . '/foto-' . ($i + 1) . '.jpg';
        imagejpeg($img, $arquivo, 85);
        imagedestroy($img);
        $fotos[] = $arquivo;
    }
    $logo = imagecreatetruecolor(600, 200);
    imagesavealpha($logo, true);
    imagealphablending($logo, false);
    imagefilledrectangle($logo, 0, 0, 599, 199, imagecolorallocatealpha($logo, 0, 0, 0, 127));
    imagealphablending($logo, true);
    imagefilledellipse($logo, 100, 100, 160, 160, imagecolorallocate($logo, 30, 90, 160));
    imagefilledrectangle($logo, 210, 70, 560, 130, imagecolorallocate($logo, 40, 40, 48));
    $arquivoLogo = $pasta . '/logo.png';
    imagepng($logo, $arquivoLogo);
    imagedestroy($logo);
    return ['fotos' => $fotos, 'logo' => $arquivoLogo];
}
