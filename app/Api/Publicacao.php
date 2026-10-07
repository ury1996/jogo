<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Eventos;

/**
 * Validação, publicação e reversão (chama Rankly\Gerador\Gerador, contrato §11.2).
 * Erros mostráveis ao usuário vindos do gerador: ErroValidacao (lista de erros) ou
 * \DomainException / \InvalidArgumentException com mensagem em português.
 */
final class Publicacao
{
    public function validar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $r = $this->gerador($ctx)->validar($site);
        return Resposta::json([
            'erros' => array_values((array) ($r['erros'] ?? [])),
            'avisos' => array_values((array) ($r['avisos'] ?? [])),
            'textosPadraoAlterados' => array_values((array) ($r['textosPadraoAlterados'] ?? [])),
        ]);
    }

    public function publicar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $u = $ctx->exigirUsuario();
        if ($site['status'] === 'arquivado') {
            throw ErroHttp::proibido('Este site está arquivado e não pode ser publicado.');
        }
        $gerador = $this->gerador($ctx);
        $r = $this->comTrava($ctx, (int) $site['id'], function () use ($ctx, $gerador, $site, $u): array {
            try {
                return $gerador->publicar($site, (int) $u['id']);
            } catch (\Throwable $e) {
                if (self::ehErroValidacao($e)) {
                    throw new ErroHttp(422, 'validacao', 'O site tem pendências que impedem a publicação.', [
                        'erros' => array_values((array) $e->erros),
                    ]);
                }
                throw $this->traduzir($ctx, $e, 'publicar');
            }
        });
        Eventos::registrar($ctx->app, (int) $site['id'], (int) $u['id'], 'site.publicado', ['versao' => (int) ($r['versao'] ?? 0)]);
        return Resposta::json(['url' => (string) ($r['url'] ?? $ctx->app->urlSite((string) $site['slug'])), 'versao' => (int) ($r['versao'] ?? 0)]);
    }

    public function reverter(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $u = $ctx->exigirUsuario();
        $gerador = $this->gerador($ctx);
        $r = $this->comTrava($ctx, (int) $site['id'], function () use ($ctx, $gerador, $site, $u): array {
            try {
                return $gerador->reverter($site, (int) $u['id']);
            } catch (\Throwable $e) {
                throw $this->traduzir($ctx, $e, 'reverter');
            }
        });
        Eventos::registrar($ctx->app, (int) $site['id'], (int) $u['id'], 'site.revertido', ['versao' => (int) ($r['versao'] ?? 0)]);
        return Resposta::json(['url' => (string) ($r['url'] ?? $ctx->app->urlSite((string) $site['slug'])), 'versao' => (int) ($r['versao'] ?? 0)]);
    }

    private function gerador(Contexto $ctx): object
    {
        try {
            return $ctx->app->gerador();
        } catch (\RuntimeException $e) {
            $ctx->app->log()->aviso($e->getMessage());
            throw new ErroHttp(503, 'indisponivel', 'A publicação ainda não está disponível neste servidor.');
        }
    }

    private static function ehErroValidacao(\Throwable $e): bool
    {
        return $e instanceof \Rankly\Gerador\ErroValidacao;
    }

    /** Exceção do gerador → ErroHttp (mensagem própria só para erros "de domínio"). */
    private function traduzir(Contexto $ctx, \Throwable $e, string $acao): ErroHttp
    {
        if ($e instanceof ErroHttp) {
            return $e;
        }
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            return new ErroHttp(422, 'nao_permitido', $e->getMessage());
        }
        $ctx->app->log()->excecao($e, ['acao' => $acao]);
        return new ErroHttp(500, 'falha_publicacao', $acao === 'publicar'
            ? 'Não foi possível publicar agora. Tente de novo em instantes; se continuar, avise o suporte.'
            : 'Não foi possível voltar à publicação anterior agora. Tente de novo em instantes.');
    }

    /** Uma publicação/reversão por site por vez (trava em var/locks). */
    private function comTrava(Contexto $ctx, int $siteId, callable $fn): array
    {
        $arquivo = $ctx->app->dirVar('locks') . '/publicar-' . $siteId . '.lock';
        $h = @fopen($arquivo, 'c');
        if ($h === false) {
            return $fn();
        }
        try {
            if (!flock($h, LOCK_EX | LOCK_NB)) {
                throw new ErroHttp(409, 'publicando', 'Já existe uma publicação deste site em andamento. Aguarde alguns segundos.');
            }
            $r = $fn();
            return is_array($r) ? $r : [];
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
