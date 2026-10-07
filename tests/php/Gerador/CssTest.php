<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\Css;
use Rankly\Preparo\Biblioteca;

final class CssTest extends TestCase
{
    public function testConverteContainerECqiParaPublicacao(): void
    {
        $css = ".rk {\n  container: site / inline-size;\n  --t: clamp(1rem, 0.9rem + 4cqi, 2rem);\n  width: min(320px, 100cqi - 2 * var(--gut));\n}\n"
            . "@container site (max-width: 760px) { .a { gap: 2cqi; } }\n"
            . ".b::after { content: \"2cqi @container site (x)\"; }";
        $r = Css::paraPublicacao($css);
        $this->assertStringContainsString('@media (max-width: 760px)', $r);
        $this->assertStringNotContainsString('@container site (max', $r);
        $this->assertStringContainsString('0.9rem + 4vw', $r);
        $this->assertStringContainsString('100vw - 2 * var(--gut)', $r);
        $this->assertStringContainsString('gap: 2vw', $r);
        $this->assertStringNotContainsString('container:', $r, 'a declaração container sai (sem consultas, só atrapalharia position: fixed)');
        $this->assertStringContainsString('content: "2cqi @container site (x)"', $r, 'strings ficam intactas');
    }

    public function testMinificaSemQuebrarStringsUrlsECalc(): void
    {
        $css = "/* comentário */\n.a  >  .b ,\n.c :where(h1, h2) {\n  font-family: \"Libre Caslon Text\", serif ;\n"
            . "  content: \"a  /* não é comentário */  b\";\n  background: url( /img/x y.png ) no-repeat;\n"
            . "  width: calc(100% - 2 * var(--gut));\n}\n@media (max-width: 760px) and (min-width: 1px) { .d { color: red; } }";
        $m = Css::minificar($css);
        $this->assertStringNotContainsString('comentário */', explode('content', $m)[0]);
        $this->assertStringContainsString('"a  /* não é comentário */  b"', $m);
        $this->assertStringContainsString('url( /img/x y.png ) no-repeat', $m);
        $this->assertStringContainsString('calc(100% - 2 * var(--gut))', $m);
        $this->assertStringContainsString('.c :where(h1,h2)', $m, 'o espaço antes de :where é combinador e fica');
        $this->assertStringContainsString('font-family:"Libre Caslon Text",serif', $m);
        $this->assertStringContainsString('@media (max-width:760px) and (min-width:1px){.d{color:red}}', $m);
        $this->assertStringNotContainsString(";}", $m);
    }

    public function testPodaSeletoresDeClassesQueNaoAparecem(): void
    {
        $css = Css::minificar(
            '.rk{color:red}.k-classico .rk-btn{a:1}.k-moderno .rk-btn,.k-direto .rk-btn{b:2}'
            . '.rk-form[data-estado="ok"]>:not(.rk-form__ok){display:none}:where(.rk){--p:#000}'
            . '@media (max-width:760px){.serv-bloco{c:3}.rk-btn{d:4}}@media (max-width:1060px){.nada{e:5}}'
            . '@keyframes giro{from{opacity:0}to{opacity:1}}@font-face{font-family:"X";src:url(/fontes/x.woff2)}'
            . '.a:is(.nao-existe,.rk) .rk-btn{f:6}'
        );
        $classes = ['rk' => true, 'k-moderno' => true, 'rk-btn' => true, 'rk-form' => true];
        $p = Css::podar($css, $classes);
        $this->assertStringContainsString('.rk{color:red}', $p);
        $this->assertStringNotContainsString('k-classico', $p);
        $this->assertStringContainsString('.k-moderno .rk-btn{b:2}', $p, 'da lista, só o seletor usado fica');
        $this->assertStringContainsString(':not(.rk-form__ok)', $p, 'classe dentro de :not() não derruba');
        $this->assertStringContainsString(':where(.rk){--p:#000}', $p);
        $this->assertStringContainsString('@media (max-width:760px){.rk-btn{d:4}}', $p);
        $this->assertStringNotContainsString('1060px', $p, '@media vazio sai');
        $this->assertStringContainsString('@keyframes giro{from{opacity:0}to{opacity:1}}', $p);
        $this->assertStringContainsString('@font-face{font-family:"X";src:url(/fontes/x.woff2)}', $p);
        $this->assertStringNotContainsString('.a:is', $p, '.a fora dos parênteses não existe');
    }

    public function testFontesDoParComReservaESwap(): void
    {
        $lib = Biblioteca::carregar(SiteExemplo::biblioteca());
        $f = Css::fontes($lib, 'classica');
        $this->assertContains('libre-caslon-text-latin-400-normal.woff2', $f['arquivos']);
        $this->assertContains('source-sans-3-latin-wght-normal.woff2', $f['arquivos']);
        $this->assertSame('libre-caslon-text-latin-400-normal.woff2', $f['preload']);
        $this->assertStringContainsString('font-display:swap', $f['css']);
        $this->assertStringContainsString('src:url(/fontes/source-sans-3-latin-wght-normal.woff2) format("woff2")', $f['css']);
        $this->assertStringContainsString('unicode-range:U+0000-00FF', $f['css']);
        $this->assertMatchesRegularExpression('/font-family:"Libre Caslon Text Reserva";src:local\("Times New Roman"\)[^}]*size-adjust:\d/', $f['css']);
        $this->assertStringContainsString('ascent-override:', $f['css']);
        $this->assertStringNotContainsString('Manrope', $f['css'], 'só o par escolhido');
    }

    public function testMontarUsaSoOsTiposDaPagina(): void
    {
        $lib = Biblioteca::carregar(SiteExemplo::biblioteca());
        $css = Css::montar($lib, ['header', 'rodape'], '.rk{--p:#c23b6e}', 'moderna');
        $this->assertStringContainsString('.rk{--p:#c23b6e}', $css);
        $this->assertStringContainsString('.rk-sec--header', $css);
        $this->assertStringNotContainsString('.rk-sec--servicos', $css);
        $this->assertStringNotContainsString('@container', $css);
        $this->assertDoesNotMatchRegularExpression('/\dcqi\b/', $css);
        $this->assertStringContainsString('@font-face{font-family:"Manrope"', $css);
        $this->assertStringStartsWith('assets/site.', Css::nomeArquivo($css));
        $this->assertMatchesRegularExpression('#^assets/site\.[0-9a-f]{8}\.css$#', Css::nomeArquivo($css));
    }

    public function testClassesDoHtml(): void
    {
        $this->assertSame(['a' => true, 'b-c' => true, 'd' => true], Css::classesDoHtml('<div class="a  b-c"><span class="d a"></span><p data-class="x"></p></div>'));
    }
}
