<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Limpeza de SVG de logo [M26]. Recusa o que pode executar código ou buscar recursos
 * externos (script, foreignObject, atributos on*, href externo/javascript:, DOCTYPE/ENTITY);
 * remove o que é só ruído de editores (metadados, sodipodi/inkscape, comentários, animações).
 * O resultado é servido sempre como imagem e com CSP script-src 'none'.
 */
final class Svg
{
    public const MAX_BYTES = 1024 * 1024;
    private const NS_SVG = 'http://www.w3.org/2000/svg';
    private const NS_XLINK = 'http://www.w3.org/1999/xlink';
    private const NS_XML = 'http://www.w3.org/XML/1998/namespace';

    /** Elementos que tornam o arquivo inaceitável. */
    private const PROIBIDOS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener', 'audio', 'video',
        'canvas', 'meta', 'link', 'base', 'form', 'input', 'button', 'textarea', 'html', 'body',
    ];

    /** Elementos SVG mantidos (o resto é removido). */
    private const PERMITIDOS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'title', 'desc', 'lineargradient', 'radialgradient', 'stop', 'clippath',
        'mask', 'pattern', 'image', 'filter', 'marker', 'style', 'switch', 'fegaussianblur', 'feoffset',
        'feblend', 'fecolormatrix', 'fecomposite', 'feflood', 'femerge', 'femergenode', 'femorphology',
        'fedropshadow', 'fecomponenttransfer', 'fefunca', 'fefuncr', 'fefuncg', 'fefuncb', 'feturbulence',
        'fedisplacementmap', 'feimage', 'fetile', 'feconvolvematrix', 'fediffuselighting', 'fespecularlighting',
        'fedistantlight', 'fepointlight', 'fespotlight',
    ];

    /**
     * Valida e limpa. Devolve o SVG limpo e as dimensões intrínsecas.
     *
     * @return array{svg: string, largura: int, altura: int}
     * @throws \InvalidArgumentException com mensagem em português para o usuário
     */
    public static function sanitizar(string $conteudo): array
    {
        if (strlen($conteudo) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('O logo em SVG pode ter no máximo 1 MB.');
        }
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $conteudo)) {
            throw new \InvalidArgumentException('O SVG contém declarações (DOCTYPE/ENTITY) que não são aceitas. Exporte de novo ou envie o logo em PNG.');
        }
        $dom = new \DOMDocument();
        $antes = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadXML($conteudo, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($antes);
        }
        $raiz = $ok ? $dom->documentElement : null;
        if ($raiz === null || strtolower($raiz->localName ?? '') !== 'svg') {
            throw new \InvalidArgumentException('Arquivo SVG inválido. Envie o logo em PNG ou JPG.');
        }
        if ($dom->doctype !== null) {
            throw new \InvalidArgumentException('O SVG contém declarações (DOCTYPE/ENTITY) que não são aceitas.');
        }

        // Comentários e instruções de processamento saem.
        $xp = new \DOMXPath($dom);
        foreach (iterator_to_array($xp->query('//comment() | //processing-instruction()') ?: []) as $no) {
            $no->parentNode?->removeChild($no);
        }

        self::limparElemento($raiz);

        if (!$raiz->hasAttribute('xmlns') && $raiz->namespaceURI !== self::NS_SVG) {
            $raiz->setAttribute('xmlns', self::NS_SVG);
        }
        [$largura, $altura] = self::dimensoes($raiz);
        if (!$raiz->hasAttribute('viewBox') && !$raiz->hasAttribute('viewbox')) {
            $raiz->setAttribute('viewBox', "0 0 {$largura} {$altura}");
        }
        $svg = $dom->saveXML($raiz);
        if (!is_string($svg) || $svg === '') {
            throw new \InvalidArgumentException('Arquivo SVG inválido.');
        }
        return ['svg' => $svg, 'largura' => $largura, 'altura' => $altura];
    }

    /** O conteúdo parece um SVG (para aceitar mesmo quando o finfo diz text/xml)? */
    public static function pareceSvg(string $conteudo): bool
    {
        $inicio = substr($conteudo, 0, 4096);
        $inicio = preg_replace('/^\xEF\xBB\xBF/', '', $inicio) ?? $inicio;
        $inicio = preg_replace('/<\?xml.*?\?>|<!--.*?-->|\s+/s', '', $inicio) ?? $inicio;
        return stripos($inicio, '<svg') !== false;
    }

    private static function limparElemento(\DOMElement $el): void
    {
        $nome = strtolower($el->localName ?? '');
        if (in_array($nome, self::PROIBIDOS, true)) {
            throw new \InvalidArgumentException('O SVG contém scripts ou conteúdo incorporado, que não são aceitos. Envie o logo em PNG.');
        }
        self::limparAtributos($el);
        if ($nome === 'style') {
            self::conferirCss($el->textContent);
        }
        // Filhos por índice: <a> é desembrulhado e seus filhos passam pelo mesmo crivo.
        $i = 0;
        while ($i < $el->childNodes->length) {
            $filho = $el->childNodes->item($i);
            if (!$filho instanceof \DOMElement) {
                $i++;
                continue;
            }
            $nomeFilho = strtolower($filho->localName ?? '');
            $nsFilho = $filho->namespaceURI;
            if (in_array($nomeFilho, self::PROIBIDOS, true)) {
                throw new \InvalidArgumentException('O SVG contém scripts ou conteúdo incorporado, que não são aceitos. Envie o logo em PNG.');
            }
            $ehSvg = $nsFilho === null || $nsFilho === self::NS_SVG;
            if ($ehSvg && $nomeFilho === 'a') {
                self::limparAtributos($filho); // href javascript: no link também recusa o arquivo
                while ($filho->firstChild !== null) {
                    $el->insertBefore($filho->firstChild, $filho);
                }
                $el->removeChild($filho);
                continue; // os filhos movidos ocupam a posição $i
            }
            if (!$ehSvg || !in_array($nomeFilho, self::PERMITIDOS, true)) {
                self::conferirSubarvore($filho);
                $el->removeChild($filho);
                continue;
            }
            self::limparElemento($filho);
            $i++;
        }
    }

    /** Mesmo o que será removido não pode esconder script (sinal de arquivo malicioso). */
    private static function conferirSubarvore(\DOMElement $el): void
    {
        $nome = strtolower($el->localName ?? '');
        if (in_array($nome, self::PROIBIDOS, true)) {
            throw new \InvalidArgumentException('O SVG contém scripts ou conteúdo incorporado, que não são aceitos. Envie o logo em PNG.');
        }
        foreach ($el->attributes ?? [] as $attr) {
            if (str_starts_with(strtolower($attr->localName ?? $attr->nodeName), 'on')) {
                throw new \InvalidArgumentException('O SVG contém atributos de evento (on…), que não são aceitos. Envie o logo em PNG.');
            }
        }
        foreach ($el->childNodes as $filho) {
            if ($filho instanceof \DOMElement) {
                self::conferirSubarvore($filho);
            }
        }
    }

    private static function limparAtributos(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            /** @var \DOMAttr $attr */
            $nome = strtolower($attr->localName ?? $attr->nodeName);
            $ns = $attr->namespaceURI;
            $valor = (string) $attr->value;
            if (str_starts_with($nome, 'on')) {
                throw new \InvalidArgumentException('O SVG contém atributos de evento (on…), que não são aceitos. Envie o logo em PNG.');
            }
            $compacto = strtolower(preg_replace('/[\x00-\x20]+/', '', $valor) ?? '');
            if (str_contains($compacto, 'javascript:') || str_contains($compacto, 'vbscript:') || str_contains($compacto, 'data:text/html')) {
                throw new \InvalidArgumentException('O SVG contém links com javascript:, que não são aceitos. Envie o logo em PNG.');
            }
            if ($nome === 'href' || $nome === 'src') {
                if (!self::referenciaInterna($valor)) {
                    throw new \InvalidArgumentException('O SVG aponta para arquivos externos, o que não é aceito. Envie o logo em PNG.');
                }
                continue;
            }
            if ($ns !== null && $ns !== self::NS_XLINK && $ns !== self::NS_XML && $ns !== self::NS_SVG) {
                $el->removeAttributeNode($attr); // sodipodi:*, inkscape:* …
                continue;
            }
            if ($nome === 'style' || str_contains($compacto, 'url(')) {
                self::conferirCss($valor);
            }
        }
    }

    /** href aceito: âncora interna (#id) ou imagem embutida em base64. */
    private static function referenciaInterna(string $valor): bool
    {
        $v = trim($valor);
        return str_starts_with($v, '#')
            || (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#Di', $v);
    }

    private static function conferirCss(string $css): void
    {
        $c = strtolower($css);
        if (str_contains($c, '@import') || str_contains($c, 'expression(') || str_contains($c, 'javascript:') || str_contains($c, 'behavior:')) {
            throw new \InvalidArgumentException('O SVG contém estilos que carregam conteúdo externo, o que não é aceito. Envie o logo em PNG.');
        }
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', $css, $m)) {
            foreach ($m[2] as $alvo) {
                if (!self::referenciaInterna($alvo)) {
                    throw new \InvalidArgumentException('O SVG aponta para arquivos externos, o que não é aceito. Envie o logo em PNG.');
                }
            }
        }
    }

    /** @return array{int, int} largura e altura intrínsecas (width/height em px, ou viewBox; padrão 300×150) */
    private static function dimensoes(\DOMElement $raiz): array
    {
        $num = static function (string $v): ?float {
            return preg_match('/^\s*([0-9]*\.?[0-9]+)\s*(px)?\s*$/i', $v, $m) ? (float) $m[1] : null;
        };
        $w = $num($raiz->getAttribute('width'));
        $h = $num($raiz->getAttribute('height'));
        $vb = $raiz->getAttribute('viewBox') ?: $raiz->getAttribute('viewbox');
        $partes = preg_split('/[\s,]+/', trim($vb)) ?: [];
        $vw = count($partes) === 4 && is_numeric($partes[2]) ? (float) $partes[2] : null;
        $vh = count($partes) === 4 && is_numeric($partes[3]) ? (float) $partes[3] : null;
        if ($w === null && $h !== null && $vw && $vh) {
            $w = $h * $vw / $vh;
        } elseif ($h === null && $w !== null && $vw && $vh) {
            $h = $w * $vh / $vw;
        }
        $w ??= $vw ?? 300.0;
        $h ??= $vh ?? 150.0;
        $limitar = static fn (float $x): int => max(1, min(10000, (int) floor($x + 0.5)));
        return [$limitar($w), $limitar($h)];
    }
}
