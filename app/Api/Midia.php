<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Eventos;
use Rankly\Lib\Midia as MidiaLib;

/**
 * Mídia [M15][M26]: envio (multipart), texto alternativo e entrega das variantes
 * (só com sessão e acesso ao site; cache privado).
 */
final class Midia
{
    public function enviar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $app = $ctx->app;
        $form = $ctx->req->form;
        $limiteMb = (int) $app->config('limite_upload_mb', 15);
        if ($form === [] && $ctx->req->arquivos === [] && (int) ($ctx->req->cabecalho('content-length') ?? 0) > 0) {
            // Corpo maior que post_max_size: o PHP descarta campos e arquivos.
            throw new ErroHttp(413, 'arquivo_grande', "O arquivo passa do limite de {$limiteMb} MB.");
        }
        $siteId = is_string($form['site_id'] ?? null) || is_int($form['site_id'] ?? null) ? (string) $form['site_id'] : '';
        if (!ctype_digit($siteId)) {
            throw ErroHttp::invalido('Informe o site da imagem.', ['campo' => 'site_id']);
        }
        $site = $ctx->exigirSite(Contexto::inteiro($siteId));
        if ($site['status'] === 'arquivado') {
            throw ErroHttp::proibido('Este site está arquivado e não pode ser editado.');
        }
        $tipo = is_string($form['tipo'] ?? null) && $form['tipo'] !== '' ? $form['tipo'] : 'foto';
        if (!in_array($tipo, ['foto', 'logo'], true)) {
            throw ErroHttp::invalido('Tipo de imagem inválido (use foto ou logo).', ['campo' => 'tipo']);
        }
        $arquivo = $ctx->req->arquivo('arquivo');
        if ($arquivo === null || (int) $arquivo['error'] === UPLOAD_ERR_NO_FILE) {
            throw ErroHttp::invalido('Nenhum arquivo foi enviado.', ['campo' => 'arquivo']);
        }
        switch ((int) $arquivo['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new ErroHttp(413, 'arquivo_grande', "O arquivo passa do limite de {$limiteMb} MB.");
            case UPLOAD_ERR_PARTIAL:
                throw ErroHttp::requisicaoRuim('O envio foi interrompido. Tente de novo.');
            default:
                throw new \RuntimeException('Falha no upload (código ' . (int) $arquivo['error'] . ').');
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        // Na web, só aceitamos arquivos que vieram de fato pelo upload do PHP.
        if ($tmp === '' || !is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            throw ErroHttp::requisicaoRuim('Arquivo enviado inválido.');
        }
        if ((int) filesize($tmp) > $limiteMb * 1024 * 1024) {
            throw new ErroHttp(413, 'arquivo_grande', "O arquivo passa do limite de {$limiteMb} MB.");
        }
        try {
            $r = MidiaLib::receberArquivo($app, (int) $site['id'], $tipo, $tmp);
        } catch (\InvalidArgumentException $e) {
            throw ErroHttp::invalido($e->getMessage(), ['campo' => 'arquivo'], 'midia_invalida');
        }
        if (!$r['existente']) {
            Eventos::registrar($app, (int) $site['id'], (int) $u['id'], 'midia.enviada', [
                'midia' => $r['midia']['id'], 'tipo' => $tipo, 'formato' => $r['midia']['formato'],
            ]);
        }
        return Resposta::json(['midia' => $r['midia']], 201);
    }

    /** PATCH {alt}: texto alternativo (vazio = automático). */
    public function atualizar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $linha = MidiaLib::porId($ctx->app, $p['id']) ?? throw ErroHttp::naoEncontrado('Imagem não encontrada.');
        $ctx->exigirSite((int) $linha['site_id']);
        $d = $ctx->dados();
        if (!array_key_exists('alt', $d) || ($d['alt'] !== null && !is_string($d['alt']))) {
            throw ErroHttp::invalido('Informe o texto alternativo.', ['campo' => 'alt']);
        }
        $alt = trim(preg_replace('/\s+/u', ' ', (string) $d['alt']) ?? '');
        if (!mb_check_encoding($alt, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $alt)) {
            throw ErroHttp::invalido('Texto alternativo com caracteres inválidos.', ['campo' => 'alt']);
        }
        if (mb_strlen($alt, 'UTF-8') > 160) {
            throw ErroHttp::invalido('O texto alternativo pode ter no máximo 160 caracteres.', ['campo' => 'alt']);
        }
        $ctx->app->db()->atualizar('midia', ['texto_alt' => $alt === '' ? null : $alt], ['id' => (string) $linha['id']]);
        Eventos::registrar($ctx->app, (int) $linha['site_id'], (int) $u['id'], 'midia.alt', ['midia' => (string) $linha['id']]);
        $linha['texto_alt'] = $alt === '' ? null : $alt;
        return Resposta::json(['midia' => MidiaLib::publico($linha)]);
    }

    /** GET /api/media/{id}/{w}: w = largura de uma variante existente ou "orig". */
    public function servir(Contexto $ctx, array $p): Resposta
    {
        $linha = MidiaLib::porId($ctx->app, $p['id']) ?? throw ErroHttp::naoEncontrado('Imagem não encontrada.');
        $ctx->exigirSite((int) $linha['site_id']);
        $variante = MidiaLib::arquivoDaVariante($linha, $p['w']);
        if ($variante === null) {
            throw ErroHttp::naoEncontrado('Tamanho de imagem inexistente.');
        }
        $caminho = MidiaLib::caminho($ctx->app, (int) $linha['site_id'], (string) $linha['id'], $variante['arquivo']);
        if (!is_file($caminho)) {
            throw ErroHttp::naoEncontrado('Arquivo da imagem não encontrado.');
        }
        $r = Resposta::arquivo($caminho, $variante['tipo'], [
            // Cada id/tamanho é imutável: o navegador pode guardar, mas só para este usuário.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Disposition' => 'inline; filename="' . $linha['id'] . '-' . $variante['arquivo'] . '"',
        ]);
        if ($variante['tipo'] === 'image/svg+xml') {
            // [M26] SVG nunca executa script, mesmo se aberto diretamente.
            $r->cabecalho('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'none'; sandbox");
        }
        return $r;
    }
}
