<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Fundos alternados automáticos (contrato §5.2).
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/tons.mjs.
 */
final class Tons
{
    private const FIXOS = ['branco' => 'branco', 'tom-claro' => 'tom', 'escuro' => 'escuro', 'cor' => 'cor'];

    /**
     * Converte os tons das opções (na ordem da página, incluindo header e rodapé) nos
     * fundos 'branco' | 'tom' | 'escuro' | 'cor'. Tom desconhecido conta como "claro".
     *
     * @return list<string>
     */
    public static function calcularFundos(mixed $tons): array
    {
        $fundos = [];
        $anterior = 'branco';
        foreach (Texto::comoLista($tons) as $tom) {
            $fundo = is_string($tom) && array_key_exists($tom, self::FIXOS)
                ? self::FIXOS[$tom]
                : ($anterior === 'branco' ? 'tom' : 'branco');
            $fundos[] = $fundo;
            $anterior = $fundo;
        }
        return $fundos;
    }
}
