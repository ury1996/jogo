<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Sites as SitesLib;
use Rankly\Preparo\Documento;

/**
 * Histórico de publicações: lista e documento de uma versão (o editor aplica como
 * alteração desfazível, sem publicar).
 */
final class Versoes
{
    public function listar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $linhas = $ctx->app->db()->todos(
            'SELECT v.numero, v.publicado_em, v.publicado_por, u.nome AS publicado_por_nome'
            . ' FROM versoes v LEFT JOIN usuarios u ON u.id = v.publicado_por'
            . ' WHERE v.site_id = ? ORDER BY v.numero DESC',
            [(int) $site['id']],
        );
        $versoes = array_map(static fn (array $v): array => [
            'numero' => (int) $v['numero'],
            'publicadoEm' => SitesLib::iso($v['publicado_em']),
            'publicadoPor' => $v['publicado_por_nome'] !== null ? (string) $v['publicado_por_nome'] : null,
            'atual' => $site['publicado_versao'] !== null && (int) $v['numero'] === (int) $site['publicado_versao'],
        ], $linhas);
        return Resposta::json(['versoes' => $versoes]);
    }

    public function obter(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $n = Contexto::inteiro($p['n']);
        $v = $ctx->app->db()->um('SELECT numero, documento, publicado_em FROM versoes WHERE site_id = ? AND numero = ?', [(int) $site['id'], $n]);
        if ($v === null) {
            throw ErroHttp::naoEncontrado('Versão não encontrada.');
        }
        $doc = json_decode((string) $v['documento'], true);
        $doc = Documento::migrar(is_array($doc) ? $doc : [], $ctx->app->biblioteca());
        return Resposta::json([
            'numero' => (int) $v['numero'],
            'publicadoEm' => SitesLib::iso($v['publicado_em']),
            'documento' => SitesLib::documentoParaJson($doc),
        ]);
    }
}
