<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Preparo\Texto;

/**
 * Favicon do site publicado (contrato §7 passo 7): SVG com a inicial na cor do site; se houver
 * logo raster, PNG 32×32 (favicon) e 180×180 (apple-touch-icon) gerados com o GD.
 */
final class Favicon
{
    /** Raio do quadrado conforme o acabamento (num viewBox de 64). */
    private const RAIOS = ['classico' => 6, 'moderno' => 18, 'direto' => 13, 'elegante' => 2, 'suave' => 26, 'impacto' => 8];

    /**
     * @param string|null $arquivoLogo imagem raster do logo (png/webp/jpg) ou null
     * @return array{arquivos: array<string, string>, links: string}
     */
    public static function gerar(?string $arquivoLogo, string $inicial, string $cor, string $corTexto, string $acabamento): array
    {
        if ($arquivoLogo !== null) {
            $pngs = self::pngsDoLogo($arquivoLogo);
            if ($pngs !== null) {
                return [
                    'arquivos' => ['favicon.png' => $pngs[0], 'apple-touch-icon.png' => $pngs[1]],
                    'links' => '<link rel="icon" href="/favicon.png" type="image/png" sizes="32x32">'
                        . '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
                ];
            }
        }
        return [
            'arquivos' => ['favicon.svg' => self::svg($inicial, $cor, $corTexto, $acabamento)],
            'links' => '<link rel="icon" href="/favicon.svg" type="image/svg+xml">',
        ];
    }

    /** SVG 64×64: quadrado na cor do site com a inicial no texto de maior contraste. */
    public static function svg(string $inicial, string $cor, string $corTexto, string $acabamento): string
    {
        $cor = preg_match('/^#[0-9a-f]{6}$/D', $cor) ? $cor : '#14161a';
        $corTexto = preg_match('/^#[0-9a-f]{6}$/D', $corTexto) ? $corTexto : '#ffffff';
        $letra = Texto::escapeHtml(mb_substr($inicial, 0, 1, 'UTF-8') ?: 'R');
        $raio = self::RAIOS[$acabamento] ?? 12;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="' . $raio . '" fill="' . $cor . '"/>'
            . '<text x="32" y="44" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif"'
            . ' font-size="36" font-weight="700" fill="' . $corTexto . '">' . $letra . '</text></svg>';
    }

    /**
     * PNG 32 (fundo transparente) e 180 (fundo branco, exigido pelo iOS) com o logo centralizado.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function pngsDoLogo(string $arquivo): ?array
    {
        if (!function_exists('imagecreatefromstring') || !is_file($arquivo)) {
            return null;
        }
        $origem = @imagecreatefromstring((string) file_get_contents($arquivo));
        if ($origem === false) {
            return null;
        }
        try {
            return [self::quadrado($origem, 32, 1, false), self::quadrado($origem, 180, 18, true)];
        } finally {
            imagedestroy($origem);
        }
    }

    private static function quadrado(\GdImage $origem, int $lado, int $margem, bool $fundoBranco): string
    {
        $w = imagesx($origem);
        $h = imagesy($origem);
        $util = $lado - 2 * $margem;
        $escala = min($util / max(1, $w), $util / max(1, $h));
        $nw = max(1, (int) floor($w * $escala + 0.5));
        $nh = max(1, (int) floor($h * $escala + 0.5));
        $img = imagecreatetruecolor($lado, $lado);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $fundo = $fundoBranco ? imagecolorallocate($img, 255, 255, 255) : imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $lado - 1, $lado - 1, $fundo);
        // Sobre branco: mistura a transparência do logo; sobre transparente: copia o alfa como está.
        imagealphablending($img, $fundoBranco);
        imagecopyresampled($img, $origem, intdiv($lado - $nw, 2), intdiv($lado - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return $png;
    }
}
