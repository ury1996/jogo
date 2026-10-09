<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Http\ErroHttp;
use Rankly\Http\Resposta;
use Rankly\Lib\Db;
use Rankly\Lib\Eventos;
use Rankly\Lib\FotosExemplo;
use Rankly\Lib\Midia as MidiaLib;
use Rankly\Lib\Sites as SitesLib;
use Rankly\Lib\Slug;
use Rankly\Lib\Usuarios;
use Rankly\Lib\ValidadorDocumento;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Texto;

/**
 * Sites: lista do painel, criação pelo assistente, leitura, salvamento com controle de
 * revisão (409), arquivamento e duplicação.
 */
final class Sites
{
    public function listar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $app = $ctx->app;
        $incluirArquivados = $ctx->req->parametroQuery('arquivados') === '1';
        $sql = 'SELECT s.id, s.slug, s.nome, s.nicho, s.modelo, s.status, s.atualizado_em, s.publicado_em,'
            . ' (SELECT COUNT(*) FROM leads l WHERE l.site_id = s.id AND l.lido_em IS NULL) AS leads_nao_lidos'
            . ' FROM sites s';
        $params = [];
        if (!Usuarios::ehEquipe($u)) {
            $sql .= ' INNER JOIN site_acessos a ON a.site_id = s.id AND a.usuario_id = ?';
            $params[] = (int) $u['id'];
        }
        if (!$incluirArquivados) {
            $sql .= " WHERE s.status <> 'arquivado'";
        }
        $sql .= ' ORDER BY s.atualizado_em DESC, s.id DESC';
        $sites = [];
        foreach ($app->db()->todos($sql, $params) as $s) {
            $sites[] = [
                'id' => (int) $s['id'],
                'slug' => (string) $s['slug'],
                'nome' => (string) $s['nome'],
                'nicho' => (string) $s['nicho'],
                'modelo' => (string) $s['modelo'],
                'status' => (string) $s['status'],
                'atualizadoEm' => SitesLib::iso($s['atualizado_em']),
                'publicadoEm' => SitesLib::iso($s['publicado_em']),
                'url' => $app->urlSite((string) $s['slug']),
                'leadsNaoLidos' => (int) $s['leads_nao_lidos'],
            ];
        }
        return Resposta::json(['sites' => $sites]);
    }

    public function criar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirEquipe();
        $app = $ctx->app;
        $d = $ctx->dados();
        $lib = $app->biblioteca();
        $nichoId = $d['nicho'] ?? null;
        $modeloId = $d['modelo'] ?? null;
        if (!is_string($nichoId) || !isset($lib['nichos'][$nichoId])) {
            throw ErroHttp::invalido('Escolha um tipo de negócio válido.', ['campo' => 'nicho']);
        }
        if (!is_string($modeloId) || !isset($lib['modelos'][$modeloId])
            || (is_string($nichoId ?? null) && !Documento::modeloDoNicho($lib, $modeloId, $nichoId))) {
            throw ErroHttp::invalido('Escolha um modelo válido.', ['campo' => 'modelo']);
        }
        $nicho = $lib['nichos'][$nichoId];
        $esp = $d['especialidade'] ?? null;
        if ($esp !== null && $esp !== '') {
            $ids = array_column(array_filter((array) ($nicho['especialidades'] ?? []), 'is_array'), 'id');
            if (!is_string($esp) || !in_array($esp, $ids, true)) {
                throw ErroHttp::invalido('Escolha uma especialidade válida.', ['campo' => 'especialidade']);
            }
        }
        foreach (['dados', 'estilo'] as $campo) {
            $v = $d[$campo] ?? null;
            if ($v !== null && (!is_array($v) || ($v !== [] && array_is_list($v)))) {
                throw ErroHttp::invalido('Dados do site em formato inválido.', ['campo' => $campo]);
            }
        }
        $estilo = (array) ($d['estilo'] ?? []);
        if (array_key_exists('cor', $estilo) && (!is_string($estilo['cor']) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $estilo['cor']))) {
            throw ErroHttp::invalido('Cor principal inválida (use o formato #rrggbb).', ['campo' => 'estilo.cor']);
        }
        $dados = (array) ($d['dados'] ?? []);
        $dados['logo'] = null; // o logo é enviado depois que o site existe (POST /api/media)

        try {
            $doc = Documento::criarDocumento([
                'nicho' => $nichoId, 'especialidade' => $esp, 'modelo' => $modeloId, 'dados' => $dados, 'estilo' => $estilo,
            ], $lib);
        } catch (\InvalidArgumentException $e) {
            throw ErroHttp::invalido('Não foi possível criar o site com essas escolhas.');
        }
        $doc = ValidadorDocumento::validar($app, $doc, null);

        $nome = Texto::colapsarEspacos($doc['dados']['nome'] ?? '');
        if ($nome === '') {
            $nome = Texto::colapsarEspacos($nicho['exemplo']['nome'] ?? '') ?: 'Meu site';
        }
        $id = $this->inserirSite($ctx, $nome, $doc, (int) $u['id']);
        // O site já nasce com fotos de exemplo em todos os espaços (o dono troca depois).
        if (($d['fotosExemplo'] ?? true) !== false) {
            try {
                $doc = FotosExemplo::preencher($app, $id, $doc, $lib)['doc'];
                $app->db()->executar('UPDATE sites SET documento = ? WHERE id = ?', [Documento::json($doc), $id]);
            } catch (\Throwable $e) {
                $app->log()->excecao($e, ['etapa' => 'fotos de exemplo', 'site' => $id]);
            }
        }
        Eventos::registrar($app, $id, (int) $u['id'], 'site.criado', ['nicho' => $nichoId, 'modelo' => $modeloId]);
        $site = SitesLib::porId($app, $id);
        $midia = MidiaLib::mapaDoSite($app, $id);
        return Resposta::json(['site' => SitesLib::publico($app, $site), 'midia' => $midia === [] ? new \stdClass() : $midia], 201);
    }

    /**
     * Preenche os espaços de imagem vazios com fotos de exemplo (depois de trocar de modelo ou de
     * opção, por exemplo). Salva como uma revisão nova; responde {revisao, preenchidos, midia}.
     */
    public function fotosExemplo(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirUsuario();
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $app = $ctx->app;
        $revisao = $ctx->dados()['revisao'] ?? null;
        if (!is_int($revisao)) {
            throw ErroHttp::invalido('Informe a revisão do documento.', ['campo' => 'revisao']);
        }
        if ($revisao !== (int) $site['revisao']) {
            throw $this->conflito($ctx, $site);
        }
        $r = FotosExemplo::preencher($app, (int) $site['id'], $site['documento'], $app->biblioteca());
        $nova = $revisao;
        if ($r['preenchidos'] > 0) {
            $nova = $revisao + 1;
            $ok = $app->db()->executar(
                'UPDATE sites SET documento = ?, revisao = ?, atualizado_em = ?, atualizado_por = ? WHERE id = ? AND revisao = ?',
                [Documento::json($r['doc']), $nova, $app->agoraSql(), (int) $u['id'], (int) $site['id'], $revisao],
            );
            if ($ok === 0) {
                throw $this->conflito($ctx, SitesLib::porId($app, (int) $site['id']) ?? $site);
            }
        }
        return Resposta::json([
            'revisao' => $nova,
            'preenchidos' => $r['preenchidos'],
            'documento' => SitesLib::documentoParaJson($r['doc']),
            'midia' => (object) MidiaLib::mapaDoSite($app, (int) $site['id']),
        ]);
    }

    public function obter(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $midia = MidiaLib::mapaDoSite($ctx->app, (int) $site['id']);
        return Resposta::json([
            'site' => SitesLib::publico($ctx->app, $site),
            'midia' => $midia === [] ? new \stdClass() : $midia,
        ]);
    }

    public function salvar(Contexto $ctx, array $p): Resposta
    {
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $app = $ctx->app;
        $u = $ctx->exigirUsuario();
        if ($site['status'] === 'arquivado') {
            throw ErroHttp::proibido('Este site está arquivado e não pode ser editado.');
        }
        $d = $ctx->dados();
        $revisao = $d['revisao'] ?? null;
        if (!is_int($revisao)) {
            throw ErroHttp::invalido('Informe a revisão do documento.', ['campo' => 'revisao']);
        }
        if (!array_key_exists('documento', $d)) {
            throw ErroHttp::invalido('Informe o documento do site.', ['campo' => 'documento']);
        }
        if ($revisao !== (int) $site['revisao']) {
            throw $this->conflito($ctx, $site);
        }
        $doc = ValidadorDocumento::validar($app, $d['documento'], (int) $site['id']);
        $antes = $site['documento'];
        if (Documento::json($doc) === Documento::json(Documento::migrar($antes, $app->biblioteca()))) {
            return Resposta::json(['revisao' => (int) $site['revisao']]); // nada mudou
        }

        $nomeAntes = Texto::colapsarEspacos($antes['dados']['nome'] ?? '');
        $nomeDepois = Texto::colapsarEspacos($doc['dados']['nome'] ?? '');
        $nome = ($nomeDepois !== '' && $nomeDepois !== $nomeAntes) ? $nomeDepois : (string) $site['nome'];
        $nova = $revisao + 1;
        $n = $app->db()->executar(
            'UPDATE sites SET documento = ?, revisao = ?, nome = ?, nicho = ?, modelo = ?, atualizado_em = ?, atualizado_por = ?'
            . ' WHERE id = ? AND revisao = ?',
            [Documento::json($doc), $nova, mb_substr($nome, 0, 120), $doc['nicho'], $doc['modelo'], $app->agoraSql(), (int) $u['id'], (int) $site['id'], $revisao],
        );
        if ($n === 0) {
            // Outra aba salvou entre a leitura e a gravação.
            throw $this->conflito($ctx, SitesLib::porId($app, (int) $site['id']) ?? $site);
        }
        Eventos::registrarSalvamento($app, (int) $site['id'], (int) $u['id'], $nova, Eventos::diferencas($antes, $doc));
        return Resposta::json(['revisao' => $nova]);
    }

    public function arquivar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirEquipe();
        $site = $ctx->exigirSite(Contexto::inteiro($p['id']));
        if ($site['status'] !== 'arquivado') {
            $ctx->app->db()->atualizar('sites', [
                'status' => 'arquivado', 'atualizado_em' => $ctx->app->agoraSql(), 'atualizado_por' => (int) $u['id'],
            ], ['id' => (int) $site['id']]);
            Eventos::registrar($ctx->app, (int) $site['id'], (int) $u['id'], 'site.arquivado', ['statusAnterior' => $site['status']]);
        }
        return Resposta::json(['ok' => true]);
    }

    /** Cópia do documento e de toda a mídia (com ids novos) num site novo "Cópia de …". */
    public function duplicar(Contexto $ctx, array $p): Resposta
    {
        $u = $ctx->exigirEquipe();
        $origem = $ctx->exigirSite(Contexto::inteiro($p['id']));
        $app = $ctx->app;
        $nome = mb_substr('Cópia de ' . $origem['nome'], 0, 120);
        $pastasCriadas = [];
        try {
            $novoId = $app->db()->transacao(function () use ($ctx, $app, $origem, $nome, $u, &$pastasCriadas): int {
                $doc = $origem['documento'];
                $novoId = $this->inserirSite($ctx, $nome, $doc, (int) $u['id']);
                $mapa = [];
                foreach ($app->db()->todos('SELECT * FROM midia WHERE site_id = ?', [(int) $origem['id']]) as $linha) {
                    $mapa[(string) $linha['id']] = MidiaLib::copiar($app, $linha, $novoId);
                    $pastasCriadas[] = MidiaLib::pasta($app, $novoId, $mapa[(string) $linha['id']]);
                }
                if ($mapa !== []) {
                    if (is_string($doc['dados']['logo'] ?? null) && isset($mapa[$doc['dados']['logo']])) {
                        $doc['dados']['logo'] = $mapa[$doc['dados']['logo']];
                    }
                    foreach ((array) ($doc['imagens'] ?? []) as $chave => $mid) {
                        if (is_string($mid) && isset($mapa[$mid])) {
                            $doc['imagens'][$chave] = $mapa[$mid];
                        }
                    }
                    $app->db()->atualizar('sites', ['documento' => Documento::json(Documento::migrar($doc, $app->biblioteca()))], ['id' => $novoId]);
                }
                return $novoId;
            });
        } catch (\Throwable $e) {
            foreach ($pastasCriadas as $pasta) {
                MidiaLib::apagarPasta($pasta);
            }
            throw $e;
        }
        Eventos::registrar($app, $novoId, (int) $u['id'], 'site.duplicado', ['origem' => (int) $origem['id']]);
        Eventos::registrar($app, (int) $origem['id'], (int) $u['id'], 'site.copiado', ['copia' => $novoId]);
        $site = SitesLib::porId($app, $novoId);
        return Resposta::json(['site' => SitesLib::publico($app, $site)], 201);
    }

    /** Insere o site com slug único (tenta de novo se outra criação simultânea pegar o slug). */
    private function inserirSite(Contexto $ctx, string $nome, array $doc, int $usuarioId): int
    {
        $app = $ctx->app;
        for ($tentativa = 0; ; $tentativa++) {
            $slug = SitesLib::slugUnico($app, $nome);
            $agora = $app->agoraSql();
            try {
                return $app->db()->inserir('sites', [
                    'dono_id' => $usuarioId,
                    'slug' => $slug,
                    'nome' => mb_substr($nome, 0, 120),
                    'nicho' => (string) $doc['nicho'],
                    'modelo' => (string) $doc['modelo'],
                    'documento' => Documento::json($doc),
                    'revisao' => 1,
                    'status' => 'rascunho',
                    'publicado_versao' => null,
                    'publicado_em' => null,
                    'criado_em' => $agora,
                    'atualizado_em' => $agora,
                    'atualizado_por' => $usuarioId,
                ]);
            } catch (\PDOException $e) {
                if ($tentativa >= 3 || !Db::ehDuplicidade($e) || !Slug::valido($slug)) {
                    throw $e;
                }
            }
        }
    }

    private function conflito(Contexto $ctx, array $site): ErroHttp
    {
        return new ErroHttp(409, 'conflito', 'Este site foi alterado em outra janela.', [
            'revisaoAtual' => (int) $site['revisao'],
            'documento' => SitesLib::documentoParaJson(is_array($site['documento']) ? $site['documento'] : []),
        ]);
    }
}
