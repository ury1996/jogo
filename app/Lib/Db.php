<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Acesso ao banco (MySQL/MariaDB em produção, SQLite nos testes) por PDO,
 * sempre com consultas preparadas. Datas são passadas pelo PHP ("Y-m-d H:i:s", UTC).
 */
final class Db
{
    private int $profundidade = 0;

    private function __construct(private readonly \PDO $pdo, private readonly string $driver)
    {
    }

    /**
     * Conecta com cfg = config('db'): {driver, dsn, usuario, senha}.
     * O driver efetivo é o prefixo do DSN ("mysql:" ou "sqlite:").
     */
    public static function conectar(array $cfg): self
    {
        $dsn = (string) ($cfg['dsn'] ?? '');
        if ($dsn === '') {
            throw new \RuntimeException('Configuração do banco sem DSN (db.dsn).');
        }
        // O prefixo do DSN manda (o "driver" da config é só informativo e pode vir do padrão).
        $driver = strtolower(strstr($dsn, ':', true) ?: (string) ($cfg['driver'] ?? ''));
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException("Driver de banco não suportado: {$driver}.");
        }
        $opcoes = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'mysql') {
            $opcoes[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'";
        }
        $pdo = new \PDO($dsn, $cfg['usuario'] ?? null, $cfg['senha'] ?? null, $opcoes);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return new self($pdo, $driver);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** 'mysql' | 'sqlite' */
    public function driver(): string
    {
        return $this->driver;
    }

    private function preparar(string $sql, array $p): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_is_list($p) ? array_values($p) : $p as $chave => $valor) {
            $nome = is_int($chave) ? $chave + 1 : (str_starts_with((string) $chave, ':') ? (string) $chave : ':' . $chave);
            $tipo = match (true) {
                is_int($valor) => \PDO::PARAM_INT,
                is_bool($valor) => \PDO::PARAM_BOOL,
                $valor === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $stmt->bindValue($nome, is_bool($valor) ? (int) $valor : $valor, $tipo === \PDO::PARAM_BOOL ? \PDO::PARAM_INT : $tipo);
        }
        $stmt->execute();
        return $stmt;
    }

    /** Primeira linha ou null. */
    public function um(string $sql, array $p = []): ?array
    {
        $linha = $this->preparar($sql, $p)->fetch();
        return is_array($linha) ? $linha : null;
    }

    /** @return list<array> */
    public function todos(string $sql, array $p = []): array
    {
        return $this->preparar($sql, $p)->fetchAll();
    }

    /** Primeira coluna da primeira linha (ou null). */
    public function valor(string $sql, array $p = []): mixed
    {
        $v = $this->preparar($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Executa e devolve o número de linhas afetadas. */
    public function executar(string $sql, array $p = []): int
    {
        return $this->preparar($sql, $p)->rowCount();
    }

    /** INSERT a partir de um mapa coluna → valor; devolve o id gerado (0 se a chave não for numérica). */
    public function inserir(string $tabela, array $dados): int
    {
        if ($dados === []) {
            throw new \InvalidArgumentException('inserir() sem colunas.');
        }
        $colunas = array_map([$this, 'identificador'], array_keys($dados));
        $sql = 'INSERT INTO ' . $this->identificador($tabela) . ' (' . implode(', ', $colunas) . ') VALUES ('
            . implode(', ', array_fill(0, count($dados), '?')) . ')';
        $this->preparar($sql, array_values($dados));
        $id = $this->pdo->lastInsertId();
        return is_string($id) && ctype_digit($id) ? (int) $id : 0;
    }

    /** UPDATE com igualdade em todas as colunas de $onde; devolve linhas afetadas. */
    public function atualizar(string $tabela, array $dados, array $onde): int
    {
        if ($dados === [] || $onde === []) {
            throw new \InvalidArgumentException('atualizar() precisa de colunas e de condição.');
        }
        $sets = [];
        $p = [];
        foreach ($dados as $coluna => $valor) {
            $sets[] = $this->identificador((string) $coluna) . ' = ?';
            $p[] = $valor;
        }
        $conds = [];
        foreach ($onde as $coluna => $valor) {
            if ($valor === null) {
                $conds[] = $this->identificador((string) $coluna) . ' IS NULL';
            } else {
                $conds[] = $this->identificador((string) $coluna) . ' = ?';
                $p[] = $valor;
            }
        }
        $sql = 'UPDATE ' . $this->identificador($tabela) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conds);
        return $this->preparar($sql, $p)->rowCount();
    }

    /**
     * Executa $fn($this) numa transação (aninhamento reaproveita a transação externa).
     * Exceção → rollback e relança.
     */
    public function transacao(callable $fn): mixed
    {
        if ($this->profundidade > 0) {
            $this->profundidade++;
            try {
                return $fn($this);
            } finally {
                $this->profundidade--;
            }
        }
        $this->pdo->beginTransaction();
        $this->profundidade = 1;
        try {
            $resultado = $fn($this);
            $this->pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->profundidade = 0;
        }
    }

    /** O erro é de chave única/primária duplicada? (portável MySQL/SQLite) */
    public static function ehDuplicidade(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $estado = (string) ($e->errorInfo[0] ?? $e->getCode());
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        return $estado === '23000' && ($codigo === 1062 || $codigo === 19 || $codigo === 2067 || $codigo === 1555
            || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate'));
    }

    /** Nome de tabela/coluna validado e entre crases (aceito por MySQL e SQLite; "release" é reservada no MySQL). */
    private function identificador(string $nome): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/D', $nome)) {
            throw new \InvalidArgumentException("Identificador SQL inválido: {$nome}");
        }
        return '`' . $nome . '`';
    }
}
