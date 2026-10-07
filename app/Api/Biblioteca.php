<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;

/**
 * Biblioteca para o editor: bundle (§6.1) com ETag = versão, e fontes .woff2 com cache longo.
 * Rotas públicas: o conteúdo é o mesmo que vai para os sites publicados.
 */
final class Biblioteca
{
    public function bundle(Contexto $ctx, array $p): Resposta
    {
        $b = $ctx->app->bundleBiblioteca();
        $etag = '"' . $b['versao'] . '"';
        $cab = ['ETag' => $etag, 'Cache-Control' => 'no-cache'];
        if (self::etagConfere($ctx->req->cabecalho('if-none-match'), $etag)) {
            return new Resposta(304, '', $cab);
        }
        return new Resposta(200, $b['json'], $cab + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function fonte(Contexto $ctx, array $p): Resposta
    {
        $arquivo = $p['arquivo'];
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,100}\.woff2$/D', $arquivo) || str_contains($arquivo, '..')) {
            throw ErroHttp::naoEncontrado('Fonte não encontrada.');
        }
        $caminho = $ctx->app->dir('biblioteca') . '/fontes/' . $arquivo;
        if (!is_file($caminho)) {
            throw ErroHttp::naoEncontrado('Fonte não encontrada.');
        }
        return Resposta::arquivo($caminho, 'font/woff2', [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private static function etagConfere(?string $cabecalho, string $etag): bool
    {
        if ($cabecalho === null) {
            return false;
        }
        foreach (explode(',', $cabecalho) as $v) {
            $v = trim($v);
            if ($v === '*' || $v === $etag || $v === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }
}
