<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

/**
 * Falha da IA com mensagem em português pronta para mostrar ao usuário.
 * $temporario = vale tentar de novo daqui a pouco (cota, instabilidade, tempo esgotado).
 */
final class ErroIa extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly bool $temporario = false, ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }
}
