<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rankly\Lib\Svg;

final class SvgTest extends TestCase
{
    public function testSvgLimpoEAceitoComDimensoes(): void
    {
        $svg = '<?xml version="1.0"?><!-- logo --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80">'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#c23b6e"/></linearGradient></defs>'
            . '<rect width="200" height="80" fill="url(#g)"/><text x="10" y="50" style="font-weight:700">Sorriso</text></svg>';
        $r = Svg::sanitizar($svg);
        $this->assertSame(200, $r['largura']);
        $this->assertSame(80, $r['altura']);
        $this->assertStringStartsWith('<svg', $r['svg']);
        $this->assertStringNotContainsString('<!--', $r['svg']);
        $this->assertStringContainsString('url(#g)', $r['svg']);
    }

    public function testRemoveRuidoDeEditores(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"'
            . ' xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" width="120px" height="60" inkscape:version="1.2">'
            . '<metadata><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></metadata>'
            . '<sodipodi:namedview id="n"/><a href="#x"><circle cx="5" cy="5" r="5" inkscape:label="c"/></a>'
            . '<animate attributeName="r" values="1;5"/></svg>';
        $r = Svg::sanitizar($svg);
        $this->assertSame([120, 60], [$r['largura'], $r['altura']]);
        $this->assertStringNotContainsString('metadata', $r['svg']);
        $this->assertStringNotContainsString('namedview', $r['svg']);
        $this->assertStringNotContainsString('inkscape:label', $r['svg']);
        $this->assertStringNotContainsString('<animate', $r['svg']);
        $this->assertStringNotContainsString('<a ', $r['svg']);
        $this->assertStringContainsString('<circle', $r['svg'], 'O desenho dentro do link é mantido');
        $this->assertStringContainsString('viewBox="0 0 120 60"', $r['svg']);
    }

    public function testSvgSemXmlnsGanhaNamespace(): void
    {
        $r = Svg::sanitizar('<svg width="10" height="10"><rect width="10" height="10"/></svg>');
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $r['svg']);
    }

    /** @return iterable<string, array{string}> */
    public static function maliciosos(): iterable
    {
        $ns = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';
        yield 'script' => ["<svg {$ns}><script>alert(1)</script></svg>"];
        yield 'script em maiúsculas' => ["<svg {$ns}><SCRIPT>alert(1)</SCRIPT></svg>"];
        yield 'script escondido em metadata' => ["<svg {$ns}><metadata><script>alert(1)</script></metadata></svg>"];
        yield 'foreignObject' => ["<svg {$ns}><foreignObject><div xmlns=\"http://www.w3.org/1999/xhtml\">x</div></foreignObject></svg>"];
        yield 'onload' => ["<svg {$ns} onload=\"alert(1)\"><rect/></svg>"];
        yield 'onclick em filho' => ["<svg {$ns}><g><rect onClick=\"alert(1)\"/></g></svg>"];
        yield 'href javascript' => ["<svg {$ns}><a href=\"javascript:alert(1)\"><rect/></a></svg>"];
        yield 'xlink javascript com espaços' => ["<svg {$ns}><a xlink:href=\" java\tscript:alert(1)\"><rect/></a></svg>"];
        yield 'image externa' => ["<svg {$ns}><image href=\"https://evil.example/x.png\"/></svg>"];
        yield 'use externo' => ["<svg {$ns}><use xlink:href=\"https://evil.example/s.svg#a\"/></svg>"];
        yield 'css import' => ["<svg {$ns}><style>@import url(https://evil.example/a.css);</style></svg>"];
        yield 'css url externa' => ["<svg {$ns}><rect style=\"fill:url(https://evil.example/a)\"/></svg>"];
        yield 'doctype com entidade' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY x SYSTEM \"file:///etc/passwd\">]><svg {$ns}><text>&x;</text></svg>"];
        yield 'iframe' => ["<svg {$ns}><iframe src=\"https://evil.example\"/></svg>"];
        yield 'não é svg' => ['<html><body>oi</body></html>'];
        yield 'xml quebrado' => ['<svg><rect></svg>'];
    }

    #[DataProvider('maliciosos')]
    public function testSvgMaliciosoERecusado(string $svg): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Svg::sanitizar($svg);
    }

    public function testImagemEmbutidaBase64EAceita(): void
    {
        $png = base64_encode((string) file_get_contents(__FILE__, false, null, 0, 10));
        $r = Svg::sanitizar('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">'
            . '<image xlink:href="data:image/png;base64,' . $png . '" width="10" height="10"/></svg>');
        $this->assertStringContainsString('data:image/png;base64', $r['svg']);
    }

    public function testTamanhoMaximo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Svg::sanitizar('<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat(' ', Svg::MAX_BYTES) . '</svg>');
    }
}
