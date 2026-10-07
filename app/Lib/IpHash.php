<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Http\Requisicao;

/**
 * Identificação de IP sem guardar o IP (LGPD) [M19]: HMAC-SHA256(ip, segredo_ip).
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

    /** Forma canônica (IPv6 comprimido em minúsculas) para o mesmo IP dar o mesmo hash. */
    public static function normalizar(string $ip): string
    {
        $bin = @inet_pton(trim($ip));
        if ($bin === false) {
            return trim(strtolower($ip));
        }
        return (string) inet_ntop($bin);
    }
}
