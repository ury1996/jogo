<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Rankly\Lib\Db;
use Rankly\Lib\Migrador;

final class DbTest extends TestCase
{
    /** Operações comuns, rodadas em SQLite e (se disponível) em MariaDB/MySQL. */
    private function exercitar(Db $db, string $sufixo): void
    {
        $agora = gmdate('Y-m-d H:i:s');
        $email = "db-{$sufixo}@rankly.teste";
        $id = $db->inserir('usuarios', ['nome' => 'Ana', 'email' => $email, 'senha_hash' => 'x', 'papel' => 'equipe', 'criado_em' => $agora]);
        $this->assertGreaterThan(0, $id);
        $u = $db->um('SELECT * FROM usuarios WHERE id = ?', [$id]);
        $this->assertSame('Ana', $u['nome']);
        $this->assertSame(1, (int) $u['ativo']);
        $this->assertNull($db->um('SELECT * FROM usuarios WHERE id = ?', [-1]));
        $this->assertSame('Ana', $db->valor('SELECT nome FROM usuarios WHERE email = :email', ['email' => $email]));
        $this->assertNull($db->valor('SELECT nome FROM usuarios WHERE id = ?', [-1]));
        $this->assertSame(1, $db->atualizar('usuarios', ['nome' => 'Ana Lima', 'totp_segredo' => null], ['id' => $id]));
        $this->assertSame('Ana Lima', $db->valor('SELECT nome FROM usuarios WHERE id = ?', [$id]));

        // Unicidade detectada de forma portável.
        try {
            $db->inserir('usuarios', ['nome' => 'B', 'email' => $email, 'senha_hash' => 'x', 'papel' => 'equipe', 'criado_em' => $agora]);
            $this->fail('E-mail duplicado deveria falhar');
        } catch (\PDOException $e) {
            $this->assertTrue(Db::ehDuplicidade($e));
        }

        // Transação: exceção desfaz tudo; aninhada reaproveita a externa.
        try {
            $db->transacao(function (Db $db) use ($id): void {
                $db->atualizar('usuarios', ['nome' => 'Dentro'], ['id' => $id]);
                $db->transacao(fn (Db $db) => $db->atualizar('usuarios', ['papel' => 'admin'], ['id' => $id]));
                throw new \RuntimeException('falha');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(['nome' => 'Ana Lima', 'papel' => 'equipe'], $db->um('SELECT nome, papel FROM usuarios WHERE id = ?', [$id]));
        $this->assertSame(7, $db->transacao(fn (): int => 7));

        // Coluna com nome reservado no MySQL ("release") pelos helpers.
        $siteId = $db->inserir('sites', ['dono_id' => $id, 'slug' => 'db' . $sufixo, 'nome' => 'S', 'nicho' => 'n', 'modelo' => 'm',
            'documento' => '{"a":"ç"}', 'criado_em' => $agora, 'atualizado_em' => $agora]);
        $db->inserir('versoes', ['site_id' => $siteId, 'numero' => 1, 'documento' => '{}', 'release' => '1-abc', 'publicado_em' => $agora]);
        $this->assertSame('1-abc', $db->valor('SELECT `release` FROM versoes WHERE site_id = ?', [$siteId]));
        $this->assertSame('{"a":"ç"}', $db->valor('SELECT documento FROM sites WHERE id = ?', [$siteId]), 'UTF-8 preservado');
        $this->assertCount(1, $db->todos('SELECT * FROM sites WHERE id = ? AND status = ?', [$siteId, 'rascunho']));

        // Chave estrangeira: apagar o site apaga as versões.
        $db->executar('DELETE FROM sites WHERE id = ?', [$siteId]);
        $this->assertSame(0, (int) $db->valor('SELECT COUNT(*) FROM versoes WHERE site_id = ?', [$siteId]));
        $this->assertSame(1, $db->executar('DELETE FROM usuarios WHERE id = ?', [$id]));
    }

    public function testSqlite(): void
    {
        $dir = null;
        $modoMysql = getenv('RANKLY_TESTE_MYSQL');
        putenv('RANKLY_TESTE_MYSQL'); // este teste é sempre em SQLite
        try {
            $app = AmbienteTeste::criar([], $dir);
        } finally {
            if ($modoMysql !== false) {
                putenv('RANKLY_TESTE_MYSQL=' . $modoMysql);
            }
        }
        try {
            $this->assertSame('sqlite', $app->db()->driver());
            $this->exercitar($app->db(), 'sqlite');
            $this->assertSame([], (new Migrador($app->db(), Migrador::dirPadrao($app->db())))->pendentes());
            $this->assertSame(['001_inicial.sql', '002_midia_origem.sql'], array_column($app->db()->todos('SELECT nome FROM migracoes'), 'nome'));
        } finally {
            AmbienteTeste::remover($dir);
        }
    }

    public function testIdentificadorInvalidoERecusado(): void
    {
        $db = Db::conectar(['dsn' => 'sqlite::memory:']);
        $this->expectException(\InvalidArgumentException::class);
        $db->inserir('usuarios; DROP TABLE x', ['a' => 1]);
    }

    public function testDividirSql(): void
    {
        $this->assertSame(['CREATE TABLE a (x INT)', 'CREATE INDEX i ON a (x)'], Migrador::dividir("-- comentário;\nCREATE TABLE a (x INT);\n\nCREATE INDEX i ON a (x);\n"));
    }

    /** Roda contra o MariaDB local (banco rankly_teste) quando disponível; senão é pulado. */
    #[Group('mysql')]
    public function testMysql(): void
    {
        $cfg = [
            'driver' => 'mysql',
            'dsn' => getenv('RANKLY_TESTE_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=rankly_teste;charset=utf8mb4',
            'usuario' => getenv('RANKLY_TESTE_MYSQL_USUARIO') ?: 'rankly',
            'senha' => getenv('RANKLY_TESTE_MYSQL_SENHA') ?: 'rankly',
        ];
        try {
            $db = Db::conectar($cfg);
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB/MySQL indisponível: ' . $e->getMessage());
        }
        $this->assertSame('mysql', $db->driver());
        (new Migrador($db, Migrador::dirPadrao($db)))->aplicar();
        $this->assertSame([], (new Migrador($db, Migrador::dirPadrao($db)))->pendentes(), 'Migração idempotente');
        $this->assertSame('+00:00', $db->valor('SELECT @@session.time_zone'));
        $this->exercitar($db, 'mysql' . bin2hex(random_bytes(3)));
    }
}
