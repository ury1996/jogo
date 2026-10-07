<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Preparo\Dados;

/**
 * Telefones do lado do servidor (leads, e-mails). Reaproveita as regras de
 * Preparo\Dados (mesmas do editor) para normalizar e formatar.
 */
final class Telefone
{
    /** Dígitos no formato nacional (sem +55 nem 0 de operadora). */
    public static function nacional(mixed $telefone): string
    {
        return Dados::digitosNacionais($telefone);
    }

    /** Telefone de lead: 10 ou 11 dígitos nacionais (§8). */
    public static function validoParaLead(mixed $telefone): bool
    {
        $n = strlen(self::nacional($telefone));
        return $n === 10 || $n === 11;
    }

    /** "(11) 98765-4321" */
    public static function formatar(mixed $telefone): string
    {
        return Dados::formatarTelefone($telefone);
    }

    /** https://wa.me/55… com mensagem opcional ("" sem dígitos). */
    public static function linkWhatsapp(mixed $telefone, string $mensagem = ''): string
    {
        return Dados::linkWhatsapp($telefone, $mensagem);
    }
}
