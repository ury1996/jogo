<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Documento do site: criação, troca de modelo, migração e registro de campos.
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/documento.mjs (contrato §2, §3).
 */
final class Documento
{
    public const VERSAO_ESQUEMA = 2;
    public const ACABAMENTOS = ['classico', 'moderno', 'direto'];
    public const FONTES = ['classica', 'editorial', 'moderna', 'amigavel'];
    public const DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    public const REDES = ['instagram', 'facebook', 'linkedin', 'youtube', 'google'];
    public const RE_ID_ITEM = '/^[a-z0-9]+$/D';
    private const RE_NOME_LISTA = '/^[a-z]+$/D';

    /** Campos do documento que são mapas (viram {} no JSON mesmo vazios). */
    private const MAPAS = ['textos', 'listas', 'imagens', 'icones'];

    /** Ordem das opções do catálogo (§3.2), para migrar v1 quando a biblioteca não traz o tipo. */
    private const CATALOGO = [
        'header' => ['simples', 'barra'],
        'hero' => ['cards-flutuantes', 'fundo-cards', 'formulario', 'centralizado'],
        'diferenciais' => ['faixa-icones', 'foto-selo'],
        'clientes' => ['faixa'],
        'sobre' => ['duas-fotos', 'foto-numeros'],
        'servicos' => ['cards', 'lista', 'cards-foto', 'blocos'],
        'numeros' => ['faixa-clara', 'faixa-cor'],
        'passos' => ['linha-tempo', 'lista-foto'],
        'equipe' => ['fotos-nome', 'compacta'],
        'depoimentos' => ['cards-nota', 'destaque-foto'],
        'faq' => ['centralizada', 'foto-ajuda'],
        'cta' => ['faixa-cor', 'caixa-clara', 'foto-fundo'],
        'contato' => ['formulario', 'mapa'],
        'rodape' => ['completo', 'simples'],
    ];

    /** Pacote do nicho do documento ([] se desconhecido). */
    public static function nichoDe(mixed $doc, mixed $lib): array
    {
        $nichos = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'nichos'));
        $id = Texto::textoDe(Texto::pegar(Texto::comoMapa($doc), 'nicho'));
        return Texto::comoMapa(Texto::pegar($nichos, $id));
    }

    /** Especialidade do documento; ausente/inválida → a primeira do nicho; sem nenhuma → null. */
    public static function especialidade(mixed $doc, mixed $lib): ?array
    {
        $lista = array_values(array_filter(
            Texto::comoLista(Texto::pegar(self::nichoDe($doc, $lib), 'especialidades')),
            [Texto::class, 'ehMapa'],
        ));
        $id = Texto::textoDe(Texto::pegar(Texto::comoMapa($doc), 'especialidade'));
        foreach ($lista as $e) {
            if (Texto::pegar($e, 'id') === $id) {
                return $e;
            }
        }
        return $lista[0] ?? null;
    }

    private static function idEspecialidade(array $nicho, mixed $id): string
    {
        $lista = array_values(array_filter(Texto::comoLista(Texto::pegar($nicho, 'especialidades')), [Texto::class, 'ehMapa']));
        $pedido = Texto::textoDe($id);
        foreach ($lista as $e) {
            if (Texto::pegar($e, 'id') === $pedido) {
                return $pedido;
            }
        }
        if ($lista !== []) {
            return Texto::textoDe(Texto::pegar($lista[0], 'id'));
        }
        return $pedido;
    }

    private static function paresDeFontes(mixed $lib): array
    {
        return Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'fontes')), 'pares'));
    }

    public static function fonteValida(mixed $lib, mixed $fonte): bool
    {
        if (!is_string($fonte) || $fonte === '') {
            return false;
        }
        $pares = self::paresDeFontes($lib);
        return $pares !== [] ? Texto::temChave($pares, $fonte) : in_array($fonte, self::FONTES, true);
    }

    public static function fontePadrao(mixed $lib): string
    {
        $chaves = array_keys(self::paresDeFontes($lib));
        return $chaves !== [] ? (string) $chaves[0] : 'moderna';
    }

    private static function estiloPadrao(mixed $lib, array $nicho, string $modeloId): array
    {
        $modelo = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'modelos')), $modeloId));
        // Cor e fonte: do nicho para este modelo; senão o padrão do próprio modelo (modelos exclusivos de um nicho).
        $doNicho = Texto::pegar(Texto::comoMapa(Texto::pegar($nicho, 'padroesPorModelo')), $modeloId);
        $padroes = Texto::comoMapa(Texto::ehMapa($doNicho) ? $doNicho : Texto::pegar($modelo, 'padrao'));
        $fonte = Texto::pegar($padroes, 'fonte');
        $acabamento = Texto::pegar($modelo, 'acabamento');
        return [
            'cor' => Paleta::normalizarCor(Texto::pegar($padroes, 'cor')) ?? Paleta::COR_PADRAO,
            'fonte' => self::fonteValida($lib, $fonte) ? $fonte : self::fontePadrao($lib),
            'acabamento' => in_array($acabamento, self::ACABAMENTOS, true) ? $acabamento : 'moderno',
            'whatsappFlutuante' => true,
        ];
    }

    /** Estilo completo e válido; o que faltar/for inválido vem do padrão do nicho e do modelo. */
    public static function completarEstilo(mixed $estilo, mixed $lib, array $nicho, string $modeloId): array
    {
        $base = self::estiloPadrao($lib, $nicho, $modeloId);
        $e = Texto::comoMapa($estilo);
        $fonte = Texto::pegar($e, 'fonte');
        $acabamento = Texto::pegar($e, 'acabamento');
        $flutuante = Texto::pegar($e, 'whatsappFlutuante');
        return [
            'cor' => Paleta::normalizarCor(Texto::pegar($e, 'cor')) ?? $base['cor'],
            'fonte' => self::fonteValida($lib, $fonte) ? $fonte : $base['fonte'],
            'acabamento' => in_array($acabamento, self::ACABAMENTOS, true) ? $acabamento : $base['acabamento'],
            'whatsappFlutuante' => is_bool($flutuante) ? $flutuante : $base['whatsappFlutuante'],
        ];
    }

    private static function completarHorarios(mixed $horarios): array
    {
        $h = Texto::comoMapa($horarios);
        $r = [];
        foreach (self::DIAS as $dia) {
            if (!Texto::temChave($h, $dia)) {
                continue;
            }
            $v = $h[$dia];
            if ($v === null || $v === false) {
                $r[$dia] = null;
            } elseif (is_array($v) && array_is_list($v)) {
                $r[$dia] = array_map([Texto::class, 'textoDe'], $v);
            }
        }
        return $r;
    }

    /** Dados com TODOS os campos do §2 (strings vazias e estruturas), na ordem canônica. */
    public static function completarDados(mixed $dados): array
    {
        $d = Texto::comoMapa($dados);
        $e = Texto::comoMapa(Texto::pegar($d, 'endereco'));
        $r = Texto::comoMapa(Texto::pegar($d, 'registro'));
        $redes = Texto::comoMapa(Texto::pegar($d, 'redes'));
        $t = static fn (array $m, string $k): string => Texto::textoDe(Texto::pegar($m, $k));
        $redesCompletas = [];
        foreach (self::REDES as $rede) {
            $redesCompletas[$rede] = $t($redes, $rede);
        }
        $logo = Texto::pegar($d, 'logo');
        return [
            'nome' => $t($d, 'nome'),
            'cidade' => $t($d, 'cidade'),
            'uf' => $t($d, 'uf'),
            'whatsapp' => $t($d, 'whatsapp'),
            'telefone' => $t($d, 'telefone'),
            'email' => $t($d, 'email'),
            'logo' => is_string($logo) && $logo !== '' ? $logo : null,
            'endereco' => [
                'cep' => $t($e, 'cep'),
                'logradouro' => $t($e, 'logradouro'),
                'numero' => $t($e, 'numero'),
                'complemento' => $t($e, 'complemento'),
                'bairro' => $t($e, 'bairro'),
            ],
            'horarios' => self::completarHorarios(Texto::pegar($d, 'horarios')),
            'registro' => ['numero' => $t($r, 'numero'), 'uf' => $t($r, 'uf'), 'responsavel' => $t($r, 'responsavel')],
            'redes' => $redesCompletas,
        ];
    }

    /**
     * O modelo pode ser usado neste nicho? Modelo sem "nichos" vale para todos; com "nichos",
     * só para os listados (modelos exclusivos de um nicho).
     */
    public static function modeloDoNicho(mixed $lib, string $modeloId, string $nichoId): bool
    {
        $modelo = Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'modelos')), $modeloId);
        if (!Texto::ehMapa($modelo)) {
            return false;
        }
        $nichos = Texto::pegar($modelo, 'nichos');
        return !is_array($nichos) || !array_is_list($nichos) || in_array($nichoId, $nichos, true);
    }

    /** A seção e a opção existem na biblioteca? */
    public static function secaoExiste(mixed $lib, string $tipo, string $opcao): bool
    {
        $sec = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'secoes')), $tipo));
        foreach (Texto::comoLista(Texto::pegar(Texto::comoMapa(Texto::pegar($sec, 'manifest')), 'opcoes')) as $o) {
            if (Texto::ehMapa($o) && Texto::pegar($o, 'id') === $opcao) {
                return true;
            }
        }
        return false;
    }

    /**
     * Receita do modelo filtrada por so/exceto do nicho.
     *
     * @return list<array{tipo: string, opcao: string}>
     */
    public static function receitaModelo(mixed $lib, mixed $modelo, mixed $nicho): array
    {
        $def = is_string($modelo)
            ? Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'modelos')), $modelo))
            : Texto::comoMapa($modelo);
        $nichoId = is_string($nicho) ? $nicho : Texto::textoDe(Texto::pegar(Texto::comoMapa($nicho), 'id'));
        $receita = [];
        foreach (Texto::comoLista(Texto::pegar($def, 'secoes')) as $entrada) {
            if (is_array($entrada) && array_is_list($entrada) && $entrada !== []) {
                $tipo = Texto::textoDe($entrada[0] ?? null);
                $opcao = Texto::textoDe($entrada[1] ?? null);
                $filtro = Texto::comoMapa($entrada[2] ?? null);
            } elseif (Texto::ehMapa($entrada)) {
                $tipo = Texto::textoDe(Texto::pegar($entrada, 'tipo'));
                $opcao = Texto::textoDe(Texto::pegar($entrada, 'opcao'));
                $filtro = $entrada;
            } else {
                continue;
            }
            if ($tipo === '' || $opcao === '') {
                continue;
            }
            $so = Texto::pegar($filtro, 'so');
            if (is_array($so) && array_is_list($so) && !in_array($nichoId, $so, true)) {
                continue;
            }
            $exceto = Texto::pegar($filtro, 'exceto');
            if (is_array($exceto) && array_is_list($exceto) && in_array($nichoId, $exceto, true)) {
                continue;
            }
            $receita[] = ['tipo' => $tipo, 'opcao' => $opcao];
        }
        return $receita;
    }

    private static function secoesDoModelo(mixed $lib, string $modeloId, string $nichoId): array
    {
        return array_values(array_filter(
            self::receitaModelo($lib, $modeloId, $nichoId),
            static fn (array $s): bool => self::secaoExiste($lib, $s['tipo'], $s['opcao']),
        ));
    }

    private static function exigirModelo(mixed $lib, string $modeloId): array
    {
        $modelo = Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'modelos')), $modeloId);
        if (!Texto::ehMapa($modelo)) {
            throw new \InvalidArgumentException("Modelo desconhecido: \"{$modeloId}\".");
        }
        return $modelo;
    }

    /**
     * Documento inicial: receita do modelo filtrada pelo nicho, estilo padrão
     * (nicho.padroesPorModelo + acabamento do modelo + WhatsApp flutuante), estilo
     * recebido por cima, especialidade (primeira do nicho se não vier) e dados completos.
     *
     * @throws \InvalidArgumentException nicho ou modelo desconhecido
     */
    public static function criarDocumento(array $entrada, mixed $lib): array
    {
        $nichoId = Texto::textoDe(Texto::pegar($entrada, 'nicho'));
        $modeloId = Texto::textoDe(Texto::pegar($entrada, 'modelo'));
        $nicho = Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'nichos')), $nichoId);
        if (!Texto::ehMapa($nicho)) {
            throw new \InvalidArgumentException("Nicho desconhecido: \"{$nichoId}\".");
        }
        self::exigirModelo($lib, $modeloId);
        return [
            'versaoEsquema' => self::VERSAO_ESQUEMA,
            'nicho' => $nichoId,
            'especialidade' => self::idEspecialidade($nicho, Texto::pegar($entrada, 'especialidade')),
            'modelo' => $modeloId,
            'estilo' => self::completarEstilo(Texto::pegar($entrada, 'estilo'), $lib, $nicho, $modeloId),
            'dados' => self::completarDados(Texto::pegar($entrada, 'dados')),
            'secoes' => self::secoesDoModelo($lib, $modeloId, $nichoId),
            'textos' => [],
            'listas' => [],
            'imagens' => [],
            'icones' => [],
            'confirmados' => [],
            'rastreamento' => ['gtm' => '', 'ga4' => '', 'metaPixel' => ''],
            'seo' => ['titulo' => null, 'descricao' => null],
        ];
    }

    /**
     * Troca o modelo: mudam seções, acabamento e fonte padrão do modelo; ficam textos,
     * imagens, ícones, dados, cor, listas, confirmados, rastreamento e SEO.
     *
     * @throws \InvalidArgumentException modelo desconhecido
     */
    public static function aplicarModelo(array $doc, mixed $lib, string $modelo): array
    {
        self::exigirModelo($lib, $modelo);
        $nicho = self::nichoDe($doc, $lib);
        $padrao = self::estiloPadrao($lib, $nicho, $modelo);
        $estilo = self::completarEstilo(Texto::pegar($doc, 'estilo'), $lib, $nicho, $modelo);
        $estilo['fonte'] = $padrao['fonte'];
        $estilo['acabamento'] = $padrao['acabamento'];
        $doc['modelo'] = $modelo;
        $doc['estilo'] = $estilo;
        $doc['secoes'] = self::secoesDoModelo($lib, $modelo, Texto::textoDe(Texto::pegar($doc, 'nicho')));
        return $doc;
    }

    /** Chave v1 (listas base 0) → chave v2 (ids "1", "2", …). */
    public static function converterChaveV1(string $chave): string
    {
        if ($chave === 'clientes.titulo') {
            return 'cli.titulo';
        }
        if (preg_match('/^clientes\.(\d{1,4})$/D', $chave, $m)) {
            return 'cli.' . ((int) $m[1] + 1) . '.t';
        }
        if (preg_match('/^sobre\.l\.(\d{1,4})$/D', $chave, $m)) {
            return 'sobrel.' . ((int) $m[1] + 1) . '.t';
        }
        if (preg_match('/^([a-z]+)\.(\d{1,4})\.([a-z][a-z0-9]*)$/D', $chave, $m)) {
            return $m[1] . '.' . ((int) $m[2] + 1) . '.' . $m[3];
        }
        if (preg_match('/^([a-z]+)\.(\d{1,4})$/D', $chave, $m)) {
            return $m[1] . '.' . ((int) $m[2] + 1);
        }
        return $chave;
    }

    private static function mapaDeTextos(mixed $mapa, bool $v1): array
    {
        $r = [];
        foreach (Texto::comoMapa($mapa) as $chave => $valor) {
            if (!is_string($valor)) {
                continue;
            }
            $nova = $v1 ? self::converterChaveV1((string) $chave) : (string) $chave;
            if (!array_key_exists($nova, $r)) {
                $r[$nova] = $valor;
            }
        }
        return $r;
    }

    /**
     * Ids válidos de uma lista (texto /^[a-z0-9]+$/ ou inteiro ≥ 0), sem repetição.
     *
     * @return list<string>
     */
    public static function idsValidos(mixed $lista): array
    {
        $ids = [];
        foreach (Texto::comoLista($lista) as $v) {
            $inteiro = Texto::inteiroDe($v);
            $id = $inteiro !== null && $inteiro >= 0 ? (string) $inteiro : $v;
            if (is_string($id) && preg_match(self::RE_ID_ITEM, $id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private static function migrarListas(mixed $listas): array
    {
        $r = [];
        foreach (Texto::comoMapa($listas) as $nome => $ids) {
            if (preg_match(self::RE_NOME_LISTA, (string) $nome) && is_array($ids) && array_is_list($ids)) {
                $r[(string) $nome] = self::idsValidos($ids);
            }
        }
        return $r;
    }

    private static function opcoesDoTipo(mixed $lib, string $tipo): array
    {
        $sec = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'secoes')), $tipo));
        $ids = [];
        foreach (Texto::comoLista(Texto::pegar(Texto::comoMapa(Texto::pegar($sec, 'manifest')), 'opcoes')) as $o) {
            if (Texto::ehMapa($o)) {
                $id = Texto::textoDe(Texto::pegar($o, 'id'));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return $ids !== [] ? $ids : (self::CATALOGO[$tipo] ?? []);
    }

    private static function migrarSecoes(mixed $secoes, mixed $lib): array
    {
        $r = [];
        foreach (Texto::comoLista($secoes) as $s) {
            $tipo = Texto::pegar($s, 'tipo');
            if (!is_string($tipo) || $tipo === '') {
                continue;
            }
            $opcao = Texto::pegar($s, 'opcao');
            $indice = Texto::inteiroDe($opcao);
            if ($indice !== null && !is_string($opcao)) {
                $ids = self::opcoesDoTipo($lib, $tipo);
                if ($ids === []) {
                    continue;
                }
                $opcao = $indice >= 0 && $indice < count($ids) ? $ids[$indice] : $ids[0];
            }
            if (!is_string($opcao) || $opcao === '') {
                continue;
            }
            $r[] = ['tipo' => $tipo, 'opcao' => $opcao];
        }
        return $r;
    }

    /**
     * Migra/normaliza qualquer documento para o esquema 2.
     * v1 → v2: opção numérica → id pela ordem do manifesto; chaves de lista base 0
     * ("serv.0.t") → ids "1"…; ícones e imagens idem; dados completados.
     * Documentos v2 só são normalizados (campos ausentes completados, tipos corrigidos).
     * `$lib` é opcional (sem ela, a ordem das opções vem do catálogo §3.2).
     */
    public static function migrar(mixed $doc, mixed $lib = null): array
    {
        $d = Texto::comoMapa($doc);
        $v1 = Texto::inteiroDe(Texto::pegar($d, 'versaoEsquema')) !== self::VERSAO_ESQUEMA;
        $nicho = self::nichoDe($d, $lib);
        $modeloId = Texto::textoDe(Texto::pegar($d, 'modelo'));
        $r = Texto::comoMapa(Texto::pegar($d, 'rastreamento'));
        $seo = Texto::comoMapa(Texto::pegar($d, 'seo'));
        $confirmados = [];
        foreach (Texto::comoLista(Texto::pegar($d, 'confirmados')) as $g) {
            if (is_string($g) && !in_array($g, $confirmados, true)) {
                $confirmados[] = $g;
            }
        }
        $titulo = Texto::pegar($seo, 'titulo');
        $descricao = Texto::pegar($seo, 'descricao');
        return [
            'versaoEsquema' => self::VERSAO_ESQUEMA,
            'nicho' => Texto::textoDe(Texto::pegar($d, 'nicho')),
            'especialidade' => self::idEspecialidade($nicho, Texto::pegar($d, 'especialidade')),
            'modelo' => $modeloId,
            'estilo' => self::completarEstilo(Texto::pegar($d, 'estilo'), $lib, $nicho, $modeloId),
            'dados' => self::completarDados(Texto::pegar($d, 'dados')),
            'secoes' => self::migrarSecoes(Texto::pegar($d, 'secoes'), $lib),
            'textos' => self::mapaDeTextos(Texto::pegar($d, 'textos'), $v1),
            'listas' => self::migrarListas(Texto::pegar($d, 'listas')),
            'imagens' => self::mapaDeTextos(Texto::pegar($d, 'imagens'), $v1),
            'icones' => self::mapaDeTextos(Texto::pegar($d, 'icones'), $v1),
            'confirmados' => $confirmados,
            'rastreamento' => [
                'gtm' => Texto::textoDe(Texto::pegar($r, 'gtm')),
                'ga4' => Texto::textoDe(Texto::pegar($r, 'ga4')),
                'metaPixel' => Texto::textoDe(Texto::pegar($r, 'metaPixel')),
            ],
            'seo' => [
                'titulo' => is_string($titulo) ? $titulo : null,
                'descricao' => is_string($descricao) ? $descricao : null,
            ],
        ];
    }

    /**
     * Registro de todos os campos da biblioteca: chave → definição + dono.
     * Escalares: "serv.titulo" → [...def, dono, grupo, campo].
     * Itens de lista: "serv.*.t" → [...def, dono, lista, campo].
     * Se dois manifestos definirem a mesma chave, vale o primeiro (ordem do bundle).
     */
    public static function registroCampos(mixed $lib): array
    {
        $registro = [];
        foreach (Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'secoes')) as $tipo => $sec) {
            $tipo = (string) $tipo;
            $manifest = Texto::comoMapa(Texto::pegar(Texto::comoMapa($sec), 'manifest'));
            foreach (Texto::comoMapa(Texto::pegar($manifest, 'campos')) as $chave => $def) {
                $chave = (string) $chave;
                $partes = explode('.', $chave);
                if (count($partes) !== 2 || array_key_exists($chave, $registro)) {
                    continue;
                }
                $registro[$chave] = array_merge(Texto::comoMapa($def), ['dono' => $tipo, 'grupo' => $partes[0], 'campo' => $partes[1]]);
            }
            foreach (Texto::comoMapa(Texto::pegar($manifest, 'listas')) as $lista => $ldef) {
                foreach (Texto::comoMapa(Texto::pegar(Texto::comoMapa($ldef), 'campos')) as $campo => $def) {
                    $chave = $lista . '.*.' . $campo;
                    if (array_key_exists($chave, $registro)) {
                        continue;
                    }
                    $registro[$chave] = array_merge(Texto::comoMapa($def), ['dono' => $tipo, 'lista' => (string) $lista, 'campo' => (string) $campo]);
                }
            }
        }
        return $registro;
    }

    /** Definições das listas: nome → [...def (repete, rotuloItem, campos), dono]. */
    public static function registroListas(mixed $lib): array
    {
        $listas = [];
        foreach (Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'secoes')) as $tipo => $sec) {
            $manifest = Texto::comoMapa(Texto::pegar(Texto::comoMapa($sec), 'manifest'));
            foreach (Texto::comoMapa(Texto::pegar($manifest, 'listas')) as $lista => $ldef) {
                if (!array_key_exists($lista, $listas)) {
                    $listas[(string) $lista] = array_merge(Texto::comoMapa($ldef), ['dono' => (string) $tipo]);
                }
            }
        }
        return $listas;
    }

    /** Definição de uma chave concreta ("serv.nk3f.t" → registro["serv.*.t"]); null se não houver. */
    public static function definicaoCampo(array $registro, string $chave): ?array
    {
        $partes = explode('.', $chave);
        $def = match (count($partes)) {
            2 => Texto::pegar($registro, $chave),
            3 => Texto::pegar($registro, $partes[0] . '.*.' . $partes[2]),
            default => null,
        };
        return is_array($def) ? $def : null;
    }

    /**
     * JSON do documento preservando {} nos mapas vazios (o PHP codificaria [] e o JS
     * perderia chaves gravadas num array). Use ao gravar/enviar documentos.
     */
    public static function json(array $doc, int $flags = 0): string
    {
        foreach (self::MAPAS as $campo) {
            if (array_key_exists($campo, $doc) && $doc[$campo] === []) {
                $doc[$campo] = new \stdClass();
            }
        }
        if (isset($doc['dados']) && is_array($doc['dados']) && array_key_exists('horarios', $doc['dados']) && $doc['dados']['horarios'] === []) {
            $doc['dados']['horarios'] = new \stdClass();
        }
        return json_encode($doc, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
