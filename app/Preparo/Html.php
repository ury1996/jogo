<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Pedaços de HTML gerados pelo preparo: imagem, logo, invólucro e botão flutuante (§5.4, §5.5).
 * PARIDADE OBRIGATÓRIA com imagemHtml/logoHtml/involucro/botaoWhatsapp de preparo.mjs.
 */
final class Html
{
    /** Opções de prepararSite com padrões: [modo, urlMidia, midia, ano]. */
    public static function normalizarOpcoes(mixed $opcoes): array
    {
        $o = Texto::comoMapa($opcoes);
        $modo = Texto::pegar($o, 'modo') === 'publicar' ? 'publicar' : 'editor';
        $url = Texto::pegar($o, 'urlMidia');
        return [
            'modo' => $modo,
            'urlMidia' => is_string($url) && $url !== '' ? $url : ($modo === 'publicar' ? 'img/{id}-{w}.{ext}' : '/api/media/{id}/{w}'),
            'midia' => Texto::comoMapa(Texto::pegar($o, 'midia')),
            'ano' => Texto::inteiroDe(Texto::pegar($o, 'ano')),
        ];
    }

    /** Troca {id} {w} {ext} no modelo de URL (uma passada). */
    public static function urlMidia(string $modelo, string $id, int|string $w, string $ext): string
    {
        return strtr($modelo, ['{id}' => $id, '{w}' => (string) $w, '{ext}' => $ext]);
    }

    private static function inteiro(mixed $v): int
    {
        return Texto::inteiroDe($v) ?? 0;
    }

    /** @return list<int> */
    private static function larguras(mixed $variantes): array
    {
        $r = [];
        foreach (Texto::comoLista($variantes) as $v) {
            $w = Texto::inteiroDe($v);
            if ($w !== null && $w > 0 && !in_array($w, $r, true)) {
                $r[] = $w;
            }
        }
        sort($r, SORT_NUMERIC);
        return $r;
    }

    private static function maiorAte(array $lista, int $limite): ?int
    {
        $r = null;
        foreach ($lista as $w) {
            if ($w <= $limite) {
                $r = $w;
            }
        }
        return $r;
    }

    private static function midiaDe(array $op, mixed $id): ?array
    {
        if (!is_string($id) || $id === '') {
            return null;
        }
        $m = Texto::pegar($op['midia'], $id);
        return Texto::ehMapa($m) ? $m : null;
    }

    private static function vazio(mixed $rotulo, string $modo): string
    {
        return $modo === 'editor'
            ? '<span class="rk-foto__vazio">' . Texto::escapeHtml(Texto::textoDe($rotulo)) . ' · enviar</span>'
            : '<span class="rk-foto__vazio" aria-hidden="true"></span>';
    }

    /**
     * HTML de uma imagem (§5.5) → [html, vazio].
     * $entrada = [chave, midiaId, rotulo, alt, sizes, lcp]; $opcoes = as de prepararSite.
     *
     * @return array{html: string, vazio: bool}
     */
    public static function imagem(array $entrada, mixed $opcoes): array
    {
        $op = self::normalizarOpcoes($opcoes);
        $id = Texto::textoDe(Texto::pegar($entrada, 'midiaId'));
        $m = self::midiaDe($op, $id);
        if ($m === null) {
            return ['html' => self::vazio(Texto::pegar($entrada, 'rotulo'), $op['modo']), 'vazio' => true];
        }
        $altMidia = Texto::pegar($m, 'alt');
        $alt = Texto::escapeHtml(Texto::colapsarEspacos($altMidia) !== '' ? Texto::textoDe($altMidia) : Texto::textoDe(Texto::pegar($entrada, 'alt')));
        $lcp = Texto::pegar($entrada, 'lcp') === true;
        $carregamento = 'loading="' . ($lcp ? 'eager' : 'lazy') . '" decoding="async"' . ($lcp ? ' fetchpriority="high"' : '');
        $largura = self::inteiro(Texto::pegar($m, 'largura'));
        $altura = self::inteiro(Texto::pegar($m, 'altura'));
        $dimensoes = $largura > 0 && $altura > 0 ? ' width="' . $largura . '" height="' . $altura . '"' : '';
        $local = Texto::pegar($m, 'local');
        if ($op['modo'] === 'editor' && is_string($local) && $local !== '') {
            return ['html' => '<img src="' . Texto::escapeHtml($local) . '"' . $dimensoes . ' alt="' . $alt . '" ' . $carregamento . '>', 'vazio' => false];
        }
        $ext = Texto::pegar($m, 'formato') === 'svg' ? 'svg' : 'webp';
        $variantes = self::larguras(Texto::pegar($m, 'variantes'));
        if ($ext === 'svg' || $variantes === []) {
            $src = Texto::escapeHtml(self::urlMidia($op['urlMidia'], $id, 'orig', $ext));
            return ['html' => '<img src="' . $src . '"' . $dimensoes . ' alt="' . $alt . '" ' . $carregamento . '>', 'vazio' => false];
        }
        $maior = $variantes[count($variantes) - 1];
        $larguraSrc = self::maiorAte($variantes, 960) ?? $variantes[0];
        $src = Texto::escapeHtml(self::urlMidia($op['urlMidia'], $id, $larguraSrc, $ext));
        $srcset = Texto::escapeHtml(implode(', ', array_map(
            static fn (int $w): string => self::urlMidia($op['urlMidia'], $id, $w, $ext) . ' ' . $w . 'w',
            $variantes,
        )));
        $sizesEntrada = Texto::textoDe(Texto::pegar($entrada, 'sizes'));
        $sizes = Texto::escapeHtml($sizesEntrada !== '' ? $sizesEntrada : '100vw');
        $alturaProporcional = $largura > 0 ? Texto::arred(($altura * $maior) / $largura) : $altura;
        return [
            'html' => '<img src="' . $src . '" srcset="' . $srcset . '" sizes="' . $sizes . '" width="' . $maior
                . '" height="' . $alturaProporcional . '" alt="' . $alt . '" ' . $carregamento . '>',
            'vazio' => false,
        ];
    }

    /**
     * HTML do logo (§5.5) → [html, temLogo]. $entrada = [midiaId, nome, inicial].
     *
     * @return array{html: string, temLogo: bool}
     */
    public static function logo(array $entrada, mixed $opcoes): array
    {
        $op = self::normalizarOpcoes($opcoes);
        $id = Texto::textoDe(Texto::pegar($entrada, 'midiaId'));
        $m = self::midiaDe($op, $id);
        $nome = Texto::escapeHtml(Texto::textoDe(Texto::pegar($entrada, 'nome')));
        if ($m === null) {
            return [
                'html' => '<span class="rk-logo__ini" aria-hidden="true">' . Texto::escapeHtml(Texto::textoDe(Texto::pegar($entrada, 'inicial'))) . '</span>',
                'temLogo' => false,
            ];
        }
        $local = Texto::pegar($m, 'local');
        if ($op['modo'] === 'editor' && is_string($local) && $local !== '') {
            return ['html' => '<img class="rk-logo__img" src="' . Texto::escapeHtml($local) . '" alt="' . $nome . '">', 'temLogo' => true];
        }
        $largura = self::inteiro(Texto::pegar($m, 'largura'));
        $altura = self::inteiro(Texto::pegar($m, 'altura'));
        $ext = Texto::pegar($m, 'formato') === 'svg' ? 'svg' : 'webp';
        $variantes = self::larguras(Texto::pegar($m, 'variantes'));
        if ($ext === 'svg' || $variantes === []) {
            $src = Texto::escapeHtml(self::urlMidia($op['urlMidia'], $id, 'orig', $ext));
            return [
                'html' => '<img class="rk-logo__img" src="' . $src . '" width="' . $largura . '" height="' . $altura . '" alt="' . $nome . '">',
                'temLogo' => true,
            ];
        }
        $w1 = self::maiorAte($variantes, 320) ?? $variantes[0];
        $url1 = self::urlMidia($op['urlMidia'], $id, $w1, $ext);
        $srcset = $url1 . ' 1x';
        if (in_array(640, $variantes, true) && $w1 < 640) {
            $srcset .= ', ' . self::urlMidia($op['urlMidia'], $id, 640, $ext) . ' 2x';
        }
        $h = $largura > 0 ? Texto::arred(($altura * $w1) / $largura) : $altura;
        return [
            'html' => '<img class="rk-logo__img" src="' . Texto::escapeHtml($url1) . '" srcset="' . Texto::escapeHtml($srcset)
                . '" width="' . $w1 . '" height="' . $h . '" alt="' . $nome . '">',
            'temLogo' => true,
        ];
    }

    /** Invólucro da seção (§5.4). */
    public static function involucro(string $tipo, string $opcao, string $fundo, string $ancora, int $indice, string $html): string
    {
        $t = Texto::escapeHtml($tipo);
        return '<div class="rk-sec rk-sec--' . $t . ' rk-op--' . $t . '-' . Texto::escapeHtml($opcao) . ' rk-bg--' . Texto::escapeHtml($fundo)
            . '" id="' . Texto::escapeHtml($ancora) . '" data-sec="' . $indice . '">' . $html . '</div>';
    }

    /** Botão flutuante de WhatsApp (§5.4). */
    public static function botaoWhatsapp(string $link, mixed $svg): string
    {
        return '<a class="rk-wa" href="' . Texto::escapeHtml($link)
            . '" data-ev="whatsapp" data-pos="flutuante" target="_blank" rel="noopener" aria-label="Conversar no WhatsApp">'
            . Texto::textoDe($svg) . '</a>';
    }
}
