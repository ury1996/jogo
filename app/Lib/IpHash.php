<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Http\Requisicao;

/**
 * Identificação de IP sem guardar o IP (LGPD) [M19]: HMAC-SHA256(ip, segredo_ip) — IPv6 por /64.
 * Atrás da Cloudflare, o IP real vem de CF-Connecting-IP — só se confiar_cloudflare.
 */
final class IpHash
{
    public static function hash(string $ip, string $segredo): string
    {
        if ($segredo === '') {
            throw new \InvalidArgumentException('segredo_ip vazio.');
        }
        return hash_hmac('sha256', self::normalizar($ip), $segredo);
    }

    /** IP do cliente conforme a configuração. */
    public static function ipDe(Aplicacao $app, Requisicao $req): string
    {
        return $req->ip((bool) $app->config('confiar_cloudflare', false));
    }

    /** HMAC do IP do cliente da requisição. */
    public static function daRequisicao(Aplicacao $app, Requisicao $req): string
    {
        return self::hash(self::ipDe($app, $req), $app->segredoIp());
    }

    /**
     * Forma canônica para o mesmo cliente dar o mesmo hash: IPv4 como está; IPv4 mapeado em
     * IPv6 (::ffff:1.2.3.4) vira o IPv4; IPv6 vira o prefixo /64 ("2001:db8:1:2::/64").
     * Um único cliente IPv6 costuma controlar um /64 inteiro (2^64 endereços): contar por
     * endereço deixaria quem tem IPv6 contornar todos os limites por IP (login, leads)
     * trocando de endereço a cada tentativa.
     */
    public static function normalizar(string $ip): string
    {
        $bin = @inet_pton(trim($ip));
        if ($bin === false) {
            return trim(strtolower($ip));
        }
        if (strlen($bin) === 16) {
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12));
            }
            return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return (string) inet_ntop($bin);
    }
}
