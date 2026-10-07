<?php

declare(strict_types=1);

namespace Rankly\Http;

/**
 * Erro com status HTTP, código estável e mensagem em português pronta para o usuário.
 * Vira {"erro":{"codigo","mensagem"}, ...extra} na resposta.
 */
final class ErroHttp extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $codigo,
        string $mensagem,
        public readonly array $extra = [],
        public readonly array $cabecalhos = [],
    ) {
        parent::__construct($mensagem, $status);
    }

    public static function naoAutenticado(): self
    {
        return new self(401, 'nao_autenticado', 'Sua sessão expirou. Entre de novo para continuar.');
    }

    public static function proibido(string $mensagem = 'Você não tem acesso a este recurso.'): self
    {
        return new self(403, 'proibido', $mensagem);
    }

    public static function naoEncontrado(string $mensagem = 'Não encontrado.'): self
    {
        return new self(404, 'nao_encontrado', $mensagem);
    }

    public static function invalido(string $mensagem, array $extra = [], string $codigo = 'invalido'): self
    {
        return new self(422, $codigo, $mensagem, $extra);
    }

    public static function requisicaoRuim(string $mensagem): self
    {
        return new self(400, 'requisicao_invalida', $mensagem);
    }

    /** Corpo JSON do erro. */
    public function corpo(): array
    {
        return ['erro' => ['codigo' => $this->codigo, 'mensagem' => $this->getMessage()]] + $this->extra;
    }
}
