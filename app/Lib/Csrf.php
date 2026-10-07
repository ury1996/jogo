<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Token CSRF por sessão [M27]: gerado no login, enviado pelo editor no cabeçalho
 * X-CSRF-Token em toda requisição que altera dados, comparado com hash_equals.
 */
final class Csrf
{
    private const CHAVE = 'csrf';

    /** Token da sessão (cria se ainda não houver). */
    public static function token(Sessao $sessao): string
    {
        $t = $sessao->get(self::CHAVE);
        if (!is_string($t) || strlen($t) !== 64) {
            $t = self::renovar($sessao);
        }
        return $t;
    }

    /** Gera um token novo (ex.: no login). */
    public static function renovar(Sessao $sessao): string
    {
        $t = bin2hex(random_bytes(32));
        $sessao->set(self::CHAVE, $t);
        return $t;
    }

    /** O token enviado confere com o da sessão? (tempo constante) */
    public static function verificar(Sessao $sessao, ?string $enviado): bool
    {
        $esperado = $sessao->get(self::CHAVE);
        return is_string($esperado) && $esperado !== '' && is_string($enviado) && $enviado !== ''
            && hash_equals($esperado, $enviado);
    }
}
