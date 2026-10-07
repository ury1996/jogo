<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Aplicacao;
use Rankly\Preparo\Dados;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Paleta;
use Rankly\Preparo\Texto;
use Rankly\Preparo\Textos;

/**
 * Regras de publicação (contrato §7 passo 1, §2.4, [M5][M6]). Erros bloqueiam a publicação;
 * avisos só informam. Cada item: {codigo, mensagem (pronta para o usuário), chave?, secao?, …}.
 */
final class Validador
{
    public const RE_GTM = '/^GTM-[A-Z0-9]{4,12}$/D';
    public const RE_GA4 = '/^G-[A-Z0-9]{4,16}$/D';
    public const RE_PIXEL = '/^[0-9]{6,20}$/D';

    /** Grupos de "alegação" (§2.4) e o que dizer quando ainda são o texto de exemplo. */
    private const ALEGACOES = [
        'dep' => 'Os depoimentos ainda são os de exemplo. Troque pelos depoimentos reais dos seus clientes (com autorização deles) ou remova a seção de depoimentos.',
        'num' => 'Os números em destaque (anos, clientes atendidos…) ainda são os de exemplo. Edite com os seus números reais ou confirme que eles são verdadeiros.',
        'aval' => 'A nota de avaliação no Google ainda é a de exemplo. Edite com a sua nota real ou confirme que ela é verdadeira.',
        'cli' => 'Os nomes de clientes ainda são os de exemplo. Edite com clientes reais (que autorizaram a citação) ou confirme que eles são verdadeiros.',
    ];
    /**
     * Campos que carregam a alegação em cada grupo (rótulos genéricos como "Cliente" ou
     * "anos de experiência" podem continuar com o texto padrão).
     */
    private const CAMPOS_ALEGACAO = ['dep' => ['t', 'n'], 'num' => ['v'], 'cli' => ['t'], 'aval' => ['nota']];
    /** Depoimento de exemplo é sempre fictício: nunca pode ser confirmado. */
    private const NUNCA_CONFIRMAVEIS = ['dep'];

    private array $erros = [];
    private array $avisos = [];

    public function __construct(private readonly Aplicacao $app)
    {
    }

    /**
     * @return array{erros: list<array>, avisos: list<array>, textosPadraoAlterados: list<array>}
     */
    public function validar(Montagem $m): array
    {
        $this->erros = [];
        $this->avisos = [];
        $bruto = is_array($m->site['documento'] ?? null) ? $m->site['documento'] : [];

        $this->esquema($m, $bruto);
        $this->secoes($m, $bruto);
        $this->dados($m);
        $this->registro($m);
        $this->alegacoes($m);
        $this->equipePadrao($m);
        $this->rastreamento($bruto);
        $this->preparo($m);
        $this->fotos($m);
        $this->cor($m, $bruto);
        $this->conformidade($m);
        $alterados = $this->textosPadraoAlterados($m);
        if ($alterados !== []) {
            $n = count($alterados);
            $this->aviso('textos_padrao_alterados', $n === 1
                ? 'Um texto padrão da biblioteca mudou desde a última publicação. Confira o antes e o depois.'
                : "{$n} textos padrão da biblioteca mudaram desde a última publicação. Confira o antes e o depois.");
        }
        return ['erros' => $this->erros, 'avisos' => $this->avisos, 'textosPadraoAlterados' => $alterados];
    }

    private function erro(string $codigo, string $mensagem, array $extra = []): void
    {
        $this->erros[] = ['codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }

    private function aviso(string $codigo, string $mensagem, array $extra = []): void
    {
        $this->avisos[] = ['codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }

    private function esquema(Montagem $m, array $bruto): void
    {
        $versao = Texto::inteiroDe($bruto['versaoEsquema'] ?? null);
        if ($versao !== null && $versao > Documento::VERSAO_ESQUEMA) {
            $this->erro('esquema', 'O documento foi salvo por uma versão mais nova do editor. Recarregue o editor e tente de novo.');
        }
        if ($m->nicho === []) {
            $this->erro('nicho_desconhecido', 'O tipo de negócio deste site não existe mais na biblioteca. Escolha o tipo de negócio de novo no assistente.', ['chave' => 'nicho']);
        }
    }

    /** Cabeçalho primeiro, rodapé por último, sem duplicatas, seções e opções existentes. */
    private function secoes(Montagem $m, array $bruto): void
    {
        $secoes = $m->doc['secoes'];
        if ($secoes === []) {
            $this->erro('sem_secoes', 'O site não tem nenhuma seção. Escolha um modelo para começar.');
            return;
        }
        if ($secoes[0]['tipo'] !== 'header') {
            $this->erro('header_primeiro', 'O cabeçalho precisa ser a primeira seção do site.', ['secao' => 0]);
        }
        $ultima = count($secoes) - 1;
        if ($secoes[$ultima]['tipo'] !== 'rodape') {
            $this->erro('rodape_ultimo', 'O rodapé precisa ser a última seção do site.', ['secao' => $ultima]);
        }
        $vistos = [];
        foreach ($secoes as $i => $s) {
            $sec = $m->lib['secoes'][$s['tipo']] ?? null;
            if (!is_array($sec)) {
                $this->erro('secao_desconhecida', "A seção \"{$s['tipo']}\" não existe mais na biblioteca. Remova-a do site.", ['secao' => $i]);
                continue;
            }
            $nome = $m->nomeSecao($s['tipo']);
            if (!Documento::secaoExiste($m->lib, $s['tipo'], $s['opcao'])) {
                $this->erro('opcao_desconhecida', "O visual escolhido para a seção \"{$nome}\" não existe mais. Escolha outro visual para ela.", ['secao' => $i]);
            }
            if (isset($vistos[$s['tipo']])) {
                $this->erro('secao_duplicada', "A seção \"{$nome}\" aparece mais de uma vez. Remova a repetida.", ['secao' => $i]);
            }
            $vistos[$s['tipo']] = true;
        }
    }

    private function dados(Montagem $m): void
    {
        $dados = $m->doc['dados'];
        if (Texto::colapsarEspacos($dados['nome']) === '') {
            $this->erro('nome_vazio', 'Falta o nome do negócio. Ele aparece no topo, no rodapé e no título do site.', ['chave' => 'dados.nome']);
        }
        if (Texto::colapsarEspacos($dados['cidade']) === '') {
            $this->erro('cidade_vazia', 'Falta a cidade. Ela aparece nos textos e no título do site (sem ela, o site mostraria a cidade de exemplo).', ['chave' => 'dados.cidade']);
        }
        $whatsapp = Texto::colapsarEspacos($dados['whatsapp']);
        if ($whatsapp === '') {
            $this->erro('whatsapp_vazio', 'Falta o WhatsApp. Ele é usado em todos os botões de contato.', ['chave' => 'dados.whatsapp']);
        } elseif (!Dados::validarWhatsapp($whatsapp)) {
            $this->erro('whatsapp_invalido', 'O WhatsApp informado não parece válido. Use o DDD e o número, por exemplo (11) 98765-4321.', ['chave' => 'dados.whatsapp']);
        }
        $email = Texto::colapsarEspacos($dados['email']);
        if ($email !== '' && !Dados::validarEmail($email)) {
            $this->aviso('email_invalido', 'O e-mail informado não parece válido e não vai aparecer no site.', ['chave' => 'dados.email']);
        }
        $telefone = Texto::colapsarEspacos($dados['telefone']);
        if ($telefone !== '' && strlen(Dados::digitosNacionais($telefone)) < 8) {
            $this->aviso('telefone_invalido', 'O telefone informado está incompleto e não vai aparecer no site.', ['chave' => 'dados.telefone']);
        }
    }

    /** Profissão regulada sem número de registro → erro [M6]. */
    private function registro(Montagem $m): void
    {
        $esp = $m->especialidade ?? [];
        if (($esp['registroObrigatorio'] ?? false) !== true) {
            return;
        }
        $registro = $m->doc['dados']['registro'];
        $rotulo = Texto::colapsarEspacos($esp['rotuloRegistro'] ?? '') ?: (Texto::colapsarEspacos($esp['conselho'] ?? '') ?: 'conselho profissional');
        if (Texto::colapsarEspacos($registro['numero']) === '') {
            $this->erro('registro_obrigatorio', "Informe o número de registro profissional ({$rotulo}). Ele é obrigatório para esta profissão e aparece no rodapé do site.", ['chave' => 'dados.registro.numero']);
        } elseif (Texto::colapsarEspacos($registro['uf']) === '' && Texto::colapsarEspacos($m->doc['dados']['uf']) === '') {
            $this->aviso('registro_uf', "Informe a UF do registro profissional ({$rotulo}), por exemplo SP.", ['chave' => 'dados.registro.uf']);
        }
    }

    /**
     * Alegações (§2.4): grupo exibido por uma seção visível com algum texto ainda padrão
     * bloqueia, a menos que esteja em doc.confirmados (dep nunca é confirmável).
     */
    private function alegacoes(Montagem $m): void
    {
        $listas = Documento::registroListas($m->lib);
        $exibidas = $m->chavesExibidas();
        $pendentes = [];
        foreach ($m->secoes() as $s) {
            $manifest = $m->lib['secoes'][$s['tipo']]['manifest'] ?? [];
            $grupos = $manifest['alegacoes'] ?? [];
            foreach (($manifest['opcoes'] ?? []) as $o) {
                if (is_array($o) && ($o['id'] ?? null) === $s['opcao'] && array_key_exists('alegacoes', $o)) {
                    $grupos = $o['alegacoes'];
                }
            }
            foreach (is_array($grupos) ? $grupos : [] as $grupo) {
                if (!is_string($grupo) || !isset(self::ALEGACOES[$grupo]) || isset($pendentes[$grupo])) {
                    continue;
                }
                $confirmavel = !in_array($grupo, self::NUNCA_CONFIRMAVEIS, true);
                if ($confirmavel && in_array($grupo, $m->doc['confirmados'], true)) {
                    continue;
                }
                $chaves = $this->chavesDaAlegacao($m, $grupo, isset($listas[$grupo]), (int) $s['indice'], $exibidas);
                $padrao = array_values(array_filter($chaves, fn (string $c): bool => Textos::ehPadrao($m->doc, $c)
                    && Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, $c, $m->vars)) !== ''));
                if ($padrao !== []) {
                    $pendentes[$grupo] = [
                        'grupo' => $grupo, 'confirmavel' => $confirmavel, 'secao' => (int) $s['indice'],
                        'chave' => $padrao[0], 'chaves' => $padrao,
                    ];
                }
            }
        }
        foreach ($pendentes as $grupo => $extra) {
            $this->erro('alegacao_padrao', self::ALEGACOES[$grupo], $extra);
        }
    }

    /**
     * Nomes de profissionais ainda de exemplo (equipe.{id}.n exibidos com o texto padrão): não
     * bloqueiam (§2.4 só trata dep/num/aval/cli), mas publicar pessoas fictícias como equipe de
     * uma profissão regulada é arriscado — vira aviso.
     */
    private function equipePadrao(Montagem $m): void
    {
        $chaves = [];
        $secao = null;
        foreach ($m->chavesExibidas() as $chave => $secoes) {
            $chave = (string) $chave;
            if (preg_match('/^equipe\.[a-z0-9]+\.n$/D', $chave) && Textos::ehPadrao($m->doc, $chave)
                && Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, $chave, $m->vars)) !== '') {
                $chaves[] = $chave;
                $secao ??= $secoes[0];
            }
        }
        if ($chaves === []) {
            return;
        }
        $exemplo = Texto::colapsarEspacos(Textos::textoEfetivo($m->doc, $m->lib, $chaves[0], $m->vars));
        $this->aviso('equipe_padrao', 'Os nomes da equipe ainda são os de exemplo (como "' . $exemplo . '"). Troque pelos nomes reais dos profissionais antes de divulgar o site.',
            ['chave' => $chaves[0], 'chaves' => $chaves, 'secao' => $secao]);
    }

    /**
     * Chaves que carregam a alegação exibidas pela seção (itens da lista ou campos escalares,
     * só os de CAMPOS_ALEGACAO). Sem data-k no template → os itens efetivos da lista.
     *
     * @return list<string>
     */
    private function chavesDaAlegacao(Montagem $m, string $grupo, bool $ehLista, int $indice, array $exibidas): array
    {
        $campos = self::CAMPOS_ALEGACAO[$grupo] ?? [];
        $r = [];
        foreach ($exibidas as $chave => $secoes) {
            $partes = explode('.', (string) $chave);
            if ($partes[0] === $grupo && count($partes) === ($ehLista ? 3 : 2) && in_array(end($partes), $campos, true)
                && in_array($indice, $secoes, true)) {
                $r[] = (string) $chave;
            }
        }
        if ($r !== []) {
            return $r;
        }
        if ($ehLista) {
            [, $max] = Textos::limitesLista($m->lib, $grupo);
            foreach (array_slice(Textos::itensLista($m->doc, $m->lib, $grupo), 0, $max) as $id) {
                foreach ($campos as $campo) {
                    $r[] = $grupo . '.' . $id . '.' . $campo;
                }
            }
        } else {
            foreach ($campos as $campo) {
                $r[] = $grupo . '.' . $campo;
            }
        }
        return $r;
    }

    /** IDs de rastreamento vão para scripts e para a CSP: só no formato exato. */
    private function rastreamento(array $bruto): void
    {
        $r = is_array($bruto['rastreamento'] ?? null) ? $bruto['rastreamento'] : [];
        $regras = [
            'gtm' => [self::RE_GTM, 'O ID do Google Tag Manager deve ter o formato GTM-XXXXXXX.'],
            'ga4' => [self::RE_GA4, 'O ID do Google Analytics 4 deve ter o formato G-XXXXXXXXXX.'],
            'metaPixel' => [self::RE_PIXEL, 'O ID do Meta Pixel deve ter só números (ex.: 123456789012345).'],
        ];
        foreach ($regras as $campo => [$re, $mensagem]) {
            $v = $r[$campo] ?? '';
            $v = is_string($v) ? strtoupper(trim($v)) : '';
            if ($v !== '' && !preg_match($re, $v)) {
                $this->erro('rastreamento_invalido', $mensagem, ['chave' => 'rastreamento.' . $campo]);
            }
        }
    }

    /** Avisos do preparo que significam página quebrada viram erro. */
    private function preparo(Montagem $m): void
    {
        foreach ($m->preparado['avisos'] as $a) {
            if (($a['codigo'] ?? '') === 'erro_template') {
                $tipo = (string) ($m->doc['secoes'][$a['indice'] ?? -1]['tipo'] ?? '');
                $this->erro('erro_template', 'Não foi possível montar a seção "' . $m->nomeSecao($tipo) . '". Troque o visual dela ou avise o suporte.', ['secao' => (int) ($a['indice'] ?? 0)]);
            }
        }
    }

    private function fotos(Montagem $m): void
    {
        foreach ($m->fotosVazias() as $indice => $chaves) {
            $tipo = (string) ($m->doc['secoes'][$indice]['tipo'] ?? '');
            $n = count($chaves);
            $this->aviso('foto_faltando', 'A seção "' . $m->nomeSecao($tipo) . '" tem ' . ($n === 1 ? '1 espaço de foto vazio' : "{$n} espaços de foto vazios")
                . '. No site publicado eles aparecem como blocos de cor.', ['secao' => $indice, 'chave' => $chaves[0], 'chaves' => $chaves]);
        }
    }

    private function cor(Montagem $m, array $bruto): void
    {
        $original = $bruto['estilo']['cor'] ?? null;
        if ($original !== null && Paleta::normalizarCor($original) === null) {
            $this->aviso('cor_invalida', 'A cor escolhida é inválida; o site vai usar a cor padrão do modelo.', ['chave' => 'estilo.cor']);
        }
        if (Paleta::gerarPaleta($m->doc['estilo']['cor'])['claraDemais']) {
            $this->aviso('cor_clara', 'Cores muito claras podem perder leitura. Ajustamos os detalhes para manter o contraste, mas prefira um tom mais forte.', ['chave' => 'estilo.cor']);
        }
    }

    /** Termos que o conselho da profissão costuma vetar (nicho.conformidade.termosProibidos). */
    private function conformidade(Montagem $m): void
    {
        $conf = is_array($m->nicho['conformidade'] ?? null) ? $m->nicho['conformidade'] : [];
        $termos = [];
        foreach ((array) ($conf['termosProibidos'] ?? []) as $t) {
            $n = Texto::normalizar($t);
            if ($n !== '') {
                $termos[$n] = Texto::colapsarEspacos($t);
            }
        }
        if ($termos === []) {
            return;
        }
        $conselho = Texto::colapsarEspacos($conf['conselho'] ?? '');
        $textos = [];
        foreach ($m->chavesExibidas() as $chave => $secoes) {
            $textos[$chave] = [Textos::textoEfetivo($m->doc, $m->lib, (string) $chave, $m->vars), $secoes[0]];
        }
        foreach (['titulo', 'descricao'] as $campo) {
            $v = $m->doc['seo'][$campo] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $textos['seo.' . $campo] = [Textos::substituirVariaveis($v, $m->vars), null];
            }
        }
        foreach ($textos as $chave => [$texto, $secao]) {
            $normal = ' ' . preg_replace('/[^a-z0-9%]+/', ' ', Texto::normalizar($texto)) . ' ';
            foreach ($termos as $n => $original) {
                $alvo = ' ' . trim(preg_replace('/[^a-z0-9%]+/', ' ', $n) ?? $n) . ' ';
                if (trim($alvo) !== '' && str_contains($normal, $alvo)) {
                    $trecho = mb_strlen($texto, 'UTF-8') > 70 ? mb_substr($texto, 0, 67, 'UTF-8') . '…' : $texto;
                    $this->aviso('termo_conformidade', 'O texto "' . $trecho . '" usa "' . $original . '", expressão que '
                        . ($conselho !== '' ? 'o ' . $conselho : 'o conselho da profissão') . ' costuma vetar na publicidade. Revise antes de publicar.',
                        ['chave' => (string) $chave, 'termo' => $original] + ($secao !== null ? ['secao' => $secao] : []));
                    break;
                }
            }
        }
    }

    /**
     * [M5] Textos padrão exibidos cujo padrão mudou na biblioteca desde a publicação no ar:
     * compara com versoes.resolvidos; diferenças explicadas só por mudança de nome/cidade
     * (variáveis) não contam.
     *
     * @return list<array{chave: string, antes: string, depois: string}>
     */
    public function textosPadraoAlterados(Montagem $m): array
    {
        if ($m->siteId() <= 0) {
            return [];
        }
        $db = $this->app->db();
        $versao = null;
        if (($m->site['publicado_versao'] ?? null) !== null) {
            $versao = $db->um('SELECT documento, resolvidos FROM versoes WHERE site_id = ? AND numero = ?', [$m->siteId(), (int) $m->site['publicado_versao']]);
        }
        $versao ??= $db->um('SELECT documento, resolvidos FROM versoes WHERE site_id = ? ORDER BY numero DESC LIMIT 1', [$m->siteId()]);
        if ($versao === null) {
            return [];
        }
        $antigos = json_decode((string) ($versao['resolvidos'] ?? ''), true);
        if (!is_array($antigos) || $antigos === []) {
            return [];
        }
        $docAntigo = json_decode((string) $versao['documento'], true);
        $varsAntigas = Textos::contextoVariaveis(is_array($docAntigo) ? $docAntigo : [], $m->lib);
        $r = [];
        foreach ($m->textosPadraoExibidos() as $chave => $depois) {
            $antes = $antigos[$chave] ?? null;
            if (!is_string($antes) || $antes === $depois) {
                continue;
            }
            if (Textos::substituirVariaveis(Textos::textoPadrao($m->doc, $m->lib, $chave), $varsAntigas) === $antes) {
                continue; // só mudaram os dados (nome, cidade…), não o texto da biblioteca
            }
            $r[] = ['chave' => $chave, 'antes' => $antes, 'depois' => $depois];
        }
        return $r;
    }
}
