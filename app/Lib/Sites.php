<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Preparo\Documento;

/**
 * Consultas e formatos de sites usados pela API, pelo gerador e por sites/_lead.php.
 */
final class Sites
{
    /** Linha do site com 'documento' decodificado (array), ou null. */
    public static function porId(Aplicacao $app, int $id): ?array
    {
        $linha = $app->db()->um('SELECT * FROM sites WHERE id = ?', [$id]);
        return $linha === null ? null : self::decodificar($linha);
    }

    public static function porSlug(Aplicacao $app, string $slug): ?array
    {
        if (!Slug::valido($slug)) {
            return null;
        }
        $linha = $app->db()->um('SELECT * FROM sites WHERE slug = ?', [$slug]);
        return $linha === null ? null : self::decodificar($linha);
    }

    /**
     * Site atendido por um host: subdomínio de dominio_sites ("sorrisovivo.sitesrankly.com.br"
     * → slug "sorrisovivo"), senão a tabela dominios (status ativo; "www." opcional).
     */
    public static function porHost(Aplicacao $app, string $host): ?array
    {
        $host = self::semPorta(strtolower(trim($host)));
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        $base = self::semPorta(strtolower((string) $app->config('dominio_sites', '')));
        if ($base !== '' && str_ends_with($host, '.' . $base)) {
            $rotulo = substr($host, 0, -strlen('.' . $base));
            return Slug::valido($rotulo) ? self::porSlug($app, $rotulo) : null;
        }
        $candidatos = [$host];
        if (str_starts_with($host, 'www.')) {
            $candidatos[] = substr($host, 4);
        }
        foreach ($candidatos as $dominio) {
            $siteId = $app->db()->valor("SELECT site_id FROM dominios WHERE dominio = ? AND status = 'ativo'", [$dominio]);
            if ($siteId !== null) {
                return self::porId($app, (int) $siteId);
            }
        }
        return null;
    }

    /** Usuário pode ver/editar o site? admin/equipe: todos; cliente: só os de site_acessos. */
    public static function podeAcessar(Aplicacao $app, array $usuario, int $siteId): bool
    {
        if (Usuarios::ehEquipe($usuario)) {
            return true;
        }
        return $app->db()->valor(
            'SELECT 1 FROM site_acessos WHERE site_id = ? AND usuario_id = ?',
            [$siteId, (int) $usuario['id']],
        ) !== null;
    }

    /** Slug único a partir do nome. */
    public static function slugUnico(Aplicacao $app, string $nome): string
    {
        return Slug::unico($nome, static fn (string $s): bool => $app->db()->valor('SELECT 1 FROM sites WHERE slug = ?', [$s]) !== null);
    }

    /** "Y-m-d H:i:s" (UTC) → ISO 8601 "Y-m-d\TH:i:s\Z" (ou null). */
    public static function iso(mixed $sql): ?string
    {
        if (!is_string($sql) || $sql === '') {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($sql, 0, 19), new \DateTimeZone('UTC'));
        return $t !== false ? $t->format('Y-m-d\TH:i:s\Z') : null;
    }

    /**
     * Documento como árvore de objetos para json_encode, preservando {} nos mapas vazios
     * (um array PHP vazio viraria [] e o editor perderia o tipo).
     */
    public static function documentoParaJson(array $doc): mixed
    {
        return json_decode(Documento::json($doc), false, 512, JSON_THROW_ON_ERROR);
    }

    /** Formato da API para GET /api/sites/{id}. */
    public static function publico(Aplicacao $app, array $site): array
    {
        return [
            'id' => (int) $site['id'],
            'slug' => (string) $site['slug'],
            'nome' => (string) $site['nome'],
            'nicho' => (string) $site['nicho'],
            'modelo' => (string) $site['modelo'],
            'status' => (string) $site['status'],
            'revisao' => (int) $site['revisao'],
            'documento' => self::documentoParaJson(is_array($site['documento']) ? $site['documento'] : []),
            'url' => $app->urlSite((string) $site['slug']),
            'publicadoVersao' => $site['publicado_versao'] !== null ? (int) $site['publicado_versao'] : null,
            'publicadoEm' => self::iso($site['publicado_em']),
            'criadoEm' => self::iso($site['criado_em']),
            'atualizadoEm' => self::iso($site['atualizado_em']),
        ];
    }

    private static function decodificar(array $linha): array
    {
        $doc = json_decode((string) $linha['documento'], true);
        $linha['documento'] = is_array($doc) ? $doc : [];
        foreach (['id', 'dono_id', 'revisao', 'publicado_versao', 'atualizado_por'] as $campo) {
            if (isset($linha[$campo])) {
                $linha[$campo] = (int) $linha[$campo];
            }
        }
        return $linha;
    }

    private static function semPorta(string $host): string
    {
        if (str_starts_with($host, '[')) {
            return $host; // IPv6 literal: nunca é site
        }
        $pos = strrpos($host, ':');
        return $pos === false ? $host : substr($host, 0, $pos);
    }
}
