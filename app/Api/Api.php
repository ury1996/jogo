<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Aplicacao;
use Rankly\Http\ErroHttp;
use Rankly\Http\Requisicao;
use Rankly\Http\Resposta;
use Rankly\Http\Router;
use Rankly\Lib\Csrf;
use Rankly\Lib\Executores;
use Rankly\Lib\Sessao;

/**
 * Núcleo da API (/api/*): rotas da tabela do §6, sessão, autenticação, CSRF,
 * erros em JSON (sem detalhes internos em produção) e cabeçalhos de segurança.
 * Usado pelo front controller (public_html/api/index.php) e, nos testes, em processo.
 */
final class Api
{
    private Router $router;

    public function __construct(private readonly Aplicacao $app)
    {
        $this->router = new Router();
        $this->registrarRotas();
    }

    private function registrarRotas(): void
    {
        $r = $this->router;
        $auth = new Auth();
        $sites = new Sites();
        $midia = new Midia();
        $bib = new Biblioteca();
        $leads = new Leads();
        $pub = new Publicacao();
        $versoes = new Versoes();
        $publica = ['publica' => true];
        $semCsrf = ['publica' => true, 'csrf' => false];
        // Parâmetros sem quantificador com chaves (o compilador de rotas não aceita "{n}" dentro de "{…}");
        // o tamanho é conferido nos controladores.
        $id = '{id:\d+}';

        $r->post('/api/auth/login', [$auth, 'login'], $semCsrf);
        $r->post('/api/auth/logout', [$auth, 'logout']);
        $r->get('/api/auth/eu', [$auth, 'eu'], $publica);
        $r->post('/api/auth/esqueci', [$auth, 'esqueci'], $semCsrf);
        $r->post('/api/auth/redefinir', [$auth, 'redefinir'], $semCsrf);

        $r->get('/api/biblioteca', [$bib, 'bundle'], $publica);
        $r->get('/api/fontes/{arquivo}', [$bib, 'fonte'], $publica);

        $r->get('/api/sites', [$sites, 'listar']);
        $r->post('/api/sites', [$sites, 'criar']);
        $r->get("/api/sites/{$id}", [$sites, 'obter']);
        $r->put("/api/sites/{$id}", [$sites, 'salvar']);
        $r->delete("/api/sites/{$id}", [$sites, 'arquivar']);
        $r->post("/api/sites/{$id}/duplicar", [$sites, 'duplicar']);

        $r->post("/api/sites/{$id}/validar", [$pub, 'validar']);
        $r->post("/api/sites/{$id}/publicar", [$pub, 'publicar']);
        $r->post("/api/sites/{$id}/reverter", [$pub, 'reverter']);
        $r->get("/api/sites/{$id}/versoes", [$versoes, 'listar']);
        $r->get("/api/sites/{$id}/versoes/{n:\d+}", [$versoes, 'obter']);

        $r->get("/api/sites/{$id}/leads", [$leads, 'listar']);
        $r->get("/api/sites/{$id}/leads.csv", [$leads, 'csv']);
        $r->patch("/api/leads/{$id}", [$leads, 'marcarLido']);
        $r->delete("/api/leads/{$id}", [$leads, 'excluir']);

        $r->post('/api/media', [$midia, 'enviar']);
        $r->patch('/api/media/{id:m_[0-9a-f]+}', [$midia, 'atualizar']);
        $r->get('/api/media/{id:m_[0-9a-f]+}/{w:orig|\d+}', [$midia, 'servir']);

        $r->post('/api/lead/{slug:[a-z0-9]+}', [$leads, 'receber'], $semCsrf);
        $r->adicionar('OPTIONS', '/api/lead/{slug:[a-z0-9]+}', [$leads, 'preflight'], $semCsrf);
    }

    /** Processa uma requisição e devolve a resposta (nunca lança). */
    public function processar(Requisicao $req): Resposta
    {
        $sessao = null;
        try {
            $rota = $this->router->encontrar($req->metodo, $req->caminho);
            $sessao = Sessao::carregar($this->app, $req->cookie(Sessao::COOKIE));
            $ctx = new Contexto($this->app, $req, $sessao);
            $opcoes = $rota['opcoes'];
            if (!($opcoes['publica'] ?? false)) {
                $ctx->exigirUsuario();
            }
            $altera = !in_array($req->metodo, ['GET', 'HEAD', 'OPTIONS'], true);
            if ($altera && ($opcoes['csrf'] ?? true) && !Csrf::verificar($sessao, $req->cabecalho('x-csrf-token'))) {
                throw new ErroHttp(403, 'csrf', 'A página ficou desatualizada. Recarregue e tente de novo.');
            }
            $resposta = ($rota['acao'])($ctx, $rota['parametros']);
        } catch (ErroHttp $e) {
            $resposta = Resposta::json($e->corpo(), $e->status);
            foreach ($e->cabecalhos as $nome => $valor) {
                $resposta->cabecalho($nome, $valor);
            }
        } catch (\Throwable $e) {
            $resposta = $this->erroInterno($e, $req);
        }

        if ($sessao !== null) {
            try {
                $sessao->salvar($resposta, $this->cookieSeguro($req));
            } catch (\Throwable $e) {
                $resposta = $this->erroInterno($e, $req);
            }
        }
        return $this->cabecalhosSeguranca($resposta);
    }

    /** Executa já as tarefas enfileiradas nesta requisição (ver Executores::processarAposResposta). */
    public function processarTarefasDaRequisicao(): void
    {
        $ids = $this->app->tarefas()->enfileiradasAgora();
        if ($ids === []) {
            return;
        }
        try {
            Executores::registrarTodos($this->app);
            $this->app->tarefas()->processarIds($ids);
        } catch (\Throwable $e) {
            $this->app->log()->excecao($e, ['etapa' => 'tarefas apos resposta']);
        }
    }

    private function erroInterno(\Throwable $e, Requisicao $req): Resposta
    {
        $this->app->log()->excecao($e, ['metodo' => $req->metodo, 'caminho' => $req->caminho]);
        $corpo = ['erro' => ['codigo' => 'erro_interno', 'mensagem' => 'Ocorreu um erro inesperado. Tente de novo em instantes.']];
        if (!$this->app->producao()) {
            $corpo['erro']['detalhe'] = $e::class . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        return Resposta::json($corpo, 500);
    }

    private function cookieSeguro(Requisicao $req): bool
    {
        return $this->app->producao() || $req->seguro();
    }

    private function cabecalhosSeguranca(Resposta $r): Resposta
    {
        $r->cabecalhoPadrao('X-Content-Type-Options', 'nosniff');
        $r->cabecalhoPadrao('Referrer-Policy', 'same-origin');
        $r->cabecalhoPadrao('X-Frame-Options', 'DENY');
        $r->cabecalhoPadrao('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $r->cabecalhoPadrao('Cache-Control', 'no-store');
        if ($this->app->producao()) {
            $r->cabecalhoPadrao('Strict-Transport-Security', 'max-age=31536000');
        }
        return $r;
    }
}
