<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Contas de usuário: criação, senha (password_hash com rehash) e formato público.
 */
final class Usuarios
{
    public const PAPEIS = ['admin', 'equipe', 'cliente'];
    public const SENHA_MIN = 8;
    public const SENHA_MAX = 200;

    /** E-mail normalizado (minúsculas, sem espaços nas pontas). */
    public static function normalizarEmail(mixed $email): string
    {
        return mb_strtolower(trim(is_string($email) ? $email : ''), 'UTF-8');
    }

    public static function emailValido(string $email): bool
    {
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Mensagem de erro em português, ou null se a senha serve. */
    public static function problemaSenha(mixed $senha): ?string
    {
        if (!is_string($senha) || mb_strlen($senha, 'UTF-8') < self::SENHA_MIN) {
            return 'A senha precisa ter pelo menos ' . self::SENHA_MIN . ' caracteres.';
        }
        if (mb_strlen($senha, 'UTF-8') > self::SENHA_MAX) {
            return 'A senha pode ter no máximo ' . self::SENHA_MAX . ' caracteres.';
        }
        return null;
    }

    public static function hash(string $senha): string
    {
        return password_hash($senha, PASSWORD_DEFAULT);
    }

    /**
     * Hash de uma senha aleatória com o algoritmo e o custo atuais de hash(), para o login
     * conferir a senha mesmo quando o e-mail não existe e levar o mesmo tempo. Um hash fixo
     * no código (custo 10) denunciaria quais e-mails têm conta quando o custo padrão muda
     * (PHP 8.4 passou o bcrypt para 12). Gerado uma vez e guardado em var/cache.
     */
    public static function hashFalso(Aplicacao $app): string
    {
        static $memoria = [];
        $arquivo = null;
        try {
            $arquivo = $app->dirVar('cache') . '/hash-falso.txt';
        } catch (\Throwable) {
            // sem var/ gravável: gera em memória
        }
        $chave = $arquivo ?? '';
        $atual = $memoria[$chave] ?? ($arquivo !== null && is_file($arquivo) ? trim((string) @file_get_contents($arquivo)) : '');
        if ($atual !== '' && password_get_info($atual)['algo'] !== null && !password_needs_rehash($atual, PASSWORD_DEFAULT)) {
            return $memoria[$chave] = $atual;
        }
        $novo = self::hash(bin2hex(random_bytes(16)));
        if ($arquivo !== null) {
            $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $novo) !== false) {
                @rename($tmp, $arquivo);
            }
        }
        return $memoria[$chave] = $novo;
    }

    public static function porEmail(Aplicacao $app, string $email): ?array
    {
        return $app->db()->um('SELECT * FROM usuarios WHERE email = ?', [self::normalizarEmail($email)]);
    }

    public static function porId(Aplicacao $app, int $id): ?array
    {
        return $app->db()->um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    }

    /**
     * Cria um usuário. Devolve o id.
     *
     * @throws \InvalidArgumentException dados inválidos ou e-mail já cadastrado
     */
    public static function criar(Aplicacao $app, string $nome, string $email, string $papel, string $senha): int
    {
        $nome = trim($nome);
        $email = self::normalizarEmail($email);
        if ($nome === '' || mb_strlen($nome, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Informe um nome com até 120 caracteres.');
        }
        if (!self::emailValido($email)) {
            throw new \InvalidArgumentException('E-mail inválido.');
        }
        if (!in_array($papel, self::PAPEIS, true)) {
            throw new \InvalidArgumentException('Papel inválido (use admin, equipe ou cliente).');
        }
        $problema = self::problemaSenha($senha);
        if ($problema !== null) {
            throw new \InvalidArgumentException($problema);
        }
        if (self::porEmail($app, $email) !== null) {
            throw new \InvalidArgumentException('Já existe um usuário com este e-mail.');
        }
        return $app->db()->inserir('usuarios', [
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => self::hash($senha),
            'papel' => $papel,
            'ativo' => 1,
            'criado_em' => $app->agoraSql(),
        ]);
    }

    public static function definirSenha(Aplicacao $app, int $id, string $senha): void
    {
        $app->db()->atualizar('usuarios', ['senha_hash' => self::hash($senha)], ['id' => $id]);
    }

    /** Formato público (sem hash nem segredos). */
    public static function publico(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'nome' => (string) $u['nome'],
            'email' => (string) $u['email'],
            'papel' => (string) $u['papel'],
        ];
    }

    /** admin e equipe acessam todos os sites; cliente só os de site_acessos. */
    public static function ehEquipe(array $u): bool
    {
        return in_array($u['papel'] ?? '', ['admin', 'equipe'], true);
    }
}
