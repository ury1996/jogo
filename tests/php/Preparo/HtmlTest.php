<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Html;

final class HtmlTest extends TestCase
{
    private const MIDIA = [
        'm_00000001' => ['largura' => 2400, 'altura' => 1600, 'variantes' => [480, 960, 1600], 'alt' => '', 'tipo' => 'foto', 'formato' => 'webp'],
        'm_00000002' => ['largura' => 1200, 'altura' => 900, 'variantes' => [960, 480, 1200], 'alt' => 'Recepção "nova" & <ampla>', 'tipo' => 'foto', 'formato' => 'webp'],
        'm_00000003' => ['largura' => 400, 'altura' => 300, 'variantes' => [400], 'alt' => '', 'tipo' => 'foto'],
        'm_00000005' => ['largura' => 800, 'altura' => 800, 'variantes' => [], 'tipo' => 'foto', 'formato' => 'svg'],
        'm_0000000a' => ['largura' => 800, 'altura' => 200, 'variantes' => [160, 320, 640], 'tipo' => 'logo', 'formato' => 'webp'],
        'm_0000000b' => ['largura' => 120, 'altura' => 40, 'variantes' => [], 'tipo' => 'logo', 'formato' => 'svg'],
        'm_0000000c' => ['largura' => 300, 'altura' => 91, 'variantes' => [160, 300], 'tipo' => 'logo'],
        'm_0000000d' => ['largura' => 1600, 'altura' => 1200, 'variantes' => [480, 960, 1600], 'local' => 'blob:http://x/1'],
    ];

    private static function publicar(): array
    {
        return ['modo' => 'publicar', 'urlMidia' => 'img/{id}-{w}.{ext}', 'midia' => self::MIDIA];
    }

    public function testImagemComAtributosNaOrdem(): void
    {
        self::assertSame(
            '<img src="img/m_00000001-960.webp" srcset="img/m_00000001-480.webp 480w, img/m_00000001-960.webp 960w, img/m_00000001-1600.webp 1600w" sizes="50vw" width="1600" height="1067" alt="Alt" loading="lazy" decoding="async">',
            Html::imagem(['midiaId' => 'm_00000001', 'alt' => 'Alt', 'sizes' => '50vw', 'lcp' => false], self::publicar())['html'],
        );
        self::assertSame(
            '<img src="img/m_00000002-960.webp" srcset="img/m_00000002-480.webp 480w, img/m_00000002-960.webp 960w, img/m_00000002-1200.webp 1200w" sizes="100vw" width="1200" height="900" alt="Recepção &quot;nova&quot; &amp; &lt;ampla&gt;" loading="eager" decoding="async" fetchpriority="high">',
            Html::imagem(['midiaId' => 'm_00000002', 'alt' => 'ignorado', 'lcp' => true], self::publicar())['html'],
        );
        self::assertSame(
            '<img src="img/m_00000003-400.webp" srcset="img/m_00000003-400.webp 400w" sizes="100vw" width="400" height="300" alt="A" loading="lazy" decoding="async">',
            Html::imagem(['midiaId' => 'm_00000003', 'alt' => 'A'], self::publicar())['html'],
        );
        self::assertSame(
            '<img src="img/m_00000005-orig.svg" width="800" height="800" alt="S" loading="lazy" decoding="async">',
            Html::imagem(['midiaId' => 'm_00000005', 'alt' => 'S'], self::publicar())['html'],
        );
    }

    public function testImagemVaziaEPreviaLocal(): void
    {
        self::assertSame(['html' => '<span class="rk-foto__vazio" aria-hidden="true"></span>', 'vazio' => true], Html::imagem(['midiaId' => null, 'rotulo' => 'Foto'], self::publicar()));
        self::assertSame(['html' => '<span class="rk-foto__vazio">Foto &amp; equipe · enviar</span>', 'vazio' => true], Html::imagem(['midiaId' => 'm_ffffffff', 'rotulo' => 'Foto & equipe'], ['modo' => 'editor', 'midia' => self::MIDIA]));
        self::assertSame('<img src="blob:http://x/1" width="1600" height="1200" alt="A" loading="lazy" decoding="async">', Html::imagem(['midiaId' => 'm_0000000d', 'alt' => 'A'], ['modo' => 'editor', 'midia' => self::MIDIA])['html']);
        self::assertStringStartsWith('<img src="/api/media/m_00000001/960" srcset="/api/media/m_00000001/480 480w', Html::imagem(['midiaId' => 'm_00000001'], ['modo' => 'editor', 'midia' => self::MIDIA])['html']);
    }

    public function testLogo(): void
    {
        self::assertSame(
            ['html' => '<img class="rk-logo__img" src="img/m_0000000a-320.webp" srcset="img/m_0000000a-320.webp 1x, img/m_0000000a-640.webp 2x" width="320" height="80" alt="A &amp; B">', 'temLogo' => true],
            Html::logo(['midiaId' => 'm_0000000a', 'nome' => 'A & B', 'inicial' => 'A'], self::publicar()),
        );
        self::assertSame('<img class="rk-logo__img" src="img/m_0000000c-300.webp" srcset="img/m_0000000c-300.webp 1x" width="300" height="91" alt="N">', Html::logo(['midiaId' => 'm_0000000c', 'nome' => 'N'], self::publicar())['html']);
        self::assertSame('<img class="rk-logo__img" src="img/m_0000000b-orig.svg" width="120" height="40" alt="N">', Html::logo(['midiaId' => 'm_0000000b', 'nome' => 'N'], self::publicar())['html']);
        self::assertSame(['html' => '<span class="rk-logo__ini" aria-hidden="true">&lt;</span>', 'temLogo' => false], Html::logo(['midiaId' => null, 'nome' => 'N', 'inicial' => '<'], self::publicar()));
    }

    public function testInvolucroEBotao(): void
    {
        self::assertSame('<div class="rk-sec rk-sec--faq rk-op--faq-centralizada rk-bg--tom" id="perguntas" data-sec="3">X</div>', Html::involucro('faq', 'centralizada', 'tom', 'perguntas', 3, 'X'));
        self::assertSame('<a class="rk-wa" href="https://wa.me/55?text=a&amp;b" data-ev="whatsapp" data-pos="flutuante" target="_blank" rel="noopener" aria-label="Conversar no WhatsApp"><svg/></a>', Html::botaoWhatsapp('https://wa.me/55?text=a&b', '<svg/>'));
    }
}
