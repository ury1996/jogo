<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Aplicacao;
use Rankly\Http\Resposta;
use Rankly\Lib\Slug;

/**
 * Entrega os arquivos estáticos de um site publicado quando o Apache não pode fazê-lo sozinho:
 * domínio próprio (fase 2, via sites/_roteador.php), a página 404 de cada site e o servidor de
 * desenvolvimento (sites/router-dev.php). Protege contra travessia de pastas e arquivos ocultos
 * e usa os mesmos tipos e cabeçalhos de cache do sites/.htaccess.
 */
final class ServidorEstatico
{
    /** Cache do mapa domínio → slug (var/cache/dominios.json). */
    public const CACHE_DOMINIOS_SEGUNDOS = 60;

    private const TIPOS = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'webmanifest' => 'application/manifest+json',
    ];

    public function __construct(private readonly string $dirSites)
    {
    }

    /** Pasta real (release no ar) do site, ou null. */
    public function pastaDoSite(string $slug): ?string
    {
        return Slug::valido($slug) ? (new Publicador($this->dirSites))->pastaNoAr($slug) : null;
    }

    /**
     * Slug do site atendido pelo host: subdomínio de dominio_sites, ou domínio próprio ativo
     * (tabela dominios, com cache em arquivo e "www." opcional).
     */
    public static function slugDoHost(Aplicacao $app, string $host): ?string
    {
        $host = self::semPorta(strtolower(trim($host)));
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/D', $host)) {
            return null;
        }
        $base = self::semPorta(strtolower((string) $app->config('dominio_sites', '')));
        if ($base !== '' && str_ends_with($host, '.' . $base)) {
            $rotulo = substr($host, 0, -strlen('.' . $base));
            return Slug::valido($rotulo) ? $rotulo : null;
        }
        $mapa = self::mapaDominios($app);
        return $mapa[$host] ?? (str_starts_with($host, 'www.') ? ($mapa[substr($host, 4)] ?? null) : null);
    }

    /**
     * Domínios próprios ativos → slug, com cache em var/cache/dominios.json (renovado a cada
     * minuto; sem pasta gravável, consulta o banco a cada vez).
     *
     * @return array<string, string>
     */
    public static function mapaDominios(Aplicacao $app): array
    {
        $arquivo = null;
        try {
            $arquivo = $app->dirVar('cache') . '/dominios.json';
            if (is_file($arquivo) && filemtime($arquivo) >= time() - self::CACHE_DOMINIOS_SEGUNDOS) {
                $dados = json_decode((string) file_get_contents($arquivo), true);
                if (is_array($dados) && is_array($dados['dominios'] ?? null)) {
                    return $dados['dominios'];
                }
            }
        } catch (\Throwable) {
            $arquivo = null;
        }
        $mapa = [];
        $linhas = $app->db()->todos(
            "SELECT d.dominio, s.slug FROM dominios d JOIN sites s ON s.id = d.site_id WHERE d.status = 'ativo' AND s.status = 'publicado'",
        );
        foreach ($linhas as $l) {
            $mapa[strtolower((string) $l['dominio'])] = (string) $l['slug'];
        }
        if ($arquivo !== null) {
            $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['gerado' => time(), 'dominios' => $mapa], JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($tmp, $arquivo);
            }
        }
        return $mapa;
    }

    /** Apaga o cache de domínios (chamar quando um domínio for ativado ou removido). */
    public static function limparCacheDominios(Aplicacao $app): void
    {
        try {
            @unlink($app->dirVar('cache') . '/dominios.json');
        } catch (\Throwable) {
            // sem var/: nada a limpar
        }
    }

    /**
     * Resposta para GET/HEAD $caminhoUrl (já sem query) no site $slug. $query é repassada
     * nos redirecionamentos de pasta sem barra.
     */
    public function responder(?string $slug, string $metodo, string $caminhoUrl, string $query = '', array $cabecalhos = []): Resposta
    {
        $raiz = $slug !== null ? $this->pastaDoSite($slug) : null;
        if ($raiz === null) {
            return self::seguranca(Resposta::texto("Site não encontrado.\n", 404));
        }
        if ($metodo !== 'GET' && $metodo !== 'HEAD') {
            return self::seguranca(Resposta::texto("Método não permitido.\n", 405)->cabecalho('Allow', 'GET, HEAD'));
        }
        $caminho = rawurldecode($caminhoUrl);
        if ($caminho === '' || $caminho[0] !== '/' || str_contains($caminho, "\0") || str_contains($caminho, '\\')) {
            return $this->naoEncontrado($raiz);
        }
        $caminho = preg_replace('#/{2,}#', '/', $caminho) ?? $caminho;
        foreach (explode('/', $caminho) as $parte) {
            if ($parte !== '' && $parte[0] === '.') {
                return self::seguranca(Resposta::texto("Proibido.\n", 403)); // ocultos, "." e ".."
            }
        }
        $alvo = $raiz . $caminho;
        if (is_dir($alvo)) {
            if (!str_ends_with($caminho, '/')) {
                return self::seguranca(Resposta::redirecionar(self::caminhoUrl($caminho) . '/' . ($query !== '' ? '?' . $query : ''), 301));
            }
            $alvo .= 'index.html';
        }
        $real = realpath($alvo);
        if ($real === false || !is_file($real) || !str_starts_with($real, $raiz . DIRECTORY_SEPARATOR)) {
            return $this->naoEncontrado($raiz);
        }
        return $this->arquivo($real, substr($real, strlen($raiz) + 1), $cabecalhos);
    }

    private function naoEncontrado(string $raiz): Resposta
    {
        $pagina = $raiz . '/404.html';
        if (is_file($pagina)) {
            $r = Resposta::arquivo($pagina, self::TIPOS['html'], ['Cache-Control' => 'no-cache']);
            $r->status = 404;
            return self::seguranca($r);
        }
        return self::seguranca(Resposta::texto("Página não encontrada.\n", 404));
    }

    private function arquivo(string $arquivo, string $relativo, array $cabecalhos): Resposta
    {
        $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
        $tipo = self::TIPOS[$ext] ?? null;
        if ($tipo === null) {
            return $this->naoEncontrado(substr($arquivo, 0, -strlen($relativo) - 1));
        }
        $info = stat($arquivo);
        $etag = '"' . dechex((int) $info['mtime']) . '-' . dechex((int) $info['size']) . '"';
        $extra = [
            'Cache-Control' => self::cacheControl($relativo),
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) $info['mtime']) . ' GMT',
        ];
        $seNao = null;
        foreach ($cabecalhos as $nome => $valor) {
            if (strcasecmp((string) $nome, 'if-none-match') === 0) {
                $seNao = (string) $valor;
            }
        }
        if ($seNao !== null && in_array($etag, array_map('trim', explode(',', $seNao)), true)) {
            $r = new Resposta(304, '', $extra);
            return self::seguranca($r);
        }
        $r = Resposta::arquivo($arquivo, $tipo, $extra);
        if ($ext === 'svg') {
            $r->cabecalho('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }
        return self::seguranca($r);
    }

    /** Mesma política de cache do .htaccess: fotos, fontes e CSS com hash ficam 1 ano. */
    public static function cacheControl(string $relativo): string
    {
        if (preg_match('#^(img|fontes|assets)/#', $relativo)) {
            return 'public, max-age=31536000, immutable';
        }
        if (preg_match('/\.(png|svg|ico)$/', $relativo)) {
            return 'public, max-age=86400';
        }
        return 'no-cache';
    }

    private static function seguranca(Resposta $r): Resposta
    {
        return $r->cabecalhoPadrao('X-Content-Type-Options', 'nosniff')
            ->cabecalhoPadrao('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->cabecalhoPadrao('X-Frame-Options', 'SAMEORIGIN');
    }

    private static function caminhoUrl(string $caminho): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $caminho)));
    }

    private static function semPorta(string $host): string
    {
        if (str_starts_with($host, '[')) {
            return $host;
        }
        $pos = strrpos($host, ':');
        return $pos === false ? $host : substr($host, 0, $pos);
    }
}
