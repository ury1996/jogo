<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

/**
 * Gera imagens de teste com GD (e EXIF de orientação montado à mão).
 */
final class Imagens
{
    /** JPEG w×h: metade esquerda vermelha, metade direita azul. */
    public static function jpeg(string $arquivo, int $w, int $h, ?int $orientacao = null): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $bytes = (string) ob_get_clean();
        if ($orientacao !== null) {
            $bytes = self::comOrientacao($bytes, $orientacao);
        }
        file_put_contents($arquivo, $bytes);
        return $arquivo;
    }

    /** PNG w×h; transparente = fundo totalmente transparente com um quadrado opaco. */
    public static function png(string $arquivo, int $w, int $h, bool $transparente = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $fundo = $transparente ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $fundo);
        imagefilledrectangle($img, intdiv($w, 4), intdiv($h, 4), intdiv($w * 3, 4), intdiv($h * 3, 4), imagecolorallocate($img, 20, 120, 60));
        imagepng($img, $arquivo);
        return $arquivo;
    }

    public static function gif(string $arquivo): string
    {
        $img = imagecreate(10, 10);
        imagecolorallocate($img, 1, 2, 3);
        imagegif($img, $arquivo);
        return $arquivo;
    }

    /** Insere um segmento APP1/EXIF com a tag Orientation logo após o SOI. */
    public static function comOrientacao(string $jpeg, int $orientacao): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8)        // big-endian, IFD0 no byte 8
            . pack('n', 1)                          // 1 entrada
            . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientacao) . "\x00\x00"
            . pack('N', 0);                         // sem próximo IFD
        $app1 = "Exif\x00\x00" . $tiff;
        $segmento = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
        return substr($jpeg, 0, 2) . $segmento . substr($jpeg, 2);
    }

    /**
     * JPEG "falso" com cabeçalho declarando dimensões enormes (SOF0), sem dados de pixel
     * válidos: getimagesize lê as dimensões; decodificar falharia/estouraria memória.
     */
    public static function jpegGigante(string $arquivo, int $w, int $h): string
    {
        $sof = "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', $h) . pack('n', $w) . "\x03"
            . "\x01\x22\x00" . "\x02\x11\x01" . "\x03\x11\x01";
        file_put_contents($arquivo, "\xFF\xD8" . "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . $sof . "\xFF\xD9");
        return $arquivo;
    }

    /** Arquivo com assinatura ISO-BMFF de HEIC. */
    public static function heic(string $arquivo): string
    {
        file_put_contents($arquivo, pack('N', 24) . 'ftypheic' . pack('N', 0) . 'mif1heic' . str_repeat("\x00", 64));
        return $arquivo;
    }

    /** Cor (r,g,b) de um pixel de uma imagem qualquer que o GD leia. */
    public static function cor(string $arquivo, int $x, int $y): array
    {
        $img = imagecreatefromstring((string) file_get_contents($arquivo));
        $c = imagecolorat($img, $x, $y);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }
}
