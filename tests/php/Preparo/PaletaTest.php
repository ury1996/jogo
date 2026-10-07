<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Paleta;

final class PaletaTest extends TestCase
{
    private const ORDEM = ['--p', '--on-p', '--p-ink', '--p-dark', '--p-soft', '--p-soft2', '--p-tint', '--deep', '--ink', '--muted',
        '--line', '--p-fino', '--p-sobre-escuro', '--on-p-sobre-escuro'];

    public function testHexParaRgb(): void
    {
        self::assertSame([194, 59, 110], Paleta::hexParaRgb('#C23B6E'));
        self::assertSame([170, 187, 204], Paleta::hexParaRgb('abc'));
        self::assertNull(Paleta::hexParaRgb('#12345'));
        self::assertNull(Paleta::hexParaRgb("#abc\n#"));
        self::assertSame('#aabbcc', Paleta::normalizarCor('#ABC'));
    }

    public function testIdaEVoltaHsl(): void
    {
        for ($r = 0; $r < 256; $r += 15) {
            for ($g = 0; $g < 256; $g += 15) {
                for ($b = 0; $b < 256; $b += 15) {
                    $hex = Paleta::rgbParaHex([$r, $g, $b]);
                    [$h, $s, $l] = Paleta::rgbParaHsl([$r, $g, $b]);
                    self::assertTrue($h >= 0 && $h < 360 && $s >= 0 && $s <= 100 && $l >= 0 && $l <= 100);
                    self::assertSame($hex, Paleta::hslParaHex($h, $s, $l));
                }
            }
        }
        self::assertSame(Paleta::hslParaHex(240, 100, 50), Paleta::hslParaHex(-120, 100, 50));
    }

    public function testLuminanciaEContraste(): void
    {
        for ($i = 0; $i < 256; $i++) {
            $c = $i / 255;
            $esperado = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            self::assertEqualsWithDelta($esperado, Paleta::luminancia([$i, $i, $i]), 1e-15);
        }
        self::assertSame(21.0, Paleta::contraste('#000000', '#ffffff'));
        self::assertSame(1.0, Paleta::contraste('#ffffff', '#ffffff'));
    }

    public function testTokensNaOrdemEFormulas(): void
    {
        ['tokens' => $t, 'claraDemais' => $clara] = Paleta::gerarPaleta('#C23B6E');
        self::assertSame(self::ORDEM, array_keys($t));
        self::assertFalse($clara);
        self::assertSame('#c23b6e', $t['--p']);
        self::assertSame('#ffffff', $t['--on-p']);
        [$H, $S, $L] = Paleta::rgbParaHsl([194, 59, 110]);
        self::assertSame(Paleta::hslParaHex($H, $S, min($L, 36)), $t['--p-ink']);
        self::assertSame(Paleta::hslParaHex($H, $S, max($L - 14, 10)), $t['--p-dark']);
        self::assertSame(Paleta::hslParaHex($H, min($S, 60), 93), $t['--p-soft']);
        self::assertSame(Paleta::hslParaHex($H, min($S, 40), 12), $t['--deep']);
        self::assertSame(Paleta::hslParaHex($H, 22, 13), $t['--ink']);
        self::assertSame('#c23b6e', $t['--p-sobre-escuro']);
        foreach ($t as $v) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $v);
        }
        self::assertSame('.rk{--p:#c23b6e;--on-p:#ffffff;--p-ink:#8d2b50;--p-dark:#8b2a4f;--p-soft:#f7e4eb;--p-soft2:#ebc2d1;--p-tint:#faf5f7;--deep:#2b121c;--ink:#281a1f;--muted:#6f5d64;--line:#e7dee2;--p-fino:#c23b6e;--p-sobre-escuro:#c23b6e;--on-p-sobre-escuro:#ffffff}', Paleta::cssPaleta($t));
    }

    public function testCorClaraDemais(): void
    {
        ['tokens' => $t, 'claraDemais' => $clara] = Paleta::gerarPaleta('#fff3b0');
        self::assertTrue($clara);
        self::assertSame($t['--p-ink'], $t['--p-fino']);
        self::assertSame('#14161a', $t['--on-p']);
        self::assertSame('#14161a', Paleta::gerarPaleta('#f5d90a')['tokens']['--on-p']);
    }

    public function testCorEscuraClareiaSobreEscuro(): void
    {
        $t = Paleta::gerarPaleta('#1b2a4a')['tokens'];
        [$H, $S] = Paleta::rgbParaHsl([27, 42, 74]);
        self::assertLessThan(3, Paleta::contraste('#1b2a4a', $t['--deep']));
        self::assertSame(Paleta::hslParaHex($H, min($S, 80), 68), $t['--p-sobre-escuro']);
        self::assertSame('#14161a', $t['--on-p-sobre-escuro']);
    }

    public function testCinzaFicaNeutro(): void
    {
        $t = Paleta::gerarPaleta('#777777')['tokens'];
        foreach ($t as $nome => $hex) {
            if ($nome === '--on-p' || $nome === '--on-p-sobre-escuro') {
                continue;
            }
            [$r, $g, $b] = Paleta::hexParaRgb($hex);
            self::assertTrue($r === $g && $g === $b, "{$nome} = {$hex}");
        }
    }

    public function testCorInvalidaUsaPadrao(): void
    {
        self::assertSame(Paleta::gerarPaleta(Paleta::COR_PADRAO), Paleta::gerarPaleta('zzz'));
    }
}
