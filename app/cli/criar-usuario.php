<?php

/**
 * Cria (ou atualiza, com --atualizar) um usuário.
 * Uso: php app/cli/criar-usuario.php --nome="Maria Silva" --email=maria@exemplo.com --papel=admin [--senha=...] [--atualizar]
 * Sem --senha, a senha é pedida no terminal (sem eco).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \Rankly\Aplicacao $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use Rankly\Lib\Usuarios;

$opcoes = getopt('', ['nome:', 'email:', 'papel:', 'senha:', 'atualizar', 'ajuda']);
if (isset($opcoes['ajuda']) || !isset($opcoes['email'])) {
    echo "Uso: php app/cli/criar-usuario.php --nome=\"Nome\" --email=email@exemplo.com --papel=admin|equipe|cliente [--senha=...] [--atualizar]\n";
    exit(isset($opcoes['ajuda']) ? 0 : 1);
}

/** Lê a senha do terminal sem mostrar o que é digitado (quando possível). */
$lerSenha = static function (string $pergunta): string {
    echo $pergunta;
    $semEco = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($semEco) {
        shell_exec('stty -echo');
    }
    $linha = fgets(STDIN);
    if ($semEco) {
        shell_exec('stty echo');
        echo "\n";
    }
    return rtrim((string) $linha, "\r\n");
};

$email = Usuarios::normalizarEmail((string) $opcoes['email']);
$nome = (string) ($opcoes['nome'] ?? '');
$papel = (string) ($opcoes['papel'] ?? 'equipe');
$senha = isset($opcoes['senha']) ? (string) $opcoes['senha'] : null;

try {
    $existente = Usuarios::porEmail($app, $email);
    if ($existente !== null && !isset($opcoes['atualizar'])) {
        fwrite(STDERR, "Já existe um usuário com o e-mail {$email}. Use --atualizar para trocar senha, nome ou papel.\n");
        exit(1);
    }
    if ($senha === null) {
        $senha = $lerSenha('Senha: ');
        if ($senha !== $lerSenha('Repita a senha: ')) {
            fwrite(STDERR, "As senhas não conferem.\n");
            exit(1);
        }
    }
    $problema = Usuarios::problemaSenha($senha);
    if ($problema !== null) {
        fwrite(STDERR, $problema . "\n");
        exit(1);
    }
    if ($existente !== null) {
        $dados = ['senha_hash' => Usuarios::hash($senha), 'ativo' => 1];
        if ($nome !== '') {
            $dados['nome'] = $nome;
        }
        if (isset($opcoes['papel'])) {
            if (!in_array($papel, Usuarios::PAPEIS, true)) {
                fwrite(STDERR, "Papel inválido (use admin, equipe ou cliente).\n");
                exit(1);
            }
            $dados['papel'] = $papel;
        }
        $app->db()->atualizar('usuarios', $dados, ['id' => (int) $existente['id']]);
        echo "Usuário {$email} atualizado (id {$existente['id']}).\n";
    } else {
        $id = Usuarios::criar($app, $nome !== '' ? $nome : $email, $email, $papel, $senha);
        echo "Usuário {$email} criado (id {$id}, papel {$papel}).\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
