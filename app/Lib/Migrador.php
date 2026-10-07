<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Aplica os arquivos app/sql/{driver}/NNN_*.sql ainda não registrados na tabela migracoes.
 * Cada arquivo é dividido em comandos por ";" no fim da linha (os esquemas não têm ";" em textos).
 */
final class Migrador
{
    public function __construct(private readonly Db $db, private readonly string $dirSql)
    {
    }

    /** Pasta padrão do driver: app/sql/mysql ou app/sql/sqlite. */
    public static function dirPadrao(Db $db): string
    {
        return dirname(__DIR__) . '/sql/' . $db->driver();
    }

    /** @return list<string> nomes dos arquivos pendentes, em ordem */
    public function pendentes(): array
    {
        $this->criarTabela();
        $aplicadas = array_column($this->db->todos('SELECT nome FROM migracoes'), 'nome');
        $arquivos = glob($this->dirSql . '/*.sql') ?: [];
        sort($arquivos, SORT_STRING);
        $r = [];
        foreach ($arquivos as $arquivo) {
            $nome = basename($arquivo);
            if (!in_array($nome, $aplicadas, true)) {
                $r[] = $nome;
            }
        }
        return $r;
    }

    /**
     * Aplica as pendentes.
     *
     * @return list<string> nomes aplicados
     */
    public function aplicar(?callable $aoAplicar = null): array
    {
        $aplicadas = [];
        foreach ($this->pendentes() as $nome) {
            $sql = (string) file_get_contents($this->dirSql . '/' . $nome);
            $comandos = self::dividir($sql);
            // No MySQL, DDL encerra a transação implicitamente: aplicamos fora de transação.
            if ($this->db->driver() === 'sqlite') {
                $this->db->transacao(function (Db $db) use ($comandos, $nome): void {
                    foreach ($comandos as $comando) {
                        $db->pdo()->exec($comando);
                    }
                    $this->registrar($nome);
                });
            } else {
                foreach ($comandos as $comando) {
                    $this->db->pdo()->exec($comando);
                }
                $this->registrar($nome);
            }
            $aplicadas[] = $nome;
            if ($aoAplicar !== null) {
                $aoAplicar($nome);
            }
        }
        return $aplicadas;
    }

    /** @return list<string> */
    public static function dividir(string $sql): array
    {
        $semComentarios = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $partes = preg_split('/;\s*$/m', $semComentarios) ?: [];
        return array_values(array_filter(array_map('trim', $partes), static fn (string $c): bool => $c !== ''));
    }

    private function registrar(string $nome): void
    {
        $this->db->inserir('migracoes', [
            'nome' => $nome,
            'aplicada_em' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function criarTabela(): void
    {
        $sql = $this->db->driver() === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS migracoes (nome VARCHAR(190) NOT NULL, aplicada_em DATETIME NOT NULL, PRIMARY KEY (nome))'
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE IF NOT EXISTS migracoes (nome TEXT PRIMARY KEY, aplicada_em TEXT NOT NULL)';
        $this->db->pdo()->exec($sql);
    }
}
