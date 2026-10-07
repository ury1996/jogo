<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Preparo\Texto;

/**
 * Slug do site a partir do nome: sem acento, só a-z0-9, até 40 caracteres, único
 * (sorrisovivo, sorrisovivo2, …). Vira o subdomínio {slug}.{dominio_sites}.
 */
final class Slug
{
    public const MAX = 40;

    /** Subdomínios reservados para a infraestrutura (nunca viram site). */
    public const RESERVADOS = [
        'www', 'api', 'editor', 'admin', 'painel', 'app', 'sites', 'mail', 'email', 'smtp', 'imap', 'pop',
        'ftp', 'cdn', 'static', 'assets', 'img', 'media', 'blog', 'ajuda', 'suporte', 'status', 'dev', 'teste',
        'staging', 'localhost', 'webmail', 'cpanel', 'ns1', 'ns2', 'mx', 'autodiscover', 'releases',
    ];

    /** Base do slug: "Clínica Sorriso Vivo" → "clinicasorrisovivo" ("" se não sobrar nada). */
    public static function base(string $nome): string
    {
        $s = strtolower(Texto::semAcentos($nome));
        $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
        return substr($s, 0, self::MAX);
    }

    /** Formato aceito para um slug (também usado para validar hosts e rotas públicas). */
    public static function valido(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]{1,' . self::MAX . '}$/D', $slug);
    }

    /**
     * Slug único: a base, ou base2, base3… (a base é cortada para caber o número).
     *
     * @param callable(string): bool $existe consulta se o slug já está em uso
     */
    public static function unico(string $nome, callable $existe, string $reserva = 'site'): string
    {
        $base = self::base($nome);
        if ($base === '') {
            $base = self::base($reserva) ?: 'site';
        }
        for ($n = 1; $n < 100000; $n++) {
            $sufixo = $n === 1 ? '' : (string) $n;
            $candidato = substr($base, 0, self::MAX - strlen($sufixo)) . $sufixo;
            if (!in_array($candidato, self::RESERVADOS, true) && !$existe($candidato)) {
                return $candidato;
            }
        }
        // Inalcançável na prática: cai num sufixo aleatório.
        return substr($base, 0, self::MAX - 8) . bin2hex(random_bytes(4));
    }
}
