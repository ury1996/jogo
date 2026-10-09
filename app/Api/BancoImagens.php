<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\ErroBancoImagens;
use Rankly\Lib\Eventos;
use Rankly\Lib\LimiteTaxa;
use Rankly\Lib\Midia as MidiaLib;
use Rankly\Lib\Pixabay;

/**
 * Banco de imagens (Pixabay): GET /api/banco-imagens (disponível?), GET /api/banco-imagens/buscar e
 * POST /api/banco-imagens/importar (baixa a foto para a mídia do site, com origem e crédito do autor).
 * Buscas e importações contam no mesmo limite por usuário por hora (pixabay.limite_por_hora).
 */
final class BancoImagens
{
    public function estado(Contexto $ctx, array $p): Resposta
    {
        $ctx->exigirUsuario();
        return Resposta::json(['disponivel' => $ctx->app->bancoImagens() !== null]);
    }

    public function buscar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $banco = $this->cliente($ctx);
        $termo = Pixabay::limparTermo((string) ($ctx->req->parametroQuery('q') ?? ''));
        if ($termo === '') {
            throw ErroHttp::invalido('Digite o que você procura (ex.: "consultório odontológico").', ['campo' => 'q']);
        }
        $paginaTxt = (string) ($ctx->req->parametroQuery('pagina') ?? '1');
        $pagina = ctype_digit($paginaTxt) && strlen($paginaTxt) <= 3 ? max(1, (int) $paginaTxt) : 1;
        $orientacao = $ctx->req->parametroQuery('orientacao');
        if ($orientacao !== null && $orientacao !== '' && !Pixabay::orientacaoValida($orientacao)) {
            throw ErroHttp::invalido('Orientação inválida (use paisagem ou retrato).', ['campo' => 'orientacao']);
        }

        [$limites, $chave] = $this->consumirLimite($ctx, $u);
        try {
            $r = $banco->buscar($termo, $pagina, $orientacao === '' ? null : $orientacao);
        } catch (ErroBancoImagens $e) {
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
        $banco = $this->cliente($ctx);

        [$limites, $chave] = $this->consumirLimite($ctx, $u);
        $limiteBytes = (int) $app->config('limite_upload_mb', 15) * 1024 * 1024;
        $tmp = null;
        try {
            $foto = $banco->foto((int) $fotoId);
            $tmp = $banco->baixar($foto['download'], $limiteBytes, $app->dirVar('tmp'));
            $r = MidiaLib::receberArquivo($app, (int) $site['id'], 'foto', $tmp);
        } catch (ErroBancoImagens $e) {
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
        if (empty($linha['origem']) || str_starts_with((string) $linha['origem'], 'pixabay:')) {
            $alteracoes['origem'] = 'pixabay:' . (int) $foto['id'];
            $alteracoes['credito'] = $foto['autor'] !== ''
                ? mb_substr('Imagem de ' . $foto['autor'] . ' por Pixabay', 0, 160, 'UTF-8')
                : 'Imagem do Pixabay';
        }
        if (($linha['texto_alt'] ?? null) === null && $foto['alt'] !== '') {
            $alteracoes['texto_alt'] = $foto['alt'];
        }
        if ($alteracoes !== []) {
            $app->db()->atualizar('midia', $alteracoes, ['id' => $midiaId]);
            $linha = $alteracoes + $linha;
        }
        if (!$r['existente']) {
            Eventos::registrar($app, (int) $site['id'], (int) $u['id'], 'midia.banco', [
                'midia' => $midiaId, 'pixabay' => (int) $foto['id'],
            ]);
        }
        return Resposta::json(['midia' => MidiaLib::publico($linha)], 201);
    }

    private function cliente(Contexto $ctx): Pixabay
    {
        return $ctx->app->bancoImagens() ?? throw new ErroHttp(503, 'banco_indisponivel',
            'O banco de imagens não está ligado neste servidor. Peça para configurar a chave grátis do Pixabay (PIXABAY_API_KEY).');
    }

    /** @return array{0: LimiteTaxa, 1: string} */
    private function consumirLimite(Contexto $ctx, array $u): array
    {
        $limites = new LimiteTaxa($ctx->app);
        $chave = LimiteTaxa::chave('banco-imagens', (string) $u['id']);
        $maximo = max(1, (int) $ctx->app->config('pixabay.limite_por_hora', 120));
        if (!$limites->consumir($chave, $maximo, 3600)) {
            $limites->descontar($chave);
            throw new ErroHttp(429, 'limite', 'Você usou o banco de imagens muitas vezes na última hora. Tente de novo mais tarde.');
        }
        return [$limites, $chave];
    }

    private function erro(Contexto $ctx, ErroBancoImagens $e): ErroHttp
    {
        if ($e->status >= 500 || $e->status === 429) {
            $ctx->app->log()->aviso('banco de imagens: ' . $e->getMessage(), ['status' => $e->status]);
        }
        return new ErroHttp($e->status, $e->status === 429 ? 'limite' : 'banco_imagens', $e->getMessage());
    }
}
