<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Preparo\Dados;
use Rankly\Preparo\Texto;
use Rankly\Preparo\Textos;

/**
 * SEO e documento HTML do site publicado (contrato §7 passos 7 e 9, [M26]): título,
 * descrição, canonical, Open Graph, JSON-LD (sem AggregateRating), sitemap.xml, robots.txt
 * e a CSP por <meta> com o hash sha256 dos scripts embutidos.
 */
final class Seo
{
    public const MAX_DESCRICAO = 155;

    private const DIAS_SCHEMA = [
        'seg' => 'Monday', 'ter' => 'Tuesday', 'qua' => 'Wednesday', 'qui' => 'Thursday',
        'sex' => 'Friday', 'sab' => 'Saturday', 'dom' => 'Sunday',
    ];

    /** "{nome} · {segmento} em {cidade}" (ou seo.titulo, com as variáveis trocadas). */
    public static function titulo(Montagem $m): string
    {
        $proprio = $m->doc['seo']['titulo'] ?? null;
        if (is_string($proprio) && Texto::colapsarEspacos($proprio) !== '') {
            return Texto::colapsarEspacos(Textos::substituirVariaveis($proprio, $m->vars));
        }
        $nome = $m->vars['nome'];
        $segmento = Texto::colapsarEspacos($m->vars['segmento']);
        $cidade = $m->vars['cidade'];
        $complemento = $segmento !== '' && $cidade !== '' ? $segmento . ' em ' . $cidade : ($segmento !== '' ? $segmento : ($cidade !== '' ? 'em ' . $cidade : ''));
        if ($complemento === '') {
            return $nome;
        }
        return $segmento === '' ? $nome . ' ' . $complemento : $nome . ' · ' . $complemento;
    }

    /** seo.descricao ?? texto do destaque cortado em ~155 caracteres sem quebrar palavra. */
    public static function descricao(Montagem $m): string
    {
        $propria = $m->doc['seo']['descricao'] ?? null;
        if (is_string($propria) && Texto::colapsarEspacos($propria) !== '') {
            return self::cortar(Textos::substituirVariaveis($propria, $m->vars), 300);
        }
        $texto = Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, 'hero.texto', $m->vars));
        if ($texto === '') {
            $texto = Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, 'rodape.sobre', $m->vars));
        }
        if ($texto === '') {
            $texto = self::titulo($m) . '. Fale com a gente pelo WhatsApp.';
        }
        return self::cortar($texto, self::MAX_DESCRICAO);
    }

    /** Corta em até $max caracteres sem quebrar palavra (com "…" quando corta). */
    public static function cortar(string $texto, int $max = self::MAX_DESCRICAO): string
    {
        $texto = Texto::colapsarEspacos($texto);
        if (mb_strlen($texto, 'UTF-8') <= $max) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, $max, 'UTF-8');
        $espaco = mb_strrpos($corte, ' ', 0, 'UTF-8');
        if ($espaco !== false && $espaco > $max * 0.6) {
            $corte = mb_substr($corte, 0, $espaco, 'UTF-8');
        }
        return rtrim($corte, " \t,;:.-–—") . '…';
    }

    /**
     * Dados estruturados (schema.org): negócio com o tipo da especialidade, site e FAQPage.
     *
     * @param array{base: string, descricao: string, imagem: ?string, logo: ?string} $info
     */
    public static function jsonLd(Montagem $m, array $info): string
    {
        $dados = $m->doc['dados'];
        $tipo = (string) ($m->especialidade['schemaOrg'] ?? '');
        $tipo = preg_match('/^[A-Z][A-Za-z]{2,60}$/D', $tipo) ? $tipo : 'LocalBusiness';
        $url = $info['base'] . '/';
        $negocio = [
            '@type' => $tipo,
            '@id' => $url . '#negocio',
            'name' => $m->vars['nome'],
            'url' => $url,
            'description' => $info['descricao'],
        ];
        $telefone = Dados::digitosNacionais($dados['telefone']);
        if (strlen($telefone) < 10) {
            $telefone = Dados::digitosNacionais($dados['whatsapp']);
        }
        if (strlen($telefone) === 10 || strlen($telefone) === 11) {
            $negocio['telephone'] = '+55 ' . substr($telefone, 0, 2) . ' ' . (strlen($telefone) === 11
                ? substr($telefone, 2, 5) . '-' . substr($telefone, 7) : substr($telefone, 2, 4) . '-' . substr($telefone, 6));
        }
        if ($m->d['temEmail']) {
            $negocio['email'] = $m->d['email'];
        }
        if ($info['imagem'] !== null) {
            $negocio['image'] = $info['imagem'];
        }
        if ($info['logo'] !== null) {
            $negocio['logo'] = $info['logo'];
        }
        $endereco = self::endereco($dados);
        if ($endereco !== null) {
            $negocio['address'] = $endereco;
        }
        if ($m->vars['cidade'] !== '') {
            $negocio['areaServed'] = ['@type' => 'City', 'name' => $m->vars['cidade']];
        }
        if ($m->d['mapaLink'] !== '' && $m->d['temEndereco']) {
            $negocio['hasMap'] = $m->d['mapaLink'];
        }
        $horarios = self::horarios($dados['horarios']);
        if ($horarios !== []) {
            $negocio['openingHoursSpecification'] = $horarios;
        }
        $redes = [];
        foreach ($m->d['redes'] as $r) {
            $redes[] = $r['url'];
        }
        if ($redes !== []) {
            $negocio['sameAs'] = $redes;
        }
        $grafo = [
            $negocio,
            ['@type' => 'WebSite', '@id' => $url . '#site', 'url' => $url, 'name' => $m->vars['nome'], 'inLanguage' => 'pt-BR',
                'publisher' => ['@id' => $url . '#negocio']],
        ];
        $faq = self::faq($m);
        if ($faq !== []) {
            $grafo[] = ['@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => $faq];
        }
        return self::json(['@context' => 'https://schema.org', '@graph' => $grafo]);
    }

    /** JSON seguro para <script> (sem "</" nem "&" literais). */
    public static function json(mixed $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    private static function endereco(array $dados): ?array
    {
        $e = $dados['endereco'];
        $cidade = Texto::colapsarEspacos($dados['cidade']);
        $uf = mb_strtoupper(Texto::colapsarEspacos($dados['uf']), 'UTF-8');
        $logradouro = Texto::colapsarEspacos($e['logradouro']);
        if ($logradouro === '' && $cidade === '') {
            return null;
        }
        $a = ['@type' => 'PostalAddress'];
        if ($logradouro !== '') {
            $rua = $logradouro;
            if (Texto::colapsarEspacos($e['numero']) !== '') {
                $rua .= ', ' . Texto::colapsarEspacos($e['numero']);
            }
            if (Texto::colapsarEspacos($e['complemento']) !== '') {
                $rua .= ' - ' . Texto::colapsarEspacos($e['complemento']);
            }
            $a['streetAddress'] = $rua;
        }
        if ($cidade !== '') {
            $a['addressLocality'] = $cidade;
        }
        if ($uf !== '') {
            $a['addressRegion'] = $uf;
        }
        $cep = Dados::soDigitos($e['cep']);
        if (strlen($cep) === 8) {
            $a['postalCode'] = substr($cep, 0, 5) . '-' . substr($cep, 5);
        }
        $a['addressCountry'] = 'BR';
        return $a;
    }

    /**
     * openingHoursSpecification agrupando dias com os mesmos horários (dia fechado fica de fora).
     *
     * @return list<array>
     */
    public static function horarios(mixed $horarios): array
    {
        $porIntervalo = [];
        foreach (self::DIAS_SCHEMA as $dia => $nome) {
            $v = $horarios[$dia] ?? null;
            if (!is_array($v)) {
                continue;
            }
            for ($i = 0; $i + 1 < count($v); $i += 2) {
                $abre = self::hora($v[$i]);
                $fecha = self::hora($v[$i + 1]);
                if ($abre !== null && $fecha !== null) {
                    $porIntervalo[$abre . '-' . $fecha][] = $nome;
                }
            }
        }
        $r = [];
        foreach ($porIntervalo as $intervalo => $dias) {
            [$abre, $fecha] = explode('-', $intervalo);
            $r[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $dias, 'opens' => $abre, 'closes' => $fecha];
        }
        return $r;
    }

    private static function hora(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{1,2}):(\d{2})$/D', trim($v), $m) || (int) $m[1] > 24 || (int) $m[2] > 59) {
            return null;
        }
        return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }

    /** Perguntas da seção de FAQ (só se ela estiver na página). */
    private static function faq(Montagem $m): array
    {
        if (!$m->temSecao('faq')) {
            return [];
        }
        [, $max] = Textos::limitesLista($m->lib, 'faq');
        $r = [];
        foreach (array_slice(Textos::itensLista($m->doc, $m->lib, 'faq'), 0, $max) as $id) {
            $q = Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, 'faq.' . $id . '.q', $m->vars));
            $a = Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, 'faq.' . $id . '.a', $m->vars));
            if ($q !== '' && $a !== '') {
                $r[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
            }
        }
        return $r;
    }

    /** @param list<array{url: string, data: string}> $paginas */
    public static function sitemap(array $paginas): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($paginas as $p) {
            $xml .= '  <url><loc>' . htmlspecialchars($p['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $p['data'] . "</lastmod></url>\n";
        }
        return $xml . "</urlset>\n";
    }

    public static function robots(string $base): string
    {
        return "User-agent: *\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n";
    }

    /**
     * Content-Security-Policy por <meta> [M26]: scripts próprios só pelo hash; Google
     * (GTM/GA4/Ads) e Meta liberados só se configurados; mapa do Google em frame-src.
     * (frame-ancestors não vale em <meta>; o .htaccess manda X-Frame-Options.)
     *
     * @param list<string> $hashes valores 'sha256-…' (com aspas)
     * @param array{gtm: string, ga4: string, metaPixel: string} $rastreamento
     */
    public static function csp(array $hashes, array $rastreamento): string
    {
        $google = ($rastreamento['gtm'] ?? '') !== '' || ($rastreamento['ga4'] ?? '') !== '';
        $meta = ($rastreamento['metaPixel'] ?? '') !== '';
        $script = array_merge(["'self'"], $hashes);
        $img = ["'self'", 'data:'];
        $connect = ["'self'"];
        $frame = ['https://www.google.com'];
        if ($google) {
            array_push($script, 'https://www.googletagmanager.com', 'https://*.googletagmanager.com', 'https://www.googleadservices.com',
                'https://googleads.g.doubleclick.net', 'https://www.google.com');
            array_push($img, 'https://*.google-analytics.com', 'https://*.googletagmanager.com', 'https://*.g.doubleclick.net',
                'https://www.google.com', 'https://www.google.com.br', 'https://www.googleadservices.com');
            array_push($connect, 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com',
                'https://*.g.doubleclick.net', 'https://www.google.com', 'https://www.google.com.br', 'https://pagead2.googlesyndication.com',
                'https://www.googleadservices.com');
            array_push($frame, 'https://www.googletagmanager.com', 'https://td.doubleclick.net');
        }
        if ($meta) {
            $script[] = 'https://connect.facebook.net';
            $img[] = 'https://www.facebook.com';
            array_push($connect, 'https://www.facebook.com', 'https://connect.facebook.net');
        }
        $diretivas = [
            'default-src' => ["'self'"],
            'script-src' => $script,
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => $img,
            'font-src' => ["'self'"],
            'connect-src' => $connect,
            'frame-src' => $frame,
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];
        $partes = [];
        foreach ($diretivas as $nome => $valores) {
            $partes[] = $nome . ' ' . implode(' ', array_values(array_unique($valores)));
        }
        return implode('; ', $partes);
    }

    /**
     * Documento HTML completo de uma página.
     *
     * $p: titulo, descricao, canonical (?string), noindex (bool), og (?array: titulo, descricao,
     * url, siteNome, imagem: ?{url, largura, altura, alt}), corTema, favicon (HTML), preload
     * (?string URL da fonte de títulos), css (['inline' => …] ou ['href' => …]), jsonLd (?string),
     * classesRaiz, corpo (HTML), script (JS), rastreamento.
     */
    public static function documento(array $p): string
    {
        $e = static fn (string $s): string => Texto::escapeHtml($s);
        $script = (string) $p['script'];
        $csp = self::csp([ScriptSite::hashCsp($script)], $p['rastreamento']);
        $h = [];
        $h[] = '<meta charset="utf-8">';
        $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        // Na CSP só & < > " precisam de escape (as aspas simples ficam legíveis).
        $h[] = '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp, ENT_COMPAT | ENT_HTML5, 'UTF-8') . '">';
        $h[] = '<title>' . $e($p['titulo']) . '</title>';
        $h[] = '<meta name="description" content="' . $e($p['descricao']) . '">';
        if (!empty($p['noindex'])) {
            $h[] = '<meta name="robots" content="noindex">';
        }
        if (($p['canonical'] ?? null) !== null) {
            $h[] = '<link rel="canonical" href="' . $e($p['canonical']) . '">';
        }
        $h[] = '<meta name="theme-color" content="' . $e($p['corTema']) . '">';
        $h[] = '<meta name="referrer" content="strict-origin-when-cross-origin">';
        $og = $p['og'] ?? null;
        if (is_array($og)) {
            $h[] = '<meta property="og:type" content="website">';
            $h[] = '<meta property="og:locale" content="pt_BR">';
            $h[] = '<meta property="og:site_name" content="' . $e($og['siteNome']) . '">';
            $h[] = '<meta property="og:title" content="' . $e($og['titulo']) . '">';
            $h[] = '<meta property="og:description" content="' . $e($og['descricao']) . '">';
            $h[] = '<meta property="og:url" content="' . $e($og['url']) . '">';
            if (is_array($og['imagem'] ?? null)) {
                $h[] = '<meta property="og:image" content="' . $e($og['imagem']['url']) . '">';
                $h[] = '<meta property="og:image:width" content="' . (int) $og['imagem']['largura'] . '">';
                $h[] = '<meta property="og:image:height" content="' . (int) $og['imagem']['altura'] . '">';
                $h[] = '<meta property="og:image:alt" content="' . $e($og['imagem']['alt']) . '">';
                $h[] = '<meta name="twitter:card" content="summary_large_image">';
            } else {
                $h[] = '<meta name="twitter:card" content="summary">';
            }
        }
        $h[] = $p['favicon'];
        if (($p['preload'] ?? null) !== null) {
            $h[] = '<link rel="preload" href="' . $e($p['preload']) . '" as="font" type="font/woff2" crossorigin>';
        }
        if (isset($p['css']['href'])) {
            $h[] = '<link rel="stylesheet" href="' . $e($p['css']['href']) . '">';
        } else {
            $h[] = '<style>' . str_ireplace('</style', '<\/style', (string) ($p['css']['inline'] ?? '')) . '</style>';
        }
        if (($p['jsonLd'] ?? null) !== null) {
            $h[] = '<script type="application/ld+json">' . $p['jsonLd'] . '</script>';
        }
        return "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n" . implode("\n", $h) . "\n</head>\n"
            . '<body class="' . $e($p['classesRaiz']) . '">' . "\n" . $p['corpo'] . "\n"
            . '<script>' . $script . '</script>' . "\n</body>\n</html>\n";
    }

    /** Endereço da foto do destaque para o Open Graph (variante 1600 ou a maior que houver). */
    public static function imagemOg(Montagem $m, string $base): ?array
    {
        foreach (['hero.img', 'sobre.img', 'hero.img2', 'cta.img'] as $chave) {
            $id = $m->doc['imagens'][$chave] ?? null;
            $midia = is_string($id) ? ($m->midia[$id] ?? null) : null;
            if (!is_array($midia) || ($midia['formato'] ?? 'webp') !== 'webp' || ($midia['variantes'] ?? []) === []) {
                continue;
            }
            $variantes = array_map('intval', $midia['variantes']);
            sort($variantes);
            $w = in_array(1600, $variantes, true) ? 1600 : $variantes[count($variantes) - 1];
            $largura = (int) $midia['largura'];
            $altura = $largura > 0 ? (int) floor(((int) $midia['altura'] * $w) / $largura + 0.5) : (int) $midia['altura'];
            $alt = Texto::colapsarEspacos($midia['alt'] ?? '');
            return [
                'url' => $base . '/img/' . $id . '-' . $w . '.webp',
                'largura' => $w,
                'altura' => $altura,
                'alt' => $alt !== '' ? $alt : $m->vars['nome'],
            ];
        }
        return null;
    }

    /** URL absoluta do logo (para o JSON-LD) ou null. */
    public static function urlLogo(Montagem $m, string $base): ?string
    {
        $id = $m->doc['dados']['logo'] ?? null;
        $midia = is_string($id) ? ($m->midia[$id] ?? null) : null;
        if (!is_array($midia)) {
            return null;
        }
        if (($midia['formato'] ?? 'webp') === 'svg' || ($midia['variantes'] ?? []) === []) {
            return ($midia['formato'] ?? '') === 'svg' ? $base . '/img/' . $id . '-orig.svg' : null;
        }
        $variantes = array_map('intval', $midia['variantes']);
        sort($variantes);
        return $base . '/img/' . $id . '-' . $variantes[count($variantes) - 1] . '.webp';
    }
}
