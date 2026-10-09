<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\ErroPexels;
use Rankly\Lib\Eventos;
use Rankly\Lib\LimiteTaxa;
use Rankly\Lib\Midia as MidiaLib;
use Rankly\Lib\Pexels as PexelsLib;

/**
 * Banco de imagens Pexels: GET /api/pexels (disponível?), GET /api/pexels/buscar e
 * POST /api/pexels/importar (baixa a foto para a mídia do site, com origem e crédito do autor).
 * Buscas e importações contam no mesmo limite por usuário por hora (pexels.limite_por_hora).
 */
final class Pexels
{
    public function estado(Contexto $ctx, array $p): Resposta
    {
        $ctx->exigirUsuario();
        return Resposta::json(['disponivel' => $ctx->app->pexels() !== null]);
    }

    public function buscar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $pexels = $this->cliente($ctx);
        $termo = PexelsLib::limparTermo((string) ($ctx->req->parametroQuery('q') ?? ''));
        if ($termo === '') {
            throw ErroHttp::invalido('Digite o que você procura (ex.: "consultório odontológico").', ['campo' => 'q']);
        }
        $paginaTxt = (string) ($ctx->req->parametroQuery('pagina') ?? '1');
        $pagina = ctype_digit($paginaTxt) && strlen($paginaTxt) <= 3 ? max(1, (int) $paginaTxt) : 1;
        $orientacao = $ctx->req->parametroQuery('orientacao');
        if ($orientacao !== null && $orientacao !== '' && PexelsLib::orientacao($orientacao) === null) {
            throw ErroHttp::invalido('Orientação inválida (use paisagem, retrato ou quadrada).', ['campo' => 'orientacao']);
        }

        [$limites, $chave] = $this->consumirLimite($ctx, $u);
        try {
            $r = $pexels->buscar($termo, $pagina, $orientacao === '' ? null : $orientacao);
        } catch (ErroPexels $e) {
            $limites->descontar($chave);
            throw $this->erro($ctx, $e);
        }
        return Resposta::json(['termo' => $termo] + $r);
    }

    /** POST {site_id, foto_id} → 201 {midia} (igual ao upload). */
    public function importar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $app = $ctx->app;
        $d = $ctx->dados();
        $siteId = is_string($d['site_id'] ?? null) || is_int($d['site_id'] ?? null) ? (string) $d['site_id'] : '';
        if (!ctype_digit($siteId)) {
            throw ErroHttp::invalido('Informe o site da imagem.', ['campo' => 'site_id']);
        }
        $site = $ctx->exigirSite(Contexto::inteiro($siteId));
        if ($site['status'] === 'arquivado') {
            throw ErroHttp::proibido('Este site está arquivado e não pode ser editado.');
        }
        $fotoId = is_string($d['foto_id'] ?? null) || is_int($d['foto_id'] ?? null) ? (string) $d['foto_id'] : '';
        if (!ctype_digit($fotoId) || strlen($fotoId) > 12 || (int) $fotoId < 1) {
            throw ErroHttp::invalido('Foto do banco de imagens inválida.', ['campo' => 'foto_id']);
        }
        $pexels = $this->cliente($ctx);

        [$limites, $chave] = $this->consumirLimite($ctx, $u);
        $limiteBytes = (int) $app->config('limite_upload_mb', 15) * 1024 * 1024;
        $tmp = null;
        try {
            $foto = $pexels->foto((int) $fotoId);
            $tmp = $pexels->baixar($foto['download'], $limiteBytes, $app->dirVar('tmp'));
            $r = MidiaLib::receberArquivo($app, (int) $site['id'], 'foto', $tmp);
        } catch (ErroPexels $e) {
            $limites->descontar($chave);
            throw $this->erro($ctx, $e);
        } catch (\InvalidArgumentException $e) {
            $limites->descontar($chave);
            throw ErroHttp::invalido($e->getMessage(), ['campo' => 'foto_id'], 'midia_invalida');
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }

        $midiaId = (string) $r['midia']['id'];
        $linha = MidiaLib::porId($app, $midiaId) ?? throw new \RuntimeException('Mídia importada sumiu.');
        $alteracoes = [];
        if (empty($linha['origem']) || str_starts_with((string) $linha['origem'], 'pexels:')) {
            $alteracoes['origem'] = 'pexels:' . (int) $foto['id'];
            $alteracoes['credito'] = $foto['autor'] !== ''
                ? mb_substr('Foto: ' . $foto['autor'] . ' / Pexels', 0, 160, 'UTF-8')
                : 'Foto: Pexels';
        }
        if (($linha['texto_alt'] ?? null) === null && $foto['alt'] !== '') {
            $alteracoes['texto_alt'] = $foto['alt'];
        }
        if ($alteracoes !== []) {
            $app->db()->atualizar('midia', $alteracoes, ['id' => $midiaId]);
            $linha = $alteracoes + $linha;
        }
        if (!$r['existente']) {
            Eventos::registrar($app, (int) $site['id'], (int) $u['id'], 'midia.pexels', [
                'midia' => $midiaId, 'pexels' => (int) $foto['id'],
            ]);
        }
        return Resposta::json(['midia' => MidiaLib::publico($linha)], 201);
    }

    private function cliente(Contexto $ctx): PexelsLib
    {
        return $ctx->app->pexels() ?? throw new ErroHttp(503, 'pexels_indisponivel',
            'O banco de imagens não está ligado neste servidor. Peça para configurar a chave grátis do Pexels (PEXELS_API_KEY).');
    }

    /** @return array{0: LimiteTaxa, 1: string} */
    private function consumirLimite(Contexto $ctx, array $u): array
    {
        $limites = new LimiteTaxa($ctx->app);
        $chave = LimiteTaxa::chave('pexels', (string) $u['id']);
        $maximo = max(1, (int) $ctx->app->config('pexels.limite_por_hora', 120));
        if (!$limites->consumir($chave, $maximo, 3600)) {
            $limites->descontar($chave);
            throw new ErroHttp(429, 'limite', 'Você usou o banco de imagens muitas vezes na última hora. Tente de novo mais tarde.');
        }
        return [$limites, $chave];
    }

    private function erro(Contexto $ctx, ErroPexels $e): ErroHttp
    {
        if ($e->status >= 500 || $e->status === 429) {
            $ctx->app->log()->aviso('pexels: ' . $e->getMessage(), ['status' => $e->status]);
        }
        return new ErroHttp($e->status, $e->status === 429 ? 'limite' : 'pexels', $e->getMessage());
    }
}
