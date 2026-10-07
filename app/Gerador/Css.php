<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Preparo\Texto;

/**
 * CSS do site publicado (contrato §7 passo 3, [M16]): base + CSS só das seções usadas +
 * @font-face do par escolhido (com fontes de reserva ajustadas) + paleta; converte
 * `@container site (…)` → `@media (…)` e `cqi` → `vw`, e minifica sem tocar em strings e url().
 */
final class Css
{
    /** Até este tamanho o CSS vai embutido no HTML; acima, arquivo com hash no nome. */
    public const LIMITE_EMBUTIDO = 30 * 1024;

    private const RE_ARQUIVO_FONTE = '/^[a-z0-9][a-z0-9-]*\.woff2$/D';
    private const RE_FAMILIA = '/^[A-Za-z0-9][A-Za-z0-9 ]{0,60}$/D';
    private const RE_PORCENTO = '/^\d{1,3}(\.\d{1,4})?%$/D';
    private const RE_PESO = '/^\d{3}( \d{3,4})?$/D';
    private const RE_UNICODE = '/^U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(,\s*U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/D';

    /** Estilos do próprio gerador: páginas extras [M24], faixa de consentimento [M23] e formulário. */
    private const CSS_GERADOR = <<<'CSS'
body.rk { margin: 0; min-height: 100vh; }
.rk-sec--pagina { padding-block: var(--sec-py); }
.rk-pagina { max-width: 760px; display: grid; gap: 16px; }
.rk-pagina > .rk-h1 { margin-bottom: 4px; }
.rk-pagina h2 { margin-top: 18px; }
.rk-pagina ul { list-style: disc; padding-left: 1.3em; display: grid; gap: 6px; color: var(--fg2); }
.rk-pagina a:not(.rk-btn) { color: var(--destaque); text-decoration: underline; text-underline-offset: 2px; }
.rk-pagina .rk-acoes { margin-top: 8px; }
.rk-pagina__data { font-size: var(--t-small); color: var(--fg2); }
.rk-consent {
  position: fixed; z-index: 90; left: 16px; right: 16px; bottom: 16px;
  max-width: 600px; margin-inline: auto; padding: 18px 20px;
  display: grid; gap: 12px; border-radius: var(--rc);
  font-size: 0.9375rem; line-height: 1.5;
  box-shadow: 0 22px 60px -18px color-mix(in srgb, var(--deep) 70%, transparent);
}
.rk-consent[hidden] { display: none; }
.rk-consent a { text-decoration: underline; text-underline-offset: 2px; }
.rk-consent .rk-btn { min-height: 44px; }
.rk-form__status[data-tipo="erro"] { color: var(--destaque); }
CSS;

    /**
     * CSS final (convertido e minificado).
     *
     * @param list<string> $tipos tipos de seção presentes na página (ordem da página)
     */
    public static function montar(array $lib, array $tipos, string $cssPaleta, string $fonte): string
    {
        $partes = [self::fontes($lib, $fonte)['css'], Texto::textoDe($lib['baseCss'] ?? '')];
        $vistos = [];
        foreach ($tipos as $tipo) {
            if (isset($vistos[$tipo])) {
                continue;
            }
            $vistos[$tipo] = true;
            $css = $lib['secoes'][$tipo]['css'] ?? '';
            if (is_string($css) && $css !== '') {
                $partes[] = $css;
            }
        }
        $partes[] = self::CSS_GERADOR;
        $partes[] = $cssPaleta;
        return self::minificar(self::paraPublicacao(implode("\n", $partes)));
    }

    /**
     * @font-face do par de fontes: arquivos .woff2 (servidos de /fontes/) com
     * font-display: swap, e as famílias "… Reserva" (fontes locais com size-adjust e
     * ascent/descent-override) que evitam o salto de layout enquanto a fonte carrega.
     *
     * @return array{css: string, arquivos: list<string>, preload: ?string}
     */
    public static function fontes(array $lib, string $fonte): array
    {
        $fontesLib = Texto::comoMapa($lib['fontes'] ?? []);
        $par = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar($fontesLib, 'pares')), $fonte));
        $titulos = Texto::textoDe(Texto::pegar($par, 'titulos'));
        $texto = Texto::textoDe(Texto::pegar($par, 'texto'));
        $familias = array_values(array_unique(array_filter([$titulos, $texto], static fn (string $f): bool => $f !== '')));
        $css = '';
        $arquivos = [];
        $preload = null;
        foreach (Texto::comoLista(Texto::pegar($fontesLib, 'arquivos')) as $a) {
            $familia = Texto::textoDe(Texto::pegar($a, 'familia'));
            $arquivo = Texto::textoDe(Texto::pegar($a, 'arquivo'));
            if (!in_array($familia, $familias, true) || !preg_match(self::RE_ARQUIVO_FONTE, $arquivo) || !preg_match(self::RE_FAMILIA, $familia)) {
                continue;
            }
            $peso = Texto::textoDe(Texto::pegar($a, 'peso'));
            $estilo = Texto::textoDe(Texto::pegar($a, 'estilo')) === 'italic' ? 'italic' : 'normal';
            $faixa = Texto::textoDe(Texto::pegar($a, 'unicodeRange'));
            $css .= '@font-face{font-family:"' . $familia . '";font-style:' . $estilo
                . (preg_match(self::RE_PESO, $peso) ? ';font-weight:' . $peso : '')
                . ';font-display:swap;src:url(/fontes/' . $arquivo . ') format("woff2")'
                . (preg_match(self::RE_UNICODE, $faixa) ? ';unicode-range:' . preg_replace('/\s+/', '', $faixa) : '')
                . "}\n";
            $arquivos[] = $arquivo;
            if ($preload === null && $familia === $titulos && $estilo === 'normal') {
                $preload = $arquivo;
            }
        }
        $reservas = Texto::comoMapa(Texto::pegar($fontesLib, 'reserva'));
        foreach ($familias as $familia) {
            $r = Texto::comoMapa(Texto::pegar($reservas, $familia));
            $nome = Texto::textoDe(Texto::pegar($r, 'familia'));
            $locais = array_values(array_filter(
                array_map([Texto::class, 'textoDe'], Texto::comoLista(Texto::pegar($r, 'local'))),
                static fn (string $l): bool => (bool) preg_match(self::RE_FAMILIA, $l),
            ));
            if (!preg_match(self::RE_FAMILIA, $nome) || $locais === []) {
                continue;
            }
            $css .= '@font-face{font-family:"' . $nome . '";src:'
                . implode(',', array_map(static fn (string $l): string => 'local("' . $l . '")', $locais));
            foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $prop) {
                $v = Texto::textoDe(Texto::pegar(Texto::comoMapa(Texto::pegar($r, 'ajustes')), $prop));
                if (preg_match(self::RE_PORCENTO, $v)) {
                    $css .= ';' . $prop . ':' . $v;
                }
            }
            $css .= "}\n";
        }
        return ['css' => $css, 'arquivos' => $arquivos, 'preload' => $preload];
    }

    /**
     * Ajustes da publicação [M16] (fora de strings e url()):
     * `@container site (…)` → `@media (…)`; `cqi`/`cqw` → `vw`; remove `container: …`
     * (sem consultas de contêiner, o contêiner só atrapalharia elementos `position: fixed`).
     */
    public static function paraPublicacao(string $css): string
    {
        return self::porTrechos(self::semComentarios($css), static function (string $codigo): string {
            $codigo = preg_replace('/@container\s+(?:site\s*)?(?=\()/i', '@media ', $codigo) ?? $codigo;
            $codigo = preg_replace('/(?<![\w.-])(-?(?:\d+\.?\d*|\.\d+))cq[iw]\b/i', '$1vw', $codigo) ?? $codigo;
            $codigo = preg_replace('/(?<![\w.-])(-?(?:\d+\.?\d*|\.\d+))cq[hb]\b/i', '$1vh', $codigo) ?? $codigo;
            return preg_replace('/([{;])\s*container(?:-type|-name)?\s*:[^;{}]*(;|(?=\}))/i', '$1', $codigo) ?? $codigo;
        });
    }

    /** Minificação segura: sem comentários, espaços colapsados; strings e url() intactas. */
    public static function minificar(string $css): string
    {
        $min = self::porTrechos(self::semComentarios($css), static function (string $codigo): string {
            $codigo = preg_replace('/\s+/', ' ', $codigo) ?? $codigo;
            $codigo = preg_replace('/\s*([{};,])\s*/', '$1', $codigo) ?? $codigo;
            $codigo = preg_replace('/:\s+/', ':', $codigo) ?? $codigo;
            return str_replace(';}', '}', $codigo);
        });
        return trim($min);
    }

    /** Remove comentários /* … *\/ (respeitando strings), trocando cada um por um espaço. */
    public static function semComentarios(string $css): string
    {
        return preg_replace_callback(
            '~"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|/\*.*?(?:\*/|$)~s',
            static fn (array $m): string => str_starts_with($m[0], '/*') ? ' ' : $m[0],
            $css,
        ) ?? $css;
    }

    /** Aplica $fn só aos trechos de código (fora de strings e de url(...) sem aspas). */
    private static function porTrechos(string $css, callable $fn): string
    {
        $partes = preg_split(
            '~("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|\burl\(\s*(?!["\'])[^)]*\))~i',
            $css,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($partes === false) {
            return $css;
        }
        $saida = '';
        foreach ($partes as $i => $parte) {
            $saida .= $i % 2 === 0 ? $fn($parte) : $parte;
        }
        return $saida;
    }

    /**
     * Remove regras cujos seletores dependem de classes que não aparecem em nenhuma página
     * (opções e acabamentos não usados, por exemplo). Conservador: só conta as classes fora de
     * parênteses e colchetes (:not(), :is(), :where() e [atributos] nunca derrubam um seletor);
     * @font-face, @keyframes e outras regras com @ ficam como estão, exceto @media/@supports,
     * que são podadas por dentro.
     *
     * @param array<string, true> $classes classes usadas (HTML de todas as páginas + script)
     */
    public static function podar(string $css, array $classes): string
    {
        return self::podarBloco($css, $classes);
    }

    /** Classes presentes em atributos class="…" de um HTML. @return array<string, true> */
    public static function classesDoHtml(string $html): array
    {
        $r = [];
        preg_match_all('/\sclass="([^"]*)"/', $html, $m);
        foreach ($m[1] as $lista) {
            foreach (preg_split('/\s+/', trim($lista)) ?: [] as $c) {
                if ($c !== '') {
                    $r[$c] = true;
                }
            }
        }
        return $r;
    }

    private static function podarBloco(string $css, array $classes): string
    {
        $saida = '';
        $n = strlen($css);
        $i = 0;
        while ($i < $n) {
            [$preludio, $fim, $marca] = self::lerAte($css, $i, ['{', ';', '}']);
            $preludio = trim($preludio);
            if ($marca === ';' || $marca === null) {
                if ($preludio !== '') {
                    $saida .= $preludio . ($marca === ';' ? ';' : '');
                }
                $i = $fim + 1;
                continue;
            }
            if ($marca === '}') {
                $i = $fim + 1; // chave solta: ignora
                continue;
            }
            $fechamento = self::fecharBloco($css, $fim);
            $corpo = substr($css, $fim + 1, $fechamento - $fim - 1);
            $i = $fechamento + 1;
            if (preg_match('/^@(media|supports|layer|container)\b/i', $preludio)) {
                $interno = self::podarBloco($corpo, $classes);
                if ($interno !== '') {
                    $saida .= $preludio . '{' . $interno . '}';
                }
            } elseif (str_starts_with($preludio, '@')) {
                $saida .= $preludio . '{' . $corpo . '}';
            } else {
                $seletores = array_filter(self::dividirSeletores($preludio), static fn (string $s): bool => self::seletorUsado($s, $classes));
                if ($seletores !== []) {
                    $saida .= implode(',', $seletores) . '{' . $corpo . '}';
                }
            }
        }
        return $saida;
    }

    /** Lê a partir de $i até um dos caracteres $marcas no nível 0 (fora de strings, (), []). */
    private static function lerAte(string $css, int $i, array $marcas): array
    {
        $n = strlen($css);
        $nivel = 0;
        $inicio = $i;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $i = self::fimString($css, $i);
            } elseif ($c === '(' || $c === '[') {
                $nivel++;
            } elseif ($c === ')' || $c === ']') {
                $nivel = max(0, $nivel - 1);
            } elseif ($nivel === 0 && in_array($c, $marcas, true)) {
                return [substr($css, $inicio, $i - $inicio), $i, $c];
            }
            $i++;
        }
        return [substr($css, $inicio), $n, null];
    }

    /** Posição da "}" que fecha o bloco aberto em $abre. */
    private static function fecharBloco(string $css, int $abre): int
    {
        $n = strlen($css);
        $nivel = 0;
        for ($i = $abre; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $i = self::fimString($css, $i);
            } elseif ($c === '{') {
                $nivel++;
            } elseif ($c === '}' && --$nivel === 0) {
                return $i;
            }
        }
        return $n;
    }

    private static function fimString(string $css, int $i): int
    {
        $aspas = $css[$i];
        $n = strlen($css);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($css[$j] === '\\') {
                $j++;
            } elseif ($css[$j] === $aspas || $css[$j] === "\n") {
                return $j;
            }
        }
        return $n - 1;
    }

    /** @return list<string> seletores de uma lista (vírgulas de nível 0) */
    private static function dividirSeletores(string $lista): array
    {
        $r = [];
        $resto = $lista;
        while ($resto !== '') {
            [$sel, $fim, $marca] = self::lerAte($resto, 0, [',']);
            $r[] = trim($sel);
            $resto = $marca === null ? '' : substr($resto, $fim + 1);
        }
        return array_values(array_filter($r, static fn (string $s): bool => $s !== ''));
    }

    /** Todas as classes exigidas pelo seletor (fora de parênteses/colchetes) existem? */
    private static function seletorUsado(string $seletor, array $classes): bool
    {
        $sem = '';
        $nivel = 0;
        $n = strlen($seletor);
        for ($i = 0; $i < $n; $i++) {
            $c = $seletor[$i];
            if ($c === '"' || $c === "'") {
                $i = self::fimString($seletor, $i);
                continue;
            }
            if ($c === '(' || $c === '[') {
                $nivel++;
            } elseif ($c === ')' || $c === ']') {
                $nivel = max(0, $nivel - 1);
            } elseif ($nivel === 0) {
                $sem .= $c;
            }
        }
        if (str_contains($sem, '\\')) {
            return true; // seletor com escape: não arrisca
        }
        preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $sem, $m);
        foreach ($m[1] as $classe) {
            if (!isset($classes[$classe])) {
                return false;
            }
        }
        return true;
    }

    /** Nome do arquivo externo: assets/site.{hash8}.css */
    public static function nomeArquivo(string $css): string
    {
        return 'assets/site.' . substr(hash('sha256', $css), 0, 8) . '.css';
    }
}
