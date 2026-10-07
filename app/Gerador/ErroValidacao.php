<?php

declare(strict_types=1);

namespace Rankly\Gerador;

/**
 * O site tem pendências que impedem a publicação (contrato §11.2).
 * $erros = lista de {codigo, mensagem, chave?, secao?, grupo?, confirmavel?}, já em português.
 */
final class ErroValidacao extends \RuntimeException
{
    public function __construct(public array $erros)
    {
        $primeira = $erros[0]['mensagem'] ?? null;
        parent::__construct(is_string($primeira) && $primeira !== ''
            ? 'O site tem pendências que impedem a publicação: ' . $primeira
            : 'O site tem pendências que impedem a publicação.');
    }
}
