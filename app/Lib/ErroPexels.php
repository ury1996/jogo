<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Falha do banco de imagens (Pexels) com mensagem em português pronta para o usuário
 * e o status HTTP que a API deve devolver (429 cota, 503 fora do ar, 404 foto inexistente…).
 */
final class ErroPexels extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly int $status = 502, ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }
}
