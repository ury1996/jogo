<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Eventos;
use Rankly\Lib\Ia\ErroIa;
use Rankly\Lib\Ia\GeradorConteudo;
use Rankly\Lib\LimiteTaxa;

/**
 * IA que escreve os textos do site (GET /api/ia e POST /api/sites/{id}/ia).
 * Não salva nada: devolve um patch que o editor aplica como alteração desfazível.
 */
final class Ia
{
    public function estado(Contexto $ctx, array $p): Resposta
    {
        $ctx->exigirUsuario();
        $ia = $ctx->app->ia();
        return Resposta::json(['disponivel' => $ia !== null, 'provedor' => $ia?->nome()]);
    }

    public function gerar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $u = $ctx->exigirUsuario();
        $ia = $ctx->app->ia() ?? throw new ErroHttp(503, 'ia_indisponivel',
            'A IA não está configurada neste servidor. Peça para configurar a chave do Gemini.');

        $dados = $ctx->dados();
        $descricao = is_string($dados['descricao'] ?? null) ? trim($dados['descricao']) : '';
        if (mb_strlen($descricao, 'UTF-8') < 10) {
            throw ErroHttp::invalido('Conte um pouco mais sobre o negócio (pelo menos uma frase).', ['campo' => 'descricao']);
        }
        if (mb_strlen($descricao, 'UTF-8') > GeradorConteudo::MAX_DESCRICAO) {
            throw ErroHttp::invalido('A descrição pode ter até ' . GeradorConteudo::MAX_DESCRICAO . ' caracteres.', ['campo' => 'descricao']);
        }
        $escopo = is_string($dados['escopo'] ?? null) ? $dados['escopo'] : 'site';
        $lib = $ctx->app->biblioteca();
        if ($escopo !== 'site' && !isset($lib['secoes'][$escopo])) {
            throw ErroHttp::invalido('Seção desconhecida.', ['campo' => 'escopo']);
        }

        $limites = new LimiteTaxa($ctx->app);
        $chave = LimiteTaxa::chave('ia', (string) $u['id']);
        $maximo = max(1, (int) $ctx->app->config('ia.limite_por_hora', 30));
        if (!$limites->consumir($chave, $maximo, 3600)) {
            $limites->descontar($chave);
            throw new ErroHttp(429, 'limite', 'Você usou a IA muitas vezes na última hora. Tente de novo mais tarde.');
        }

        try {
            $patch = (new GeradorConteudo($ia))->gerar((array) $site['documento'], $lib, $descricao, $escopo);
        } catch (ErroIa $e) {
            $limites->descontar($chave);
            $ctx->app->log()->aviso('ia: ' . $e->getMessage(), ['site' => (int) $site['id'], 'provedor' => $ia->nome()]);
            throw new ErroHttp($e->temporario ? 503 : 502, 'ia', $e->getMessage());
        }
        Eventos::registrar($ctx->app, (int) $site['id'], (int) $u['id'], 'site.ia', [
            'escopo' => $escopo, 'provedor' => $ia->nome(), 'textos' => count($patch['textos']),
        ]);
        return Resposta::json($patch);
    }
}
