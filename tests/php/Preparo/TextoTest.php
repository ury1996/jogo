<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Texto;

final class TextoTest extends TestCase
{
    public function testSemAcentosUsaMapaExplicito(): void
    {
        self::assertSame('aaaaaa eeee iiii ooooo uuuu c n y y', Texto::semAcentos('áàâãäå éèêë íìîï óòôõö úùûü ç ñ ý ÿ'));
        self::assertSame('AAAAAA EEEE IIII OOOOO UUUU C N Y Y', Texto::semAcentos('ÁÀÂÃÄÅ ÉÈÊË ÍÌÎÏ ÓÒÔÕÖ ÚÙÛÜ Ç Ñ Ý Ÿ'));
        self::assertSame('Cafe', Texto::semAcentos("Cafe\u{0301}"));
        self::assertSame('', Texto::semAcentos(null));
    }

    public function testNormalizar(): void
    {
        self::assertSame('consultoria tecnica', Texto::normalizar("  Consultoria   TÉCNICA\t\n"));
        self::assertSame('plantao 24 horas', Texto::normalizar("Plantão\u{00A0}24\u{2003}horas"));
    }

    public function testEspacosIguaisAoTrimDoJs(): void
    {
        self::assertSame('a b', Texto::colapsarEspacos("\u{FEFF} a \u{3000} b \u{2028}"));
        self::assertSame('a  b', Texto::aparar("\u{00A0} a  b \n"));
        self::assertSame('x', Texto::aparar("x\n"), 'o $ não para antes do \n final');
    }

    public function testEscapeHtmlSoCincoCaracteres(): void
    {
        self::assertSame('&amp; &lt; &gt; &quot; &#39; / ` =', Texto::escapeHtml('& < > " \' / ` ='));
        self::assertSame('&amp;amp;', Texto::escapeHtml('&amp;'));
        self::assertSame('', Texto::escapeHtml(null));
        self::assertSame('2026', Texto::escapeHtml(2026));
        self::assertSame('true', Texto::escapeHtml(true), 'como String(true) no JS');
        self::assertSame('false', Texto::escapeHtml(false));
        self::assertSame('x,1,', Texto::escapeHtml(['x', 1, null]));
        self::assertSame('[object Object]', Texto::escapeHtml(['a' => 1]));
    }

    public function testCodificarUriComoEncodeUriComponent(): void
    {
        self::assertSame("Ol%C3%A1!%20(teste)%20*a*%20'b'%20~-_.", Texto::codificarUri("Olá! (teste) *a* 'b' ~-_."));
        self::assertSame('a%26b%3Dc%3Fd%23e%2Ff%2Bg', Texto::codificarUri('a&b=c?d#e/f+g'));
        self::assertSame('%F0%9F%98%80', Texto::codificarUri('😀'));
    }

    public function testArredEFloorMaisMeio(): void
    {
        self::assertSame(1, Texto::arred(0.5));
        self::assertSame(3, Texto::arred(2.5));
        self::assertSame(0, Texto::arred(-0.5));
        self::assertSame(-1, Texto::arred(-1.5));
    }

    public function testUtilitariosDeTipo(): void
    {
        self::assertSame('5', Texto::textoDe(5));
        self::assertSame('5', Texto::textoDe(5.0), 'float inteiro do JSON');
        self::assertSame('', Texto::textoDe(1.5));
        self::assertSame('', Texto::textoDe(true));
        self::assertTrue(Texto::ehMapa([]));
        self::assertFalse(Texto::ehMapa(['x']));
        self::assertFalse(Texto::temChave(['x'], '0'), 'listas não são mapas');
        self::assertNull(Texto::pegar(['a' => 1], 'b'));
    }
}
