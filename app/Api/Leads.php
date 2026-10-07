<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Aplicacao;
use Rankly\Http\ErroHttp;
use Rankly\Http\Requisicao;
use Rankly\Http\Resposta;
use Rankly\Lib\Eventos;
use Rankly\Lib\IpHash;
use Rankly\Lib\Leads as LeadsLib;
use Rankly\Lib\Sites as SitesLib;
use Rankly\Preparo\Texto;

/**
 * Leads: recebimento público (POST /api/lead/{slug}; o /_lead dos sites usa
 * responderPublico) e painel (lista paginada, lido, exclusão LGPD, CSV).
 */
final class Leads
{
    public const POR_PAGINA = 50;

    public function listar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $db = $ctx->app->db();
        $pagina = $ctx->req->parametroQuery('pagina');
        $pagina = $pagina !== null && ctype_digit($pagina) && (int) $pagina >= 1 && strlen($pagina) < 7 ? (int) $pagina : 1;
        $total = (int) $db->valor('SELECT COUNT(*) FROM leads WHERE site_id = ?', [(int) $site['id']]);
        $naoLidos = (int) $db->valor('SELECT COUNT(*) FROM leads WHERE site_id = ? AND lido_em IS NULL', [(int) $site['id']]);
        $offset = ($pagina - 1) * self::POR_PAGINA;
        $linhas = $db->todos(
            'SELECT * FROM leads WHERE site_id = ? ORDER BY criado_em DESC, id DESC LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            [(int) $site['id']],
        );
        return Resposta::json([
            'leads' => array_map([LeadsLib::class, 'publico'], $linhas),
            'total' => $total,
            'naoLidos' => $naoLidos,
            'pagina' => $pagina,
            'porPagina' => self::POR_PAGINA,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
        ]);
    }

    public function csv(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $app = $ctx->app;
        $linhas = $app->db()->todos('SELECT * FROM leads WHERE site_id = ? ORDER BY criado_em DESC, id DESC', [(int) $site['id']]);
        Eventos::registrar($app, (int) $site['id'], $ctx->usuarioId(), 'leads.exportados', ['quantidade' => count($linhas)]);
        $nome = 'leads-' . $site['slug'] . '-' . $app->agora()->format('Y-m-d') . '.csv';
        return Resposta::texto(LeadsLib::csv($linhas), 200, 'text/csv; charset=utf-8')
            ->cabecalho('Content-Disposition', 'attachment; filename="' . $nome . '"');
    }

    public function marcarLido(Contexto $ctx, array $p): Resposta
    {
        $lead = $this->exigirLead($ctx, Contexto::inteiro($p['id']));
        $d = $ctx->dados();
        if (!is_bool($d['lido'] ?? null)) {
            throw ErroHttp::invalido('Informe se o contato foi lido (lido: true ou false).', ['campo' => 'lido']);
        }
        $lidoEm = $d['lido'] ? ($lead['lido_em'] ?? $ctx->app->agoraSql()) : null;
        $ctx->app->db()->atualizar('leads', ['lido_em' => $lidoEm], ['id' => (int) $lead['id']]);
        $lead['lido_em'] = $lidoEm;
        return Resposta::json(['lead' => LeadsLib::publico($lead)]);
    }

    /** Exclusão definitiva (pedido do titular, LGPD). O evento não guarda dados pessoais. */
    public function excluir(Contexto $ctx, array $p): Resposta
    {
        $lead = $this->exigirLead($ctx, Contexto::inteiro($p['id']));
        $ctx->app->db()->executar('DELETE FROM leads WHERE id = ?', [(int) $lead['id']]);
        Eventos::registrar($ctx->app, (int) $lead['site_id'], $ctx->usuarioId(), 'lead.excluido', ['lead' => (int) $lead['id']]);
        return Resposta::json(['ok' => true]);
    }

    /** POST /api/lead/{slug} (público). */
    public function receber(Contexto $ctx, array $p): Resposta
    {
        $app = $ctx->app;
        $site = SitesLib::porSlug($app, $p['slug']);
        if ($site === null) {
            $erro = ErroHttp::naoEncontrado('Site não encontrado.');
            return $ctx->req->querJson()
                ? Resposta::json($erro->corpo() + ['ok' => false], 404)
                : self::paginaErro('Site não encontrado.', 404, null, '/');
        }
        $resposta = self::responderPublico($app, $ctx->req, $site, $app->urlSite((string) $site['slug']) . '/obrigado/');
        return self::cors($app, $ctx->req, $site, $resposta);
    }

    /** OPTIONS /api/lead/{slug}: pré-verificação CORS para fetch a partir do site. */
    public function preflight(Contexto $ctx, array $p): Resposta
    {
        $site = SitesLib::porSlug($ctx->app, $p['slug']);
        $r = Resposta::vazia(204);
        if ($site !== null) {
            self::cors($ctx->app, $ctx->req, $site, $r);
            if ($r->obterCabecalho('Access-Control-Allow-Origin') !== null) {
                $r->cabecalho('Access-Control-Allow-Methods', 'POST');
                $r->cabecalho('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With');
                $r->cabecalho('Access-Control-Max-Age', '86400');
            }
        }
        return $r;
    }

    /**
     * Recebe o lead e responde: JSON {ok:true} para fetch/XHR; sem JavaScript, 303 para a
     * página de agradecimento (ou uma página simples com o erro). Usado também por sites/_lead.php.
     */
    public static function responderPublico(Aplicacao $app, Requisicao $req, array $site, string $urlObrigado): Resposta
    {
        $pos = strrpos(rtrim($urlObrigado, '/'), '/');
        $urlVoltar = ($pos !== false ? substr($urlObrigado, 0, $pos) : '') . '/';
        try {
            $campos = $req->dados();
        } catch (ErroHttp $e) {
            $campos = null;
            $falha = $e;
        }
        if ($campos === null) {
            return $req->querJson()
                ? Resposta::json($falha->corpo() + ['ok' => false], $falha->status)
                : self::paginaErro($falha->getMessage(), $falha->status, $site, $urlVoltar);
        }
        $r = LeadsLib::receber($app, $site, $campos, IpHash::ipDe($app, $req), $req->userAgent());
        if ($req->querJson()) {
            if ($r['ok']) {
                return Resposta::json(['ok' => true]);
            }
            $corpo = ['ok' => false, 'erro' => ['codigo' => $r['erro'] ?? 'invalido', 'mensagem' => $r['mensagem'] ?? 'Não foi possível enviar.']];
            if (isset($r['campo'])) {
                $corpo['campo'] = $r['campo'];
            }
            $resp = Resposta::json($corpo, (int) ($r['status'] ?? 422));
            if (($r['status'] ?? 0) === 429) {
                $resp->cabecalho('Retry-After', '3600');
            }
            return $resp;
        }
        if ($r['ok']) {
            return Resposta::redirecionar($urlObrigado, 303);
        }
        return self::paginaErro($r['mensagem'] ?? 'Não foi possível enviar.', (int) ($r['status'] ?? 422), $site, $urlVoltar);
    }

    /** Página mínima (sem JavaScript) para erro no envio do formulário sem fetch. */
    public static function paginaErro(string $mensagem, int $status, ?array $site, string $urlVoltar = '/'): Resposta
    {
        $nome = $site !== null ? (Texto::textoDe($site['documento']['dados']['nome'] ?? '') ?: (string) $site['nome']) : '';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Não foi possível enviar</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1.5rem;color:#14161a}'
            . 'a{color:inherit}</style></head><body>'
            . '<h1>Não foi possível enviar</h1><p>' . $e($mensagem) . '</p>'
            . '<p><a href="' . $e($urlVoltar) . '">Voltar ao site' . ($nome !== '' ? ' ' . $e($nome) : '') . '</a></p>'
            . '</body></html>';
        return Resposta::texto($html, $status, 'text/html; charset=utf-8')
            ->cabecalho('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    }

    /** CORS só para a origem do próprio site (subdomínio ou domínio próprio ativo). */
    private static function cors(Aplicacao $app, Requisicao $req, array $site, Resposta $r): Resposta
    {
        $origem = $req->cabecalho('origin');
        if ($origem === null || $origem === '') {
            return $r;
        }
        $permitidas = [$app->urlSite((string) $site['slug'])];
        foreach ($app->db()->todos("SELECT dominio FROM dominios WHERE site_id = ? AND status = 'ativo'", [(int) $site['id']]) as $d) {
            $permitidas[] = 'https://' . $d['dominio'];
            $permitidas[] = 'https://www.' . $d['dominio'];
        }
        if (in_array(rtrim(strtolower($origem), '/'), array_map('strtolower', $permitidas), true)) {
            $r->cabecalho('Access-Control-Allow-Origin', $origem);
            $r->cabecalho('Vary', 'Origin');
        }
        return $r;
    }

    private function exigirLead(Contexto $ctx, int $id): array
    {
        $ctx->exigirUsuario();
        $lead = $ctx->app->db()->um('SELECT * FROM leads WHERE id = ?', [$id]);
        if ($lead === null) {
            throw ErroHttp::naoEncontrado('Contato não encontrado.');
        }
        $ctx->exigirSite((int) $lead['site_id']); // 404 se não tiver acesso
        return $lead;
    }
}
