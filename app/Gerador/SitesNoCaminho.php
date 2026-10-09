<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Aplicacao;
use Rankly\Http\Resposta;

/**
 * Modo demonstração (config sites_no_caminho): os sites publicados ficam em
 * {url_editor}/s/{slug}/, no mesmo endereço do editor — sem subdomínios curinga, para rodar
 * num Codespace, num túnel (trycloudflare) ou num serviço que dá um endereço só.
 *
 * A release publicada é a mesma de sempre (caminhos absolutos "/img/…", "/_lead"…); aqui ela
 * é servida com esses caminhos prefixados por /s/{slug} no HTML (atributos e <style>) e no CSS.
 * Scripts embutidos não são tocados (o hash da CSP continua valendo): o formulário usa o
 * atributo action, que é reescrito.
 *
 * Produção continua com um domínio separado para os sites ([M25]): aqui eles dividem a origem
 * com o editor, o que só serve para testes.
 */
final class SitesNoCaminho
{
    /** [slug, resto do caminho] de "/s/{slug}/resto", ou null. */
    public static function separar(string $caminho): ?array
    {
        if (!preg_match('#^/s/([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(/.*)?$#', $caminho, $m)) {
            return null;
        }
        return [$m[1], $m[2] ?? ''];
    }

    /** GET/HEAD de um arquivo do site (o POST /s/{slug}/_lead é tratado por public_html/s.php). */
    public static function responder(Aplicacao $app, string $metodo, string $uri, array $cabecalhos = []): Resposta
    {
        $caminho = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
        $partes = self::separar($caminho);
        if ($partes === null) {
            return Resposta::texto("Site não encontrado.\n", 404);
        }
        [$slug, $resto] = $partes;
        $base = '/s/' . $slug;
        if ($resto === '') {
            return Resposta::redirecionar($base . '/' . ($query !== '' ? '?' . $query : ''), 301);
        }
        $r = (new ServidorEstatico($app->dir('sites')))->responder($slug, $metodo, $resto, $query, $cabecalhos);

        $local = $r->obterCabecalho('Location');
        if ($local !== null && str_starts_with($local, '/') && !str_starts_with($local, '//')) {
            $r->cabecalho('Location', $base . $local);
        }
        $tipo = strtolower((string) $r->obterCabecalho('Content-Type'));
        $html = str_starts_with($tipo, 'text/html');
        if ($r->arquivo === null || (!$html && !str_starts_with($tipo, 'text/css'))) {
            return $r;
        }
        $conteudo = (string) file_get_contents($r->arquivo);
        $conteudo = $html ? self::html($conteudo, $base) : self::css($conteudo, $base);
        $nova = new Resposta($r->status, $conteudo, $r->cabecalhos());
        $nova->cabecalho('Content-Length', (string) strlen($conteudo));
        return $nova;
    }

    /** Prefixa os caminhos absolutos ("/x", nunca "//x") dos atributos e dos <style> do HTML. */
    public static function html(string $html, string $base): string
    {
        // Trechos <script>…</script> ficam intactos (CSP por hash); <style> passa pelo css().
        $pedacos = preg_split('#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pedacos === false) {
            return $html;
        }
        $saida = '';
        foreach ($pedacos as $i => $pedaco) {
            if ($i % 2 === 1) {
                $saida .= stripos($pedaco, '<style') === 0 ? self::css($pedaco, $base) : $pedaco;
                continue;
            }
            $pedaco = preg_replace_callback(
                '#\b(href|src|action|poster|data-src)=(["\'])/(?!/)#i',
                static fn (array $m): string => $m[1] . '=' . $m[2] . $base . '/',
                $pedaco,
            ) ?? $pedaco;
            $pedaco = preg_replace_callback(
                '#\b(srcset)=(["\'])(.*?)\2#is',
                static fn (array $m): string => $m[1] . '=' . $m[2]
                    . (preg_replace('#(^|,\s*)/(?!/)#', '$1' . $base . '/', $m[3]) ?? $m[3]) . $m[2],
                $pedaco,
            ) ?? $pedaco;
            $saida .= self::css($pedaco, $base); // url(/…) em style="…"
        }
        return $saida;
    }

    /** Prefixa url(/…) do CSS. */
    public static function css(string $css, string $base): string
    {
        return preg_replace('#url\(\s*(["\']?)/(?!/)#i', 'url($1' . $base . '/', $css) ?? $css;
    }
}
