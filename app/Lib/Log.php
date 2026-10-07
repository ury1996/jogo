<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Log simples em arquivo (var/logs/rankly-AAAA-MM.log), uma linha JSON por evento.
 * Nunca registrar senhas, tokens ou dados de leads.
 */
final class Log
{
    public function __construct(private readonly string $dir, private readonly ?\Closure $relogio = null)
    {
    }

    public function info(string $mensagem, array $contexto = []): void
    {
        $this->escrever('info', $mensagem, $contexto);
    }

    public function aviso(string $mensagem, array $contexto = []): void
    {
        $this->escrever('aviso', $mensagem, $contexto);
    }

    public function erro(string $mensagem, array $contexto = []): void
    {
        $this->escrever('erro', $mensagem, $contexto);
    }

    /** Registra uma exceção com classe, arquivo, linha e pilha resumida. */
    public function excecao(\Throwable $e, array $contexto = []): void
    {
        $this->escrever('erro', $e->getMessage(), $contexto + [
            'classe' => $e::class,
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
            'pilha' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /** Caminho do arquivo do mês corrente. */
    public function arquivo(): string
    {
        return $this->dir . '/rankly-' . $this->agora()->format('Y-m') . '.log';
    }

    private function agora(): \DateTimeImmutable
    {
        return $this->relogio !== null ? ($this->relogio)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function escrever(string $nivel, string $mensagem, array $contexto): void
    {
        $linha = json_encode([
            'em' => $this->agora()->format('Y-m-d\TH:i:s\Z'),
            'nivel' => $nivel,
            'mensagem' => $mensagem,
        ] + ($contexto !== [] ? ['contexto' => $contexto] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        try {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            @file_put_contents($this->arquivo(), $linha . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // log nunca derruba a requisição
        }
    }
}
