<?php

declare(strict_types=1);

namespace Rankly\Gerador;

/**
 * Ícones repetidos viram um "sprite" no início da página (contrato §7; meta de peso do PDF §9.5).
 * Cada SVG embutido cujo conteúdo (viewBox + desenho) aparece duas ou mais vezes passa a ser
 * <svg …atributos originais…><use href="#rk-iN"/></svg>, e o desenho fica uma vez só num
 * <symbol>. Os atributos de apresentação do <svg> externo (fill, stroke, class, aria-hidden)
 * continuam no lugar e são herdados pelo desenho.
 *
 * Cuidado com o CSS: seletores do documento não alcançam o desenho dentro de um <use> (árvore
 * sombra). Por isso o CSS final da página é lido e todo SVG que possa ser alvo de uma regra que
 * entra no ícone (ex.: `.dep-estrela path[opacity]`, `.x svg > *`) fica embutido como está.
 * A verificação é conservadora: basta o SVG ter entre os ancestrais todas as classes do trecho
 * do seletor antes do elemento interno. Seletor interno sem nenhuma classe desliga o sprite.
 *
 * Só entra no sprite o SVG "simples": sem id, href, <use>, <style>, <script>, <foreignObject>
 * ou outro <svg> no desenho (referências internas quebrariam ao virar símbolo).
 */
final class Sprite
{
    private const RE_SVG = '#<svg\b([^>]*)>(.*?)</svg>#s';
    private const VAZIOS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    /** Elementos de desenho que um seletor pode mirar dentro de um ícone. */
    private const RE_INTERNO = '/(?:^|[\s>+~(,])(?:svg\s*[>+~]|svg\s+(?=[^\s{,)])|(?:path|circle|rect|line|polyline|polygon|ellipse|g|text|tspan|use)(?=$|[\[.:#\s>+~),]))/i';

    /**
     * @param string $html corpo da página
     * @param string $css CSS final da página (para achar regras que entram nos ícones)
     * @param list<string> $classesRaiz classes do <body> (ancestral de tudo)
     */
    public static function aplicar(string $html, string $css = '', array $classesRaiz = []): string
    {
        $exigencias = self::exigenciasInternas($css);
        if ($exigencias === null) {
            return $html;
        }
        // Posição de cada <svg> → classes dos ancestrais (pilha de tags abertas).
        $protegidos = $exigencias === [] ? [] : self::svgsProtegidos($html, $exigencias, $classesRaiz);

        if (!preg_match_all(self::RE_SVG, $html, $todos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $contagem = [];
        foreach ($todos as $m) {
            $chave = isset($protegidos[$m[0][1]]) ? null : self::chave($m[1][0], $m[2][0]);
            if ($chave !== null) {
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            }
        }
        $ids = [];
        $simbolos = '';
        foreach ($contagem as $chave => $n) {
            if ($n < 2) {
                continue;
            }
            $id = 'rk-i' . (count($ids) + 1);
            $ids[$chave] = $id;
            [$viewBox, $desenho] = explode("\n", $chave, 2);
            $simbolos .= '<symbol id="' . $id . '"' . ($viewBox !== '' ? ' viewBox="' . $viewBox . '"' : '') . '>' . $desenho . '</symbol>';
        }
        if ($ids === []) {
            return $html;
        }
        $saida = '';
        $pos = 0;
        foreach ($todos as $m) {
            [$inteiro, $inicio] = $m[0];
            $chave = isset($protegidos[$inicio]) ? null : self::chave($m[1][0], $m[2][0]);
            if ($chave === null || !isset($ids[$chave])) {
                continue;
            }
            $saida .= substr($html, $pos, $inicio - $pos) . '<svg' . $m[1][0] . '><use href="#' . $ids[$chave] . '"/></svg>';
            $pos = $inicio + strlen($inteiro);
        }
        $saida .= substr($html, $pos);
        $sprite = '<svg aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">' . $simbolos . '</svg>';
        return $sprite . "\n" . $saida;
    }

    /**
     * Para cada seletor do CSS que entra num ícone, as classes exigidas antes do elemento
     * interno. null = algum seletor interno sem classe (não dá para saber quem ele atinge).
     *
     * @return list<list<string>>|null
     */
    public static function exigenciasInternas(string $css): ?array
    {
        $css = Css::semComentarios($css);
        $r = [];
        preg_match_all('/([^{};]+)\{/', $css, $m);
        foreach ($m[1] as $lista) {
            $lista = trim($lista);
            if ($lista === '' || $lista[0] === '@') {
                continue;
            }
            foreach (explode(',', $lista) as $seletor) {
                $seletor = trim($seletor);
                if (!preg_match(self::RE_INTERNO, $seletor, $achado, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $antes = substr($seletor, 0, $achado[0][1]);
                preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $antes, $c);
                if ($c[1] === []) {
                    return null;
                }
                $r[] = array_values(array_unique($c[1]));
            }
        }
        return $r;
    }

    /**
     * Offsets dos <svg> cujos ancestrais têm todas as classes de alguma exigência.
     *
     * @param list<list<string>> $exigencias
     * @param list<string> $classesRaiz
     * @return array<int, true>
     */
    private static function svgsProtegidos(string $html, array $exigencias, array $classesRaiz): array
    {
        $protegidos = [];
        $pilha = [['', $classesRaiz]];
        preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^<>]*)?)>#', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $t) {
            $fecha = $t[1][0] === '/';
            $nome = strtolower($t[2][0]);
            if ($fecha) {
                for ($i = count($pilha) - 1; $i > 0; $i--) {
                    if ($pilha[$i][0] === $nome) {
                        array_splice($pilha, $i);
                        break;
                    }
                }
                continue;
            }
            $atributos = $t[3][0];
            if ($nome === 'svg') {
                $ancestrais = array_merge(...array_map(static fn (array $p): array => $p[1], $pilha));
                $ancestrais = array_flip($ancestrais);
                foreach ($exigencias as $classes) {
                    if (array_diff_key(array_flip($classes), $ancestrais) === []) {
                        $protegidos[$t[0][1]] = true;
                        break;
                    }
                }
            }
            if (in_array($nome, self::VAZIOS, true) || str_ends_with(rtrim($atributos), '/')) {
                continue;
            }
            $classes = preg_match('/\sclass="([^"]*)"/', $atributos, $c) ? (preg_split('/\s+/', trim($c[1])) ?: []) : [];
            $pilha[] = [$nome, $classes];
        }
        return $protegidos;
    }

    /** "viewBox\ndesenho" para SVGs que podem virar símbolo; null para os demais. */
    private static function chave(string $atributos, string $desenho): ?string
    {
        if ($desenho === '' || preg_match('/\sid=|href=|<use\b|<style\b|<script\b|<foreignObject\b|<svg\b/i', $desenho)) {
            return null;
        }
        $viewBox = preg_match('/\sviewBox="([^"]*)"/', $atributos, $m) ? $m[1] : '';
        return $viewBox . "\n" . $desenho;
    }
}
