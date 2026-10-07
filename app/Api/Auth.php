<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Csrf;
use Rankly\Lib\Eventos;
use Rankly\Lib\IpHash;
use Rankly\Lib\LimiteTaxa;
use Rankly\Lib\Usuarios;

/**
 * Autenticação [M27]: login com limite de tentativas, logout, sessão atual,
 * "esqueci a senha" (token de uso único, 1 h, só o hash no banco) e redefinição.
 */
final class Auth
{
    private const FALHAS_MAX = 5;
    private const FALHAS_MAX_IP = 30;
    private const JANELA = 900; // 15 min

    public function login(Contexto $ctx, array $p): Resposta
    {
        $d = $ctx->dados();
        $email = Usuarios::normalizarEmail($d['email'] ?? '');
        $senha = is_string($d['senha'] ?? null) ? $d['senha'] : '';
        if ($email === '' || $senha === '') {
            throw ErroHttp::invalido('Informe o e-mail e a senha.');
        }
        $app = $ctx->app;
        $ipHash = IpHash::daRequisicao($app, $ctx->req);
        $limites = new LimiteTaxa($app);
        $chave = LimiteTaxa::chave('login', $email, $ipHash);
        $chaveIp = LimiteTaxa::chave('login-ip', $ipHash);
        // A tentativa é contada ANTES do password_verify (lento): tentativas simultâneas não
        // passam todas por uma conferência que ainda não viu nenhuma delas.
        $cabe = $limites->consumir($chave, self::FALHAS_MAX, self::JANELA);
        if ($cabe && !$limites->consumir($chaveIp, self::FALHAS_MAX_IP, self::JANELA)) {
            $limites->descontar($chave); // barrada pelo limite do IP: esta senha nem foi testada
            $cabe = false;
        }
        if (!$cabe) {
            $espera = max($limites->segundosRestantes($chave, self::JANELA), $limites->segundosRestantes($chaveIp, self::JANELA), 60);
            throw new ErroHttp(429, 'muitas_tentativas',
                'Muitas tentativas de entrar. Aguarde ' . (int) ceil($espera / 60) . ' minuto(s) e tente de novo, ou use "Esqueci a senha".',
                [], ['Retry-After' => (string) $espera]);
        }

        $u = mb_strlen($senha, 'UTF-8') <= Usuarios::SENHA_MAX ? Usuarios::porEmail($app, $email) : null;
        // E-mail inexistente também passa por um password_verify de mesmo custo (tempo uniforme).
        $confere = password_verify($senha, $u !== null ? (string) $u['senha_hash'] : Usuarios::hashFalso($app));
        if ($u === null || !$confere || (int) $u['ativo'] !== 1) {
            // Falha: a tentativa já foi contada nos dois limites.
            if ($u !== null) {
                Eventos::registrar($app, null, (int) $u['id'], 'auth.falha', []);
            }
            throw new ErroHttp(401, 'credenciais', 'E-mail ou senha incorretos.');
        }
        $limites->limpar($chave);
        $limites->descontar($chaveIp); // login certo não conta contra o IP (escritórios atrás do mesmo IP)

        $atualizar = ['ultimo_login_em' => $app->agoraSql()];
        if (password_needs_rehash((string) $u['senha_hash'], PASSWORD_DEFAULT)) {
            $atualizar['senha_hash'] = Usuarios::hash($senha);
            $u['senha_hash'] = $atualizar['senha_hash'];
        }
        $app->db()->atualizar('usuarios', $atualizar, ['id' => (int) $u['id']]);

        // Sessão nova (evita fixação) com a marca da credencial e um CSRF novo.
        $ctx->sessao->regenerar();
        $ctx->sessao->set('usuario_id', (int) $u['id']);
        $ctx->sessao->set('marca', Contexto::marcaCredencial($u));
        $csrf = Csrf::renovar($ctx->sessao);
        $ctx->definirUsuario($u);
        Eventos::registrar($app, null, (int) $u['id'], 'auth.login', []);
        return Resposta::json(['usuario' => Usuarios::publico($u), 'csrf' => $csrf]);
    }

    public function logout(Contexto $ctx, array $p): Resposta
    {
        $id = $ctx->usuarioId();
        $ctx->sessao->destruir();
        if ($id !== null) {
            Eventos::registrar($ctx->app, null, $id, 'auth.logout', []);
        }
        return Resposta::json(['ok' => true]);
    }

    public function eu(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        return Resposta::json(['usuario' => Usuarios::publico($u), 'csrf' => Csrf::token($ctx->sessao)]);
    }

    /** Sempre responde {ok:true} (não revela se o e-mail existe). */
    public function esqueci(Contexto $ctx, array $p): Resposta
    {
        $app = $ctx->app;
        $email = Usuarios::normalizarEmail($ctx->dados()['email'] ?? '');
        if (!Usuarios::emailValido($email)) {
            return Resposta::json(['ok' => true]);
        }
        $ipHash = IpHash::daRequisicao($app, $ctx->req);
        $limites = new LimiteTaxa($app);
        $chave = LimiteTaxa::chave('esqueci', $email);
        $chaveIp = LimiteTaxa::chave('esqueci-ip', $ipHash);
        // Contado antes do trabalho (ver login): pedidos simultâneos não furam o limite.
        if (!$limites->consumir($chave, 3, 3600)) {
            return Resposta::json(['ok' => true]);
        }
        if (!$limites->consumir($chaveIp, 10, 3600)) {
            $limites->descontar($chave);
            return Resposta::json(['ok' => true]);
        }

        $u = Usuarios::porEmail($app, $email);
        if ($u !== null && (int) $u['ativo'] === 1) {
            $token = bin2hex(random_bytes(32));
            $app->db()->transacao(static function () use ($app, $u, $token): void {
                $app->db()->inserir('redefinicoes_senha', [
                    'usuario_id' => (int) $u['id'],
                    'token_hash' => hash('sha256', $token),
                    'expira_em' => $app->agoraSql('+1 hour'),
                    'usado_em' => null,
                ]);
                // O token só existe em claro no payload até o e-mail sair (payload sensível é apagado).
                $app->tarefas()->enfileirar('email_redefinicao', ['usuario_id' => (int) $u['id'], 'token' => $token]);
            });
            Eventos::registrar($app, null, (int) $u['id'], 'auth.esqueci', []);
        }
        return Resposta::json(['ok' => true]);
    }

    public function redefinir(Contexto $ctx, array $p): Resposta
    {
        $app = $ctx->app;
        $d = $ctx->dados();
        $token = is_string($d['token'] ?? null) ? trim($d['token']) : '';
        $senha = $d['senha'] ?? null;
        $invalido = new ErroHttp(400, 'token_invalido', 'Este link de redefinição é inválido ou expirou. Peça um novo em "Esqueci a senha".');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw $invalido;
        }
        $problema = Usuarios::problemaSenha($senha);
        if ($problema !== null) {
            throw ErroHttp::invalido($problema, ['campo' => 'senha']);
        }
        $linha = $app->db()->um('SELECT * FROM redefinicoes_senha WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($linha === null || $linha['usado_em'] !== null || (string) $linha['expira_em'] <= $app->agoraSql()) {
            throw $invalido;
        }
        $usuarioId = (int) $linha['usuario_id'];
        $app->db()->transacao(static function () use ($app, $linha, $usuarioId, $senha, $invalido): void {
            $agora = $app->agoraSql();
            $marcou = $app->db()->executar(
                'UPDATE redefinicoes_senha SET usado_em = ? WHERE id = ? AND usado_em IS NULL',
                [$agora, (int) $linha['id']],
            );
            if ($marcou === 0) {
                throw $invalido; // uso simultâneo do mesmo link
            }
            Usuarios::definirSenha($app, $usuarioId, (string) $senha);
            // Os demais links pendentes do usuário deixam de valer.
            $app->db()->executar('UPDATE redefinicoes_senha SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL', [$agora, $usuarioId]);
        });
        Eventos::registrar($app, null, $usuarioId, 'auth.senha_redefinida', []);
        return Resposta::json(['ok' => true]);
    }
}
