<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Fila de tarefas em banco [M14] (e-mails, republicação, limpeza), processada pelo
 * app/cli/cron.php. Falhas são repetidas com recuo exponencial até 5 tentativas.
 */
final class Tarefas
{
    public const MAX_TENTATIVAS = 5;
    /** Tarefa "executando" há mais que isto é considerada abandonada (processo morreu). */
    private const ABANDONO_SEGUNDOS = 1800;

    /** @var array<string, callable> */
    private array $executores = [];
    /** @var array<string, bool> tipos cujo payload é apagado ao terminar (ex.: tokens) */
    private array $sensiveis = [];
    /** @var list<int> ids enfileirados nesta requisição/processo */
    private array $enfileiradas = [];

    public function __construct(private readonly Aplicacao $app)
    {
    }

    /** Põe uma tarefa na fila; devolve o id. */
    public function enfileirar(string $tipo, array $payload = [], ?\DateTimeImmutable $quando = null): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $tipo)) {
            throw new \InvalidArgumentException("Tipo de tarefa inválido: {$tipo}");
        }
        $agora = $this->app->agoraSql();
        $id = $this->app->db()->inserir('tarefas', [
            'tipo' => $tipo,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'pendente',
            'tentativas' => 0,
            'executar_em' => ($quando ?? $this->app->agora())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'erro' => null,
            'criado_em' => $agora,
            'atualizado_em' => $agora,
        ]);
        $this->enfileiradas[] = $id;
        return $id;
    }

    /**
     * Registra o executor de um tipo: executor(array $payload, Aplicacao $app): void.
     * $payloadSensivel = true apaga o payload quando a tarefa termina (ex.: token de redefinição).
     */
    public function registrar(string $tipo, callable $executor, bool $payloadSensivel = false): void
    {
        $this->executores[$tipo] = $executor;
        if ($payloadSensivel) {
            $this->sensiveis[$tipo] = true;
        } else {
            unset($this->sensiveis[$tipo]);
        }
    }

    public function registrado(string $tipo): bool
    {
        return isset($this->executores[$tipo]);
    }

    /** Ids enfileirados por este processo (para processar logo após responder). */
    public function enfileiradasAgora(): array
    {
        return $this->enfileiradas;
    }

    /**
     * Executa até $limite tarefas vencidas de tipos registrados. Devolve quantas executou
     * (com sucesso ou falha).
     */
    public function processar(int $limite = 20): int
    {
        if ($this->executores === [] || $limite <= 0) {
            return 0;
        }
        $this->recuperarAbandonadas();
        $tipos = array_keys($this->executores);
        $marcadores = implode(', ', array_fill(0, count($tipos), '?'));
        $limite = max(1, min(500, $limite));
        $linhas = $this->app->db()->todos(
            "SELECT id FROM tarefas WHERE status = 'pendente' AND executar_em <= ? AND tipo IN ({$marcadores})"
            . " ORDER BY executar_em, id LIMIT {$limite}",
            array_merge([$this->app->agoraSql()], $tipos),
        );
        $feitas = 0;
        foreach ($linhas as $linha) {
            if ($this->executar((int) $linha['id'])) {
                $feitas++;
            }
        }
        return $feitas;
    }

    /** Executa uma tarefa específica se ela estiver pendente e vencida. */
    public function processarIds(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $t = $this->app->db()->um("SELECT tipo, executar_em FROM tarefas WHERE id = ? AND status = 'pendente'", [(int) $id]);
            if ($t !== null && $this->registrado((string) $t['tipo']) && (string) $t['executar_em'] <= $this->app->agoraSql()
                && $this->executar((int) $id)) {
                $n++;
            }
        }
        return $n;
    }

    /** Existe tarefa deste tipo pendente ou em execução? */
    public function existePendente(string $tipo): bool
    {
        return (int) $this->app->db()->valor(
            "SELECT COUNT(*) FROM tarefas WHERE tipo = ? AND status IN ('pendente', 'executando')",
            [$tipo],
        ) > 0;
    }

    /** Recuo após a n-ésima falha: 1 min, 4 min, 16 min, 64 min… (máx. 6 h). */
    public static function recuoSegundos(int $tentativas): int
    {
        return (int) min(21600, 60 * (4 ** max(0, $tentativas - 1)));
    }

    /** Reivindica (pendente → executando) e executa; devolve true se reivindicou. */
    private function executar(int $id): bool
    {
        $db = $this->app->db();
        $agora = $this->app->agoraSql();
        $reivindicada = $db->executar(
            "UPDATE tarefas SET status = 'executando', atualizado_em = ? WHERE id = ? AND status = 'pendente'",
            [$agora, $id],
        );
        if ($reivindicada === 0) {
            return false; // outro processo pegou antes
        }
        $tarefa = $db->um('SELECT * FROM tarefas WHERE id = ?', [$id]);
        if ($tarefa === null) {
            return false;
        }
        $tipo = (string) $tarefa['tipo'];
        $payload = json_decode((string) $tarefa['payload'], true);
        $limparPayload = isset($this->sensiveis[$tipo]);
        try {
            ($this->executores[$tipo])(is_array($payload) ? $payload : [], $this->app);
            $dados = ['status' => 'feita', 'erro' => null, 'atualizado_em' => $this->app->agoraSql()];
            if ($limparPayload) {
                $dados['payload'] = '{}';
            }
            $db->atualizar('tarefas', $dados, ['id' => $id]);
        } catch (\Throwable $e) {
            $tentativas = (int) $tarefa['tentativas'] + 1;
            $esgotou = $tentativas >= self::MAX_TENTATIVAS;
            $dados = [
                'status' => $esgotou ? 'falhou' : 'pendente',
                'tentativas' => $tentativas,
                'erro' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 2000, 'UTF-8'),
                'executar_em' => $esgotou ? (string) $tarefa['executar_em']
                    : $this->app->agora()->modify('+' . self::recuoSegundos($tentativas) . ' seconds')->format('Y-m-d H:i:s'),
                'atualizado_em' => $this->app->agoraSql(),
            ];
            if ($esgotou && $limparPayload) {
                $dados['payload'] = '{}';
            }
            $db->atualizar('tarefas', $dados, ['id' => $id]);
            $this->app->log()->excecao($e, ['tarefa' => $id, 'tipo' => $tipo, 'tentativa' => $tentativas]);
        }
        return true;
    }

    /** Tarefas presas em "executando" (processo interrompido) voltam para a fila contando a tentativa. */
    private function recuperarAbandonadas(): void
    {
        $db = $this->app->db();
        $limite = $this->app->agoraSql('-' . self::ABANDONO_SEGUNDOS . ' seconds');
        foreach ($db->todos("SELECT id, tentativas FROM tarefas WHERE status = 'executando' AND atualizado_em < ?", [$limite]) as $t) {
            $tentativas = (int) $t['tentativas'] + 1;
            $db->executar(
                "UPDATE tarefas SET status = ?, tentativas = ?, erro = ?, atualizado_em = ? WHERE id = ? AND status = 'executando'",
                [
                    $tentativas >= self::MAX_TENTATIVAS ? 'falhou' : 'pendente',
                    $tentativas,
                    'Execução interrompida (processo encerrado antes de terminar).',
                    $this->app->agoraSql(),
                    (int) $t['id'],
                ],
            );
        }
    }
}
