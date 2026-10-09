<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Http\ErroHttp;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Paleta;
use Rankly\Preparo\Textos;

/**
 * Validação do documento recebido no salvamento (PUT /api/sites/{id}) e na criação.
 * Confere estrutura (§2), tamanho, limites de texto do manifesto, cores, formatos e se
 * as mídias pertencem ao site. Regras de publicação (WhatsApp válido, alegações…) ficam
 * no Gerador\Validador: aqui o documento pode estar incompleto (salvamento automático).
 */
final class ValidadorDocumento
{
    private const RE_ESCALAR = '/^([a-z]+)\.([a-z][a-z0-9]*)$/D';
    private const RE_ITEM = '/^([a-z]+)\.([a-z0-9]+)\.([a-z]+)$/D';
    private const RE_ICONE_CHAVE = '/^([a-z]+)\.([a-z0-9]+)$/D';
    private const PROIBIDOS_ESCALAR = ['itens', 'qtd', 'tem', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9'];
    private const MAX_TEXTO_DESCONHECIDO = 1000;
    private const GRUPOS_ALEGACAO = ['num', 'aval', 'cli', 'dep'];

    /** Limites dos dados do negócio [M6]. */
    private const LIMITES_DADOS = [
        'nome' => 60, 'cidade' => 40, 'uf' => 2, 'whatsapp' => 30, 'telefone' => 30, 'email' => 120,
    ];
    private const LIMITES_ENDERECO = ['cep' => 12, 'logradouro' => 120, 'numero' => 20, 'complemento' => 60, 'bairro' => 60];
    private const LIMITES_REGISTRO = ['numero' => 30, 'uf' => 2, 'responsavel' => 80];

    /**
     * Valida e devolve o documento normalizado (Documento::migrar).
     *
     * @param int|null $siteId site dono das mídias (null = nenhuma mídia permitida, ex.: criação)
     * @throws ErroHttp 422 documento_invalido (com "chave" quando aplicável) ou 413 documento_grande
     */
    public static function validar(Aplicacao $app, mixed $doc, ?int $siteId): array
    {
        $lib = $app->biblioteca();
        if (!is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            throw self::erro('O documento do site precisa ser um objeto.');
        }
        if (($doc['versaoEsquema'] ?? null) !== Documento::VERSAO_ESQUEMA) {
            throw self::erro('Versão do documento não suportada. Recarregue o editor.', 'versaoEsquema');
        }
        $nicho = $doc['nicho'] ?? null;
        if (!is_string($nicho) || !isset($lib['nichos'][$nicho])) {
            throw self::erro('Tipo de negócio (nicho) desconhecido.', 'nicho');
        }
        $modelo = $doc['modelo'] ?? null;
        if (!is_string($modelo) || !isset($lib['modelos'][$modelo])) {
            throw self::erro('Modelo desconhecido.', 'modelo');
        }
        if (is_string($doc['nicho'] ?? null) && !Documento::modeloDoNicho($lib, $modelo, $doc['nicho'])) {
            throw self::erro('Este modelo não é do tipo de negócio do site.', 'modelo');
        }
        $esp = $doc['especialidade'] ?? null;
        if ($esp !== null && $esp !== '') {
            $ids = array_map(static fn ($e): mixed => is_array($e) ? ($e['id'] ?? null) : null, (array) ($lib['nichos'][$nicho]['especialidades'] ?? []));
            if (!is_string($esp) || ($ids !== [] && !in_array($esp, $ids, true))) {
                throw self::erro('Especialidade desconhecida para este tipo de negócio.', 'especialidade');
            }
        }

        $doc['estilo'] = self::estilo($lib, $doc['estilo'] ?? []);
        $midiasDoSite = $siteId !== null ? self::midiasDoSite($app, $siteId) : [];
        self::dados($doc['dados'] ?? [], $midiasDoSite);
        self::secoes($lib, $doc['secoes'] ?? []);
        $registro = Documento::registroCampos($lib);
        $listas = Documento::registroListas($lib);
        self::textos($lib, $doc, $registro);
        self::listas($doc['listas'] ?? [], $listas);
        self::imagens($doc['imagens'] ?? [], $registro, $midiasDoSite);
        self::icones($doc['icones'] ?? []);
        self::iconesExtras($doc['iconesExtras'] ?? [], $doc['icones'] ?? []);
        self::outros($doc);

        $normalizado = Documento::migrar($doc, $lib);
        $normalizado['confirmados'] = array_values(array_filter(
            $normalizado['confirmados'],
            static fn (string $g): bool => in_array($g, self::GRUPOS_ALEGACAO, true),
        ));
        $limite = max(16, (int) $app->config('tamanho_max_documento_kb', 512)) * 1024;
        if (strlen(Documento::json($normalizado)) > $limite) {
            throw new ErroHttp(413, 'documento_grande', 'O documento do site ficou grande demais (máximo de ' . ($limite / 1024) . ' KB).');
        }
        return $normalizado;
    }

    public static function erro(string $mensagem, ?string $chave = null): ErroHttp
    {
        return ErroHttp::invalido($mensagem, $chave !== null ? ['chave' => $chave] : [], 'documento_invalido');
    }

    /** @return array<string, true> ids de mídia do site */
    private static function midiasDoSite(Aplicacao $app, int $siteId): array
    {
        $r = [];
        foreach ($app->db()->todos('SELECT id FROM midia WHERE site_id = ?', [$siteId]) as $l) {
            $r[(string) $l['id']] = true;
        }
        return $r;
    }

    private static function mapa(mixed $v, string $chave): array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw self::erro("Campo \"{$chave}\" do documento em formato inválido.", $chave);
        }
        return $v;
    }

    private static function lista(mixed $v, string $chave): array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || !array_is_list($v)) {
            throw self::erro("Campo \"{$chave}\" do documento em formato inválido.", $chave);
        }
        return $v;
    }

    /** Texto simples (sem caracteres de controle; quebras de linha só se $multilinha) e até $max caracteres. */
    private static function texto(mixed $v, int $max, string $chave, string $rotulo, bool $multilinha = false): string
    {
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw self::erro("\"{$rotulo}\" precisa ser um texto.", $chave);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw self::erro("\"{$rotulo}\" tem caracteres inválidos.", $chave);
        }
        $controle = $multilinha ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($controle, $v)) {
            throw self::erro("\"{$rotulo}\" tem caracteres de controle não permitidos.", $chave);
        }
        if (mb_strlen($v, 'UTF-8') > $max) {
            throw self::erro("\"{$rotulo}\" passa do limite de {$max} caracteres.", $chave);
        }
        return $v;
    }

    private static function estilo(array $lib, mixed $estilo): array
    {
        $e = self::mapa($estilo, 'estilo');
        if (array_key_exists('cor', $e)) {
            if (!is_string($e['cor']) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $e['cor'])) {
                throw self::erro('Cor principal inválida (use o formato #rrggbb).', 'estilo.cor');
            }
            $e['cor'] = Paleta::normalizarCor($e['cor']) ?? strtolower($e['cor']);
        }
        if (array_key_exists('fonte', $e) && !Documento::fonteValida($lib, $e['fonte'])) {
            throw self::erro('Par de fontes desconhecido.', 'estilo.fonte');
        }
        if (array_key_exists('acabamento', $e) && !in_array($e['acabamento'], Documento::ACABAMENTOS, true)) {
            throw self::erro('Acabamento desconhecido.', 'estilo.acabamento');
        }
        if (array_key_exists('whatsappFlutuante', $e) && !is_bool($e['whatsappFlutuante'])) {
            throw self::erro('Opção do botão flutuante inválida.', 'estilo.whatsappFlutuante');
        }
        return $e;
    }

    private static function dados(mixed $dados, array $midias): void
    {
        $d = self::mapa($dados, 'dados');
        foreach (self::LIMITES_DADOS as $campo => $max) {
            self::texto($d[$campo] ?? null, $max, 'dados.' . $campo, self::rotuloDado($campo));
        }
        $logo = $d['logo'] ?? null;
        if ($logo !== null && $logo !== '') {
            if (!is_string($logo) || !isset($midias[$logo])) {
                throw self::erro('O logo não pertence a este site. Envie o logo de novo.', 'dados.logo');
            }
        }
        $end = self::mapa($d['endereco'] ?? null, 'dados.endereco');
        foreach (self::LIMITES_ENDERECO as $campo => $max) {
            self::texto($end[$campo] ?? null, $max, 'dados.endereco.' . $campo, 'Endereço');
        }
        $reg = self::mapa($d['registro'] ?? null, 'dados.registro');
        foreach (self::LIMITES_REGISTRO as $campo => $max) {
            self::texto($reg[$campo] ?? null, $max, 'dados.registro.' . $campo, 'Registro profissional');
        }
        $redes = self::mapa($d['redes'] ?? null, 'dados.redes');
        foreach ($redes as $rede => $valor) {
            if (!in_array($rede, Documento::REDES, true)) {
                throw self::erro('Rede social desconhecida.', 'dados.redes');
            }
            self::texto($valor, 200, 'dados.redes.' . $rede, 'Rede social');
        }
        $horarios = self::mapa($d['horarios'] ?? null, 'dados.horarios');
        foreach ($horarios as $dia => $faixa) {
            if (!in_array($dia, Documento::DIAS, true)) {
                throw self::erro('Dia da semana inválido nos horários.', 'dados.horarios');
            }
            if ($faixa === null) {
                continue;
            }
            if (!is_array($faixa) || !array_is_list($faixa) || count($faixa) > 4) {
                throw self::erro('Horário de funcionamento em formato inválido.', 'dados.horarios.' . $dia);
            }
            foreach ($faixa as $hora) {
                if (!is_string($hora) || ($hora !== '' && !preg_match('/^([01]?\d|2[0-4]):[0-5]\d$/D', $hora))) {
                    throw self::erro('Horário de funcionamento inválido (use HH:MM).', 'dados.horarios.' . $dia);
                }
            }
        }
    }

    private static function rotuloDado(string $campo): string
    {
        return match ($campo) {
            'nome' => 'Nome da empresa',
            'cidade' => 'Cidade',
            'uf' => 'UF',
            'whatsapp' => 'WhatsApp',
            'telefone' => 'Telefone',
            'email' => 'E-mail',
            default => $campo,
        };
    }

    private static function secoes(array $lib, mixed $secoes): void
    {
        $lista = self::lista($secoes, 'secoes');
        if (count($lista) > 40) {
            throw self::erro('Seções demais no site.', 'secoes');
        }
        foreach ($lista as $i => $s) {
            if (!is_array($s) || !is_string($s['tipo'] ?? null) || !is_string($s['opcao'] ?? null)
                || !Documento::secaoExiste($lib, $s['tipo'], $s['opcao'])) {
                throw self::erro('Seção ou opção desconhecida na posição ' . ($i + 1) . '.', 'secoes');
            }
        }
    }

    private static function textos(array $lib, array $doc, array $registro): void
    {
        $textos = self::mapa($doc['textos'] ?? null, 'textos');
        if (count($textos) > 800) {
            throw self::erro('Textos demais no documento.', 'textos');
        }
        $contexto = Textos::contextoVariaveis($doc, $lib);
        foreach ($textos as $chave => $valor) {
            $chave = (string) $chave;
            if (preg_match(self::RE_ESCALAR, $chave, $m)) {
                if (in_array($m[2], self::PROIBIDOS_ESCALAR, true)) {
                    throw self::erro("Chave de texto inválida: {$chave}.", $chave);
                }
            } elseif (!preg_match(self::RE_ITEM, $chave)) {
                throw self::erro("Chave de texto inválida: {$chave}.", $chave);
            }
            $def = Documento::definicaoCampo($registro, $chave);
            $tipo = is_array($def) ? ($def['tipo'] ?? 'texto') : 'texto';
            if (!in_array($tipo, ['texto', 'texto-longo'], true)) {
                throw self::erro("A chave {$chave} não é um texto.", $chave);
            }
            $max = is_array($def) && is_int($def['max'] ?? null) ? $def['max'] : self::MAX_TEXTO_DESCONHECIDO;
            if (!is_string($valor)) {
                throw self::erro("O texto de {$chave} precisa ser um texto.", $chave);
            }
            // O limite vale para o que o visitante vê (com {nome}/{cidade} trocados).
            $exibido = Textos::substituirVariaveis($valor, $contexto);
            $rotulo = is_array($def) && is_string($def['rotulo'] ?? null) ? $def['rotulo'] : $chave;
            self::texto($exibido, $max, $chave, $rotulo, $tipo === 'texto-longo');
        }
    }

    private static function listas(mixed $listas, array $definicoes): void
    {
        foreach (self::mapa($listas, 'listas') as $nome => $ids) {
            $nome = (string) $nome;
            if (!preg_match('/^[a-z]+$/D', $nome)) {
                throw self::erro("Lista inválida: {$nome}.", 'listas');
            }
            $ids = self::lista($ids, 'listas.' . $nome);
            $repete = $definicoes[$nome]['repete'] ?? null;
            $max = is_array($repete) && is_int($repete[1] ?? null) ? $repete[1] : 20;
            if (count($ids) > $max) {
                throw self::erro("A lista {$nome} tem itens demais (máximo {$max}).", 'listas.' . $nome);
            }
            $vistos = [];
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[a-z0-9]{1,12}$/D', $id) || isset($vistos[$id])) {
                    throw self::erro("Item inválido ou repetido na lista {$nome}.", 'listas.' . $nome);
                }
                $vistos[$id] = true;
            }
        }
    }

    private static function imagens(mixed $imagens, array $registro, array $midias): void
    {
        $mapa = self::mapa($imagens, 'imagens');
        if (count($mapa) > 300) {
            throw self::erro('Imagens demais no documento.', 'imagens');
        }
        foreach ($mapa as $chave => $id) {
            $chave = (string) $chave;
            if (!preg_match(self::RE_ESCALAR, $chave) && !preg_match(self::RE_ITEM, $chave)) {
                throw self::erro("Chave de imagem inválida: {$chave}.", $chave);
            }
            $def = Documento::definicaoCampo($registro, $chave);
            if (is_array($def) && ($def['tipo'] ?? null) !== 'imagem') {
                throw self::erro("A chave {$chave} não é uma imagem.", $chave);
            }
            if (!is_string($id) || !isset($midias[$id])) {
                throw self::erro('Uma das fotos não pertence a este site. Envie a foto de novo.', $chave);
            }
        }
    }

    private static function icones(mixed $icones): void
    {
        $mapa = self::mapa($icones, 'icones');
        if (count($mapa) > 300) {
            throw self::erro('Ícones demais no documento.', 'icones');
        }
        foreach ($mapa as $chave => $id) {
            $chave = (string) $chave;
            if (!preg_match(self::RE_ICONE_CHAVE, $chave) || !is_string($id)
                || (!preg_match('/^[a-z0-9-]{1,48}$/D', $id) && !Documento::idIconeExtraValido($id))) {
                throw self::erro("Ícone inválido em {$chave}.", $chave);
            }
        }
    }

    /**
     * Ícones do Iconify guardados no documento: no máximo 60, id "prefixo:nome", nome curto e cada
     * desenho exatamente no formato que o servidor monta (Iconify::svgValido). Os que nenhum item
     * usa são descartados depois, na normalização.
     */
    private static function iconesExtras(mixed $extras, mixed $icones): void
    {
        $mapa = self::mapa($extras, 'iconesExtras');
        if (count($mapa) > 60) {
            throw self::erro('Ícones do Iconify demais no documento (máximo de 60).', 'iconesExtras');
        }
        foreach ($mapa as $id => $def) {
            $id = (string) $id;
            if (!Documento::idIconeExtraValido($id) || !is_array($def) || ($def !== [] && array_is_list($def))) {
                throw self::erro('Ícone do Iconify inválido.', 'iconesExtras');
            }
            $nome = $def['nome'] ?? null;
            if ($nome !== null && (!is_string($nome) || mb_strlen($nome, 'UTF-8') > 100)) {
                throw self::erro('Nome de ícone do Iconify inválido.', 'iconesExtras');
            }
            $svg = $def['svg'] ?? null;
            if (!is_array($svg) || $svg === [] || array_is_list($svg)) {
                throw self::erro('Ícone do Iconify sem desenho.', 'iconesExtras');
            }
            foreach ($svg as $peso => $desenho) {
                if (!in_array($peso, Documento::PESOS_ICONE, true) || !Iconify::svgValido($desenho)) {
                    throw self::erro('O desenho de um ícone do Iconify não é aceito. Escolha o ícone de novo.', 'iconesExtras');
                }
            }
        }
    }

    private static function outros(array $doc): void
    {
        foreach (self::lista($doc['confirmados'] ?? null, 'confirmados') as $g) {
            if (!is_string($g)) {
                throw self::erro('Lista de confirmações inválida.', 'confirmados');
            }
        }
        $r = self::mapa($doc['rastreamento'] ?? null, 'rastreamento');
        foreach (['gtm', 'ga4', 'metaPixel'] as $campo) {
            $v = $r[$campo] ?? '';
            // Formato exato é conferido na publicação; aqui só um alfabeto seguro (vai para scripts).
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9-]{0,30}$/D', $v)) {
                throw self::erro('Identificador de rastreamento inválido (só letras, números e hífen).', 'rastreamento.' . $campo);
            }
        }
        $seo = self::mapa($doc['seo'] ?? null, 'seo');
        if (($seo['titulo'] ?? null) !== null) {
            self::texto($seo['titulo'], 120, 'seo.titulo', 'Título da página');
        }
        if (($seo['descricao'] ?? null) !== null) {
            self::texto($seo['descricao'], 320, 'seo.descricao', 'Descrição da página');
        }
    }
}
