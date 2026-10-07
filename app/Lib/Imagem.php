<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Processamento de fotos e logos enviados [M15] com GD:
 * tipo real por finfo → recusa por megapixels ANTES de decodificar → orientação EXIF →
 * recodificação (remove EXIF/GPS) → variantes WebP.
 */
final class Imagem
{
    public const LARGURAS_FOTO = [480, 960, 1600];
    public const LARGURAS_LOGO = [160, 320, 640];
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const QUALIDADE_JPEG = 85;
    private const QUALIDADE_WEBP_FOTO = 80;
    private const QUALIDADE_WEBP_LOGO = 90;

    /**
     * Larguras das variantes: as da tabela menores que o original + o original se for
     * menor que a maior largura da tabela.
     *
     * @return list<int>
     */
    public static function larguras(int $largura, string $tipo): array
    {
        $tabela = $tipo === 'logo' ? self::LARGURAS_LOGO : self::LARGURAS_FOTO;
        $r = array_values(array_filter($tabela, static fn (int $w): bool => $w < $largura));
        if ($largura < $tabela[count($tabela) - 1] || $r === []) {
            $r[] = min($largura, $tabela[count($tabela) - 1]);
        } elseif (!in_array($tabela[count($tabela) - 1], $r, true)) {
            $r[] = $tabela[count($tabela) - 1];
        }
        $r = array_values(array_unique($r));
        sort($r);
        return $r;
    }

    /** Tipo MIME pelo conteúdo (finfo), nunca pela extensão. */
    public static function detectarMime(string $arquivo): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($arquivo);
        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }

    /** Marca de formato ISO-BMFF (HEIC/HEIF/AVIF) nos primeiros bytes, ou "". */
    public static function marcaFtyp(string $arquivo): string
    {
        $h = @fopen($arquivo, 'rb');
        if ($h === false) {
            return '';
        }
        $cab = (string) fread($h, 16);
        fclose($h);
        return substr($cab, 4, 4) === 'ftyp' ? strtolower(substr($cab, 8, 4)) : '';
    }

    /**
     * Confere o tipo e devolve o MIME aceito.
     *
     * @throws \InvalidArgumentException formato não suportado (mensagem para o usuário)
     */
    public static function conferirTipo(string $arquivo): string
    {
        $mime = self::detectarMime($arquivo);
        $marca = self::marcaFtyp($arquivo);
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)
            || in_array($marca, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)) {
            throw new \InvalidArgumentException('Formato HEIC não suportado. No iPhone, ajuste Câmera > Formatos > Mais compatível, ou envie JPG.');
        }
        if ($mime === 'image/avif' || $marca === 'avif' || $marca === 'avis') {
            throw new \InvalidArgumentException('Formato AVIF não suportado. Envie a foto em JPG, PNG ou WebP.');
        }
        if ($mime === 'image/gif') {
            throw new \InvalidArgumentException('Formato GIF não suportado. Envie a foto em JPG, PNG ou WebP.');
        }
        if (!in_array($mime, self::MIMES, true)) {
            throw new \InvalidArgumentException('Este arquivo não é uma imagem aceita. Envie JPG, PNG ou WebP.');
        }
        return $mime;
    }

    /**
     * Processa a imagem e grava em $dirDestino: orig.jpg ou orig.png + {w}.webp.
     *
     * @return array{mime: string, largura: int, altura: int, variantes: list<int>, arquivo: string, formato: string}
     * @throws \InvalidArgumentException imagem inválida, grande demais ou de formato não aceito
     */
    public static function processar(string $arquivo, string $tipo, string $dirDestino, int $maxMegapixels): array
    {
        $mime = self::conferirTipo($arquivo);

        // Dimensões pelo cabeçalho, sem decodificar os pixels.
        $info = @getimagesize($arquivo);
        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new \InvalidArgumentException('Não foi possível ler a imagem. O arquivo pode estar corrompido.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w * $h > $maxMegapixels * 1_000_000) {
            throw new \InvalidArgumentException("A imagem tem resolução alta demais (máximo de {$maxMegapixels} megapixels). Reduza o tamanho e envie de novo.");
        }
        self::garantirMemoria($w, $h);

        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($arquivo),
            'image/png' => @imagecreatefrompng($arquivo),
            'image/webp' => @imagecreatefromwebp($arquivo),
        };
        if (!$img instanceof \GdImage) {
            throw new \InvalidArgumentException('Não foi possível ler a imagem. O arquivo pode estar corrompido ou animado.');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        if ($mime === 'image/jpeg' || $mime === 'image/webp') {
            $img = self::aplicarOrientacao($img, self::orientacaoExif($arquivo));
        }
        $largura = imagesx($img);
        $altura = imagesy($img);

        $transparente = $mime !== 'image/jpeg' && self::temTransparencia($img);
        if (!is_dir($dirDestino) && !@mkdir($dirDestino, 0775, true) && !is_dir($dirDestino)) {
            throw new \RuntimeException("Não foi possível criar {$dirDestino}.");
        }

        // Original recodificado (sem metadados). Logo transparente → PNG; resto → JPEG 85.
        if ($tipo === 'logo' && $transparente) {
            $nomeOrig = 'orig.png';
            $ok = imagepng($img, $dirDestino . '/' . $nomeOrig, 6);
        } else {
            $nomeOrig = 'orig.jpg';
            $base = $transparente ? self::sobreBranco($img) : $img;
            $ok = imagejpeg($base, $dirDestino . '/' . $nomeOrig, self::QUALIDADE_JPEG);
        }
        if (!$ok) {
            throw new \RuntimeException('Falha ao gravar a imagem recodificada.');
        }

        $variantes = self::larguras($largura, $tipo);
        $qualidade = $tipo === 'logo' ? self::QUALIDADE_WEBP_LOGO : self::QUALIDADE_WEBP_FOTO;
        $fonte = ($tipo !== 'logo' && $transparente) ? self::sobreBranco($img) : $img;
        foreach ($variantes as $vw) {
            $v = $vw === $largura ? $fonte : self::redimensionar($fonte, $vw);
            imagesavealpha($v, true);
            if (!imagewebp($v, $dirDestino . '/' . $vw . '.webp', $qualidade)) {
                throw new \RuntimeException('Falha ao gerar a variante WebP.');
            }
        }

        return [
            'mime' => $nomeOrig === 'orig.png' ? 'image/png' : 'image/jpeg',
            'largura' => $largura,
            'altura' => $altura,
            'variantes' => $variantes,
            'arquivo' => $nomeOrig,
            'formato' => 'webp',
        ];
    }

    /** Orientação EXIF (1–8); 1 quando ausente ou ilegível. */
    public static function orientacaoExif(string $arquivo): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        try {
            $exif = @exif_read_data($arquivo, 'IFD0');
        } catch (\Throwable) {
            return 1;
        }
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    /** Aplica a orientação EXIF (imagerotate gira no sentido anti-horário). */
    public static function aplicarOrientacao(\GdImage $img, int $orientacao): \GdImage
    {
        $girar = static function (\GdImage $i, int $graus): \GdImage {
            $r = imagerotate($i, $graus, 0);
            if (!$r instanceof \GdImage) {
                throw new \RuntimeException('Falha ao girar a imagem.');
            }
            imagealphablending($r, false);
            imagesavealpha($r, true);
            return $r;
        };
        switch ($orientacao) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 3:
                return $girar($img, 180);
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                return $img;
            case 5: // transposição
                imageflip($img, IMG_FLIP_VERTICAL);
                return $girar($img, -90);
            case 6: // 90° no sentido horário
                return $girar($img, -90);
            case 7: // transversal
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $girar($img, -90);
            case 8: // 90° no sentido anti-horário
                return $girar($img, 90);
            default:
                return $img;
        }
    }

    /** Há algum pixel translúcido? (verifica uma cópia reduzida para ser rápido) */
    public static function temTransparencia(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $amostra = $img;
        if ($w > 256 || $h > 256) {
            $escala = 256 / max($w, $h);
            $amostra = self::redimensionar($img, max(1, (int) floor($w * $escala)), max(1, (int) floor($h * $escala)));
        }
        $aw = imagesx($amostra);
        $ah = imagesy($amostra);
        for ($y = 0; $y < $ah; $y++) {
            for ($x = 0; $x < $aw; $x++) {
                if (((imagecolorat($amostra, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Cópia redimensionada para a largura (altura proporcional, ou a informada), preservando alfa. */
    public static function redimensionar(\GdImage $img, int $largura, ?int $altura = null): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $altura ??= max(1, (int) floor($h * $largura / $w + 0.5));
        $novo = imagecreatetruecolor($largura, $altura);
        imagealphablending($novo, false);
        imagesavealpha($novo, true);
        imagefill($novo, 0, 0, imagecolorallocatealpha($novo, 255, 255, 255, 127));
        imagecopyresampled($novo, $img, 0, 0, 0, 0, $largura, $altura, $w, $h);
        return $novo;
    }

    /** Achata a transparência sobre fundo branco (para JPEG). */
    private static function sobreBranco(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $novo = imagecreatetruecolor($w, $h);
        imagefill($novo, 0, 0, imagecolorallocate($novo, 255, 255, 255));
        imagealphablending($novo, true);
        imagecopy($novo, $img, 0, 0, 0, 0, $w, $h);
        return $novo;
    }

    /** Sobe o memory_limit se a imagem decodificada (4 bytes/pixel, com cópias) não couber. */
    private static function garantirMemoria(int $w, int $h): void
    {
        $necessario = $w * $h * 4 * 3 + 64 * 1024 * 1024;
        $atual = self::bytes((string) ini_get('memory_limit'));
        if ($atual > 0 && $atual < memory_get_usage() + $necessario) {
            @ini_set('memory_limit', (string) (memory_get_usage() + $necessario));
        }
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
