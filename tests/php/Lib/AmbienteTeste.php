<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use Rankly\Aplicacao;
use Rankly\Lib\Migrador;
use Rankly\Lib\Usuarios;

/**
 * Ambiente isolado para testes: pasta temporária (var, media, sites), SQLite em arquivo
 * com as migrações aplicadas e a biblioteca de fixture (tests/fixtures/biblioteca-mini).
 */
final class AmbienteTeste
{
    public static function fixtureBiblioteca(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/biblioteca-mini';
    }

    /** Configuração de teste (sem banco ainda). */
    public static function config(string $dir, array $extra = []): array
    {
        $base = [
            'ambiente' => 'dev',
            'url_editor' => 'http://editor.teste',
            'dominio_sites' => 'sites.teste',
            'protocolo_sites' => 'https',
            'db' => ['driver' => 'sqlite', 'dsn' => 'sqlite:' . $dir . '/banco.sqlite', 'usuario' => null, 'senha' => null],
            'dir_sites' => $dir . '/sites',
            'dir_media' => $dir . '/media',
            'dir_var' => $dir . '/var',
            'dir_biblioteca' => self::fixtureBiblioteca(),
            'segredo_app' => 'segredo-app-de-teste',
            'segredo_ip' => 'segredo-ip-de-teste',
            'email_modo' => 'arquivo',
            'smtp' => ['remetente' => 'nao-responda@rankly.teste', 'nome_remetente' => 'Sites Rankly'],
        ];
        return array_replace_recursive($base, $extra);
    }

    /**
     * Cria pasta temporária + banco migrado e devolve a aplicação.
     * Com RANKLY_TESTE_MYSQL=1 usa o MariaDB local (banco rankly_teste, esvaziado a cada teste)
     * em vez de SQLite: vendor/bin/phpunit -c tests/php/phpunit.xml com a variável definida.
     */
    public static function criar(array $extra = [], ?string &$dir = null): Aplicacao
    {
        $dir = sys_get_temp_dir() . '/rankly-teste-' . bin2hex(random_bytes(6));
        foreach (['', '/sites', '/media', '/var'] as $sub) {
            mkdir($dir . $sub, 0775, true);
        }
        $extra += self::extraBanco();
        $app = Aplicacao::iniciar(self::config($dir, $extra));
        (new Migrador($app->db(), Migrador::dirPadrao($app->db())))->aplicar();
        if ($app->db()->driver() === 'mysql') {
            $pdo = $app->db()->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['eventos', 'leads', 'midia', 'versoes', 'dominios', 'site_acessos', 'redefinicoes_senha', 'sites', 'usuarios', 'tarefas', 'limites'] as $t) {
                $pdo->exec("TRUNCATE TABLE `{$t}`");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $app;
    }

    /** Configuração de banco extra: MariaDB local quando RANKLY_TESTE_MYSQL=1; senão nada (SQLite). */
    public static function extraBanco(): array
    {
        if (getenv('RANKLY_TESTE_MYSQL') !== '1') {
            return [];
        }
        return ['db' => [
            'driver' => 'mysql',
            'dsn' => getenv('RANKLY_TESTE_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=rankly_teste;charset=utf8mb4',
            'usuario' => getenv('RANKLY_TESTE_MYSQL_USUARIO') ?: 'rankly',
            'senha' => getenv('RANKLY_TESTE_MYSQL_SENHA') ?: 'rankly',
        ]];
    }

    public static function remover(?string $dir): void
    {
        if ($dir === null || !str_contains($dir, 'rankly-teste-') || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** Cria um usuário e devolve a linha (com a senha em claro em 'senha'). */
    public static function usuario(Aplicacao $app, string $papel = 'admin', ?string $email = null, string $senha = 'senha-forte-123'): array
    {
        $email ??= $papel . '-' . bin2hex(random_bytes(3)) . '@rankly.teste';
        $id = Usuarios::criar($app, ucfirst($papel) . ' Teste', $email, $papel, $senha);
        $u = Usuarios::porId($app, $id);
        $u['senha'] = $senha;
        return $u;
    }

    /** Insere um site mínimo direto no banco (para testes de Lib). */
    public static function site(Aplicacao $app, string $slug = 'sorrisovivo', array $doc = [], ?int $donoId = null): array
    {
        $doc += [
            'versaoEsquema' => 2, 'nicho' => 'clinicas', 'especialidade' => 'odontologia', 'modelo' => 'moderno',
            'estilo' => ['cor' => '#c23b6e', 'fonte' => 'editorial', 'acabamento' => 'moderno', 'whatsappFlutuante' => true],
            'dados' => ['nome' => 'Clínica Sorriso Vivo', 'cidade' => 'Jundiaí', 'uf' => 'SP', 'whatsapp' => '(11) 98765-4321', 'email' => ''],
            'secoes' => [], 'textos' => [], 'listas' => [], 'imagens' => [], 'icones' => [], 'confirmados' => [],
        ];
        $agora = $app->agoraSql();
        $id = $app->db()->inserir('sites', [
            'dono_id' => $donoId, 'slug' => $slug, 'nome' => (string) $doc['dados']['nome'], 'nicho' => $doc['nicho'],
            'modelo' => $doc['modelo'], 'documento' => json_encode($doc, JSON_UNESCAPED_UNICODE), 'revisao' => 1,
            'status' => 'rascunho', 'criado_em' => $agora, 'atualizado_em' => $agora,
        ]);
        return \Rankly\Lib\Sites::porId($app, $id);
    }

    /** E-mails gravados em var/emails (modo arquivo), em ordem. */
    public static function emails(Aplicacao $app): array
    {
        $arquivos = glob($app->dir('var') . '/emails/*.eml') ?: [];
        sort($arquivos);
        return array_map('file_get_contents', $arquivos);
    }

    /** Corpo de um .eml decodificado (quoted-printable). */
    public static function corpoEmail(string $eml): string
    {
        $partes = preg_split("/\r\n\r\n/", $eml, 2);
        return quoted_printable_decode($partes[1] ?? '');
    }
}
