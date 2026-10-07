<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Limite de tentativas por janela fixa (tabela limites): login, esqueci a senha, leads.
 * SQL portátil (sem upsert): lê, atualiza ou insere; colisão de inserção → atualiza.
 */
final class LimiteTaxa
{
    public function __construct(private readonly Aplicacao $app)
    {
    }

    /** Chave curta e sem dados pessoais: prefixo + sha256 das partes. */
    public static function chave(string $prefixo, string ...$partes): string
    {
        return $prefixo . ':' . hash('sha256', implode("\0", $partes));
    }

    /** Contagem na janela atual (0 se não há registro ou a janela venceu). */
    public function contagem(string $chave, int $janelaSegundos): int
    {
        $linha = $this->app->db()->um('SELECT contagem, janela_inicio FROM limites WHERE chave = ?', [$chave]);
        if ($linha === null || $this->vencida((string) $linha['janela_inicio'], $janelaSegundos)) {
            return 0;
        }
        return (int) $linha['contagem'];
    }

    public function excedido(string $chave, int $maximo, int $janelaSegundos): bool
    {
        return $this->contagem($chave, $janelaSegundos) >= $maximo;
    }

    /** Segundos até a janela atual acabar (0 se não há janela ativa). */
    public function segundosRestantes(string $chave, int $janelaSegundos): int
    {
        $inicio = $this->app->db()->valor('SELECT janela_inicio FROM limites WHERE chave = ?', [$chave]);
        if (!is_string($inicio)) {
            return 0;
        }
        $fim = $this->instante($inicio) + $janelaSegundos;
        return max(0, $fim - $this->app->agora()->getTimestamp());
    }

    /**
     * Reserva uma tentativa ANTES do trabalho (conferir senha, gravar lead) e diz se ela cabe
     * no limite. Conferir com excedido() e só registrar() depois deixava requisições
     * simultâneas passarem todas pela conferência antes de qualquer uma ser contada.
     * Quem desistir do trabalho (dados inválidos, login certo) devolve a vaga com descontar().
     */
    public function consumir(string $chave, int $maximo, int $janelaSegundos): bool
    {
        return $this->registrar($chave, $janelaSegundos) <= $maximo;
    }

    /** Devolve uma tentativa reservada por consumir()/registrar() (nunca abaixo de zero). */
    public function descontar(string $chave): void
    {
        $this->app->db()->executar('UPDATE limites SET contagem = contagem - 1 WHERE chave = ? AND contagem > 0', [$chave]);
    }

    /**
     * Conta mais uma ocorrência; devolve a contagem na janela incluindo esta. O incremento e a
     * leitura ficam na mesma transação (a linha fica travada até o fim), então duas
     * requisições simultâneas nunca leem o mesmo número.
     */
    public function registrar(string $chave, int $janelaSegundos): int
    {
        $db = $this->app->db();
        $agora = $this->app->agoraSql();
        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            $linha = $db->um('SELECT contagem, janela_inicio FROM limites WHERE chave = ?', [$chave]);
            if ($linha === null) {
                try {
                    $db->inserir('limites', ['chave' => $chave, 'contagem' => 1, 'janela_inicio' => $agora]);
                    return 1;
                } catch (\PDOException $e) {
                    if (!Db::ehDuplicidade($e)) {
                        throw $e;
                    }
                    continue; // outra requisição inseriu antes: tenta de novo
                }
            }
            if ($this->vencida((string) $linha['janela_inicio'], $janelaSegundos)) {
                $n = $db->executar(
                    'UPDATE limites SET contagem = 1, janela_inicio = ? WHERE chave = ? AND janela_inicio = ?',
                    [$agora, $chave, $linha['janela_inicio']],
                );
                if ($n > 0) {
                    return 1;
                }
                continue;
            }
            return (int) $db->transacao(static function (Db $db) use ($chave): int {
                $db->executar('UPDATE limites SET contagem = contagem + 1 WHERE chave = ?', [$chave]);
                return (int) $db->valor('SELECT contagem FROM limites WHERE chave = ?', [$chave]);
            });
        }
        return $this->contagem($chave, $janelaSegundos);
    }

    public function limpar(string $chave): void
    {
        $this->app->db()->executar('DELETE FROM limites WHERE chave = ?', [$chave]);
    }

    /** Remove registros com janela iniciada há mais de $idadeSegundos (limpeza diária). */
    public static function limparVencidos(Aplicacao $app, int $idadeSegundos = 86400): int
    {
        return $app->db()->executar('DELETE FROM limites WHERE janela_inicio < ?', [$app->agoraSql('-' . $idadeSegundos . ' seconds')]);
    }

    private function vencida(string $inicio, int $janelaSegundos): bool
    {
        return $this->instante($inicio) + $janelaSegundos <= $this->app->agora()->getTimestamp();
    }

    private function instante(string $sql): int
    {
        $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sql, new \DateTimeZone('UTC'));
        return $t !== false ? $t->getTimestamp() : 0;
    }
}
