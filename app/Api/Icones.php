<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\ErroBancoImagens;
use Rankly\Lib\Iconify;
use Rankly\Lib\LimiteTaxa;

/**
 * Ícones do Iconify para o seletor de ícones do editor: GET /api/icones/buscar?q=…&dicas=a,b.
 * O servidor busca, filtra as coleções e devolve o SVG já no formato aceito no documento
 * (doc.iconesExtras). Conta no limite por usuário por hora (iconify.limite_por_hora).
 */
final class Icones
{
    public function buscar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $iconify = $ctx->app->iconify() ?? throw new ErroHttp(503, 'iconify_desligado', 'A busca de ícones do Iconify está desligada neste servidor.');
        $termo = Iconify::limparTermo((string) ($ctx->req->parametroQuery('q') ?? ''));
        if ($termo === '') {
            throw ErroHttp::invalido('Digite o que você procura (ex.: "dente", "balança", "casa").', ['campo' => 'q']);
        }
        $dicas = array_slice(array_filter(
            explode(',', (string) ($ctx->req->parametroQuery('dicas') ?? '')),
            static fn (string $d): bool => preg_match('/^[a-z0-9-]{1,40}$/D', $d) === 1,
        ), 0, Iconify::MAX_DICAS);

        $limites = new LimiteTaxa($ctx->app);
        $chave = LimiteTaxa::chave('iconify', (string) $u['id']);
        if (!$limites->consumir($chave, max(1, (int) $ctx->app->config('iconify.limite_por_hora', 300)), 3600)) {
            $limites->descontar($chave);
            throw new ErroHttp(429, 'limite', 'Você buscou ícones muitas vezes na última hora. Tente de novo mais tarde.');
        }
        try {
            $r = $iconify->buscar($termo, array_values($dicas));
        } catch (ErroBancoImagens $e) {
            $limites->descontar($chave);
            if ($e->status >= 500 || $e->status === 429) {
                $ctx->app->log()->aviso('iconify: ' . $e->getMessage(), ['status' => $e->status]);
            }
            throw new ErroHttp($e->status, $e->status === 429 ? 'limite' : 'iconify', $e->getMessage());
        }
        return Resposta::json(['termo' => $termo] + $r);
    }
}
