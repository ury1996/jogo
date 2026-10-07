<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Executores;
use Rankly\Testes\Lib\AmbienteTeste;

final class AuthTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private array $admin;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
        $this->admin = AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testLoginDevolveUsuarioCsrfECookieSeguro(): void
    {
        $c = new ClienteApi($this->app);
        $r = $c->login('ADMIN@rankly.teste ', 'senha-forte-123');
        $this->assertSame(200, $r->status);
        $d = $r->dados();
        $this->assertSame(['id', 'nome', 'email', 'papel'], array_keys($d['usuario']));
        $this->assertSame('admin', $d['usuario']['papel']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $d['csrf']);
        $cookie = $r->cookies()[0] ?? '';
        $this->assertStringStartsWith('rk_sessao=', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertStringNotContainsString('Secure', $cookie, 'Em dev sem HTTPS o cookie não é Secure');
        $this->assertSame('nosniff', $r->obterCabecalho('X-Content-Type-Options'));
        $this->assertSame('DENY', $r->obterCabecalho('X-Frame-Options'));
        $this->assertNotNull($this->app->db()->valor('SELECT ultimo_login_em FROM usuarios WHERE id = ?', [(int) $this->admin['id']]));

        $eu = $c->get('/api/auth/eu');
        $this->assertSame(200, $eu->status);
        $this->assertSame($d['csrf'], $eu->dados()['csrf']);
    }

    public function testCookieSecureEmProducao(): void
    {
        $dir = null;
        $app = AmbienteTeste::criar(['ambiente' => 'prod'], $dir);
        try {
            AmbienteTeste::usuario($app, 'equipe', 'eq@rankly.teste');
            $r = (new ClienteApi($app))->login('eq@rankly.teste', 'senha-forte-123');
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('; Secure', $r->cookies()[0]);
            $this->assertNotNull($r->obterCabecalho('Strict-Transport-Security'));
        } finally {
            AmbienteTeste::remover($dir);
        }
    }

    public function testSessaoMudaDeIdNoLogin(): void
    {
        $c = new ClienteApi($this->app);
        $c->cookies['rk_sessao'] = str_repeat('a', 64); // id plantado por um atacante
        $c->login('admin@rankly.teste', 'senha-forte-123');
        $this->assertNotSame(str_repeat('a', 64), $c->cookies['rk_sessao']);
    }

    public function testSenhaErradaE401SemSessao(): void
    {
        $c = new ClienteApi($this->app);
        $r = $c->login('admin@rankly.teste', 'errada');
        $this->assertSame(401, $r->status);
        $this->assertSame('credenciais', $r->dados()['erro']['codigo']);
        $this->assertSame('E-mail ou senha incorretos.', $r->dados()['erro']['mensagem']);
        $this->assertSame(401, $c->login('ninguem@rankly.teste', 'senha-forte-123')->status);
        $this->assertSame(401, $c->get('/api/auth/eu')->status);
        $this->assertSame(401, $c->get('/api/sites')->status);
    }

    public function testLimiteDeCincoFalhasPorEmailEIp(): void
    {
        $c = new ClienteApi($this->app);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $c->login('admin@rankly.teste', 'errada' . $i)->status);
        }
        $r = $c->login('admin@rankly.teste', 'senha-forte-123');
        $this->assertSame(429, $r->status, 'Mesmo a senha certa é recusada durante o bloqueio');
        $this->assertSame('muitas_tentativas', $r->dados()['erro']['codigo']);
        $this->assertNotNull($r->obterCabecalho('Retry-After'));

        // Outro IP não é afetado pelo bloqueio por e-mail+IP.
        $outro = new ClienteApi($this->app);
        $outro->ip = '198.51.100.7';
        $this->assertSame(200, $outro->login('admin@rankly.teste', 'senha-forte-123')->status);

        // Passados 15 minutos, o primeiro IP volta a entrar.
        $this->app->definirAgora($this->app->agora()->modify('+16 minutes'));
        $this->assertSame(200, $c->login('admin@rankly.teste', 'senha-forte-123')->status);
    }

    public function testCsrfObrigatorioEmRotasQueAlteram(): void
    {
        $c = new ClienteApi($this->app);
        $c->login('admin@rankly.teste', 'senha-forte-123');
        $c->enviarCsrf = false;
        $r = $c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno']);
        $this->assertSame(403, $r->status);
        $this->assertSame('csrf', $r->dados()['erro']['codigo']);
        $this->assertSame(403, $c->post('/api/auth/logout')->status);
        $r = $c->req('POST', '/api/auth/logout', null, ['X-CSRF-Token' => str_repeat('0', 64)]);
        $this->assertSame(403, $r->status);
        $c->enviarCsrf = true;
        $this->assertSame(201, $c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'X']])->status);
    }

    public function testLogoutEncerraSessao(): void
    {
        $c = new ClienteApi($this->app);
        $c->login('admin@rankly.teste', 'senha-forte-123');
        $cookieAntigo = $c->cookies['rk_sessao'];
        $r = $c->post('/api/auth/logout');
        $this->assertSame(200, $r->status);
        $this->assertSame(['ok' => true], $r->dados());
        $this->assertArrayNotHasKey('rk_sessao', $c->cookies);
        $c->cookies['rk_sessao'] = $cookieAntigo; // reutilizar o cookie antigo não funciona
        $this->assertSame(401, $c->get('/api/auth/eu')->status);
    }

    public function testUsuarioDesativadoPerdeASessao(): void
    {
        $c = new ClienteApi($this->app);
        $c->login('admin@rankly.teste', 'senha-forte-123');
        $this->app->db()->atualizar('usuarios', ['ativo' => 0], ['id' => (int) $this->admin['id']]);
        $this->assertSame(401, $c->get('/api/auth/eu')->status);
        $this->assertSame(401, $c->login('admin@rankly.teste', 'senha-forte-123')->status);
    }

    public function testRehashDeSenhaComCustoAntigo(): void
    {
        $antigo = password_hash('senha-forte-123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->app->db()->atualizar('usuarios', ['senha_hash' => $antigo], ['id' => (int) $this->admin['id']]);
        $this->assertSame(200, (new ClienteApi($this->app))->login('admin@rankly.teste', 'senha-forte-123')->status);
        $novo = (string) $this->app->db()->valor('SELECT senha_hash FROM usuarios WHERE id = ?', [(int) $this->admin['id']]);
        $this->assertNotSame($antigo, $novo);
        $this->assertTrue(password_verify('senha-forte-123', $novo));
        $this->assertFalse(password_needs_rehash($novo, PASSWORD_DEFAULT));
    }

    public function testEsqueciERedefinirSenha(): void
    {
        $c = new ClienteApi($this->app);
        $sessaoAntiga = new ClienteApi($this->app);
        $sessaoAntiga->login('admin@rankly.teste', 'senha-forte-123');

        // Sempre {ok:true}, exista ou não o e-mail.
        $this->assertSame(['ok' => true], $c->post('/api/auth/esqueci', ['email' => 'nao-existe@rankly.teste'])->dados());
        $this->assertSame(['ok' => true], $c->post('/api/auth/esqueci', ['email' => 'admin@rankly.teste'])->dados());
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM redefinicoes_senha'));

        // O e-mail sai pela fila; o payload com o token é apagado depois do envio.
        Executores::registrarTodos($this->app);
        $this->assertSame(1, $this->app->tarefas()->processar());
        $this->assertSame('{}', $this->app->db()->valor("SELECT payload FROM tarefas WHERE tipo = 'email_redefinicao'"));
        $emails = AmbienteTeste::emails($this->app);
        $this->assertCount(1, $emails);
        $corpo = AmbienteTeste::corpoEmail($emails[0]);
        $this->assertMatchesRegularExpression('#http://editor\.teste/editor/\#/redefinir\?token=([a-f0-9]{64})#', $corpo);
        preg_match('#token=([a-f0-9]{64})#', $corpo, $m);
        $token = $m[1];

        // Só o hash do token fica no banco.
        $this->assertSame(hash('sha256', $token), $this->app->db()->valor('SELECT token_hash FROM redefinicoes_senha'));
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM redefinicoes_senha WHERE token_hash = ?', [$token]));

        $r = $c->post('/api/auth/redefinir', ['token' => $token, 'senha' => 'curta']);
        $this->assertSame(422, $r->status);
        $r = $c->post('/api/auth/redefinir', ['token' => $token, 'senha' => 'nova-senha-456']);
        $this->assertSame(200, $r->status);
        $this->assertSame(['ok' => true], $r->dados());

        // Uso único.
        $this->assertSame(400, $c->post('/api/auth/redefinir', ['token' => $token, 'senha' => 'outra-senha-789'])->status);
        $this->assertSame(401, $c->login('admin@rankly.teste', 'senha-forte-123')->status);
        $this->assertSame(200, $c->login('admin@rankly.teste', 'nova-senha-456')->status);
        // A sessão aberta com a senha antiga deixa de valer.
        $this->assertSame(401, $sessaoAntiga->get('/api/auth/eu')->status);
    }

    public function testTokenDeRedefinicaoExpiraEmUmaHora(): void
    {
        $c = new ClienteApi($this->app);
        $c->post('/api/auth/esqueci', ['email' => 'admin@rankly.teste']);
        $payload = json_decode((string) $this->app->db()->valor("SELECT payload FROM tarefas WHERE tipo = 'email_redefinicao'"), true);
        $this->app->definirAgora($this->app->agora()->modify('+61 minutes'));
        $r = $c->post('/api/auth/redefinir', ['token' => $payload['token'], 'senha' => 'nova-senha-456']);
        $this->assertSame(400, $r->status);
        $this->assertSame('token_invalido', $r->dados()['erro']['codigo']);
        $this->assertSame(400, $c->post('/api/auth/redefinir', ['token' => 'x', 'senha' => 'nova-senha-456'])->status);
    }

    public function testEsqueciTemLimitePorEmail(): void
    {
        $c = new ClienteApi($this->app);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $c->post('/api/auth/esqueci', ['email' => 'admin@rankly.teste'])->status);
        }
        $this->assertSame(3, (int) $this->app->db()->valor('SELECT COUNT(*) FROM redefinicoes_senha'));
    }

    public function testRotaDesconhecidaEMetodoErrado(): void
    {
        $c = new ClienteApi($this->app);
        $r = $c->get('/api/nao-existe');
        $this->assertSame(404, $r->status);
        $this->assertSame('nao_encontrado', $r->dados()['erro']['codigo']);
        $r = $c->req('DELETE', '/api/auth/login');
        $this->assertSame(405, $r->status);
        $this->assertSame('POST', $r->obterCabecalho('Allow'));
        $r = $c->req('POST', '/api/auth/login', null, ['Content-Type' => 'application/json']);
        $this->assertSame(422, $r->status);
        $req = new \Rankly\Http\Requisicao('POST', '/api/auth/login', [], ['Content-Type' => 'application/json'], '{quebrado', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $r = $c->api->processar($req);
        $this->assertSame(400, $r->status);
        $this->assertSame('application/json; charset=utf-8', $r->obterCabecalho('Content-Type'));
    }
}
