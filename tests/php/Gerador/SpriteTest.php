<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Css;
use Rankly\Gerador\Sprite;
use Rankly\Preparo\Biblioteca;

final class SpriteTest extends TestCase
{
    private const ICONE = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M1 1h22v22H1z"/></svg>';
    private const OUTRO = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M2 2h20v20H2z"/></svg>';

    public function testIconesRepetidosViramSimboloEUse(): void
    {
        $html = '<p><span class="a">' . self::ICONE . '</span><span class="b">' . self::ICONE . '</span>' . self::OUTRO . '</p>';
        $r = Sprite::aplicar($html);
        $this->assertStringStartsWith('<svg aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden"><symbol id="rk-i1" viewBox="0 0 24 24"><path d="M1 1h22v22H1z"/></symbol></svg>', $r);
        $this->assertSame(2, substr_count($r, '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><use href="#rk-i1"/></svg>'));
        $this->assertStringContainsString(self::OUTRO, $r, 'ícone único continua embutido');
        $this->assertSame(1, substr_count($r, 'M1 1h22v22H1z'));
    }

    public function testMesmoDesenhoComAtributosDiferentesCompartilhaOSimbolo(): void
    {
        $outraClasse = str_replace('<svg ', '<svg class="x" ', self::ICONE);
        $r = Sprite::aplicar(self::ICONE . $outraClasse);
        $this->assertStringContainsString('<svg class="x" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><use href="#rk-i1"/></svg>', $r);
    }

    public function testSemRepeticaoOuComReferenciasInternasNadaMuda(): void
    {
        $this->assertSame(self::ICONE . self::OUTRO, Sprite::aplicar(self::ICONE . self::OUTRO));
        $comId = '<svg viewBox="0 0 2 2"><defs><linearGradient id="g"/></defs><path fill="url(#g)" d="M0 0h2v2z"/></svg>';
        $this->assertSame($comId . $comId, Sprite::aplicar($comId . $comId));
    }

    public function testCssQueEntraNoIconeMantemOIconeEmbutido(): void
    {
        $css = '.k-moderno .dep-estrela path[opacity]{opacity:1}.rk-i svg{width:1em}';
        $html = '<div class="dep"><span class="dep-estrela">' . self::ICONE . '</span><span class="dep-estrela">' . self::ICONE . '</span></div>'
            . '<span class="rk-i">' . self::ICONE . '</span><span class="rk-i">' . self::ICONE . '</span>';
        // Sem a classe do acabamento na raiz, a regra não alcança as estrelas: todas viram <use>.
        $this->assertSame(4, substr_count(Sprite::aplicar($html, $css, ['rk', 'k-classico']), '<use href='));
        // Com k-moderno, as estrelas ficam embutidas (a regra pinta o path delas).
        $r = Sprite::aplicar($html, $css, ['rk', 'k-moderno']);
        $this->assertSame(2, substr_count($r, '<use href='));
        $this->assertSame(2, substr_count($r, '<span class="dep-estrela">' . self::ICONE . '</span>'));
    }

    public function testSeletorInternoSemClasseDesligaOSprite(): void
    {
        $this->assertNull(Sprite::exigenciasInternas('svg > path{fill:red}'));
        $this->assertNull(Sprite::exigenciasInternas('@media (max-width:760px){circle{r:2}}'));
        $html = self::ICONE . self::ICONE;
        $this->assertSame($html, Sprite::aplicar($html, 'svg path{fill:red}'));
        $this->assertSame([], Sprite::exigenciasInternas('.rk :where(img,svg,video){display:block}.rk-ic svg{width:50%}.serv-img,.x g-y{color:red}'));
        $this->assertSame([['x', 'y']], Sprite::exigenciasInternas('.x .y svg > *{fill:red}'));
    }

    /**
     * Guarda da biblioteca: nenhum seletor pode entrar nos ícones sem uma classe que o
     * delimite (senão o sprite seria desligado em todos os sites).
     */
    public function testBibliotecaNaoTemSeletorInternoSemClasse(): void
    {
        $lib = Biblioteca::carregar(SiteExemplo::biblioteca());
        $css = Css::paraPublicacao($lib['baseCss'] . "\n" . implode("\n", array_map(static fn (array $s): string => (string) $s['css'], $lib['secoes'])));
        $exigencias = Sprite::exigenciasInternas(Css::minificar($css));
        $this->assertNotNull($exigencias);
        $this->assertContains(['k-moderno', 'rk-sec--depoimentos', 'dep-estrela'], $exigencias);
    }
}
