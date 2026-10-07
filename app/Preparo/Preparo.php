<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Preparo do site: view de cada template, invólucros, imagens, menu e dados derivados.
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/preparo.mjs (contrato §5).
 */
final class Preparo
{
    private const MENU_MAXIMO = 5;
    private const POSICOES = 9;

    /** Opções com padrões: [modo, urlMidia, midia, ano]. */
    public static function normalizarOpcoes(mixed $opcoes): array
    {
        return Html::normalizarOpcoes($opcoes);
    }

    /** Motor Mustache com o escape do §5.1 e as parciais da biblioteca. */
    public static function motor(array $parciais): \Mustache_Engine
    {
        return new \Mustache_Engine([
            'escape' => static fn ($v): string => Texto::escapeHtml($v),
            'partials' => $parciais,
            'strict_callables' => true, // strings/arrays nunca viram chamadas de função
            'charset' => 'UTF-8',
        ]);
    }

    /**
     * Renderiza um template com o motor configurado (escape do §5.1 e parciais).
     *
     * @throws \Mustache_Exception template que não compila
     */
    public static function renderizarTemplate(string $template, mixed $view, array $parciais = []): string
    {
        $p = [];
        foreach (Texto::comoMapa($parciais) as $nome => $t) {
            if (is_string($t)) {
                $p[(string) $nome] = $t;
            }
        }
        return self::motor($p)->render($template, $view ?? []);
    }

    private static function sizesPara(array $sizes, string $chave): string
    {
        $exato = Texto::pegar($sizes, $chave);
        if (is_string($exato)) {
            return $exato;
        }
        $partes = explode('.', $chave);
        foreach ($sizes as $padrao => $valor) {
            $padrao = (string) $padrao;
            if (!is_string($valor) || !str_contains($padrao, '*')) {
                continue;
            }
            $pp = explode('.', $padrao);
            if (count($pp) !== count($partes)) {
                continue;
            }
            $casou = true;
            foreach ($pp as $i => $p) {
                if ($p !== '*' && $p !== $partes[$i]) {
                    $casou = false;
                    break;
                }
            }
            if ($casou) {
                return $valor;
            }
        }
        return '100vw';
    }

    private static function rotuloImagem(mixed $def): string
    {
        $r = Texto::colapsarEspacos(Texto::pegar(Texto::comoMapa($def), 'rotulo'));
        return $r !== '' ? $r : 'Foto';
    }

    private static function altCom(string $base, string $nome): string
    {
        if ($base !== '' && $nome !== '') {
            return $base . ' — ' . $nome;
        }
        return $base !== '' ? $base : $nome;
    }

    private static function ehCampoTexto(array $def): bool
    {
        $tipo = Texto::pegar($def, 'tipo');
        return $tipo !== 'imagem' && $tipo !== 'icone';
    }

    /** Parte da view que não depende da seção: textos, ícones e listas efetivas. */
    private static function montarContexto(array $doc, array $lib, string $acabamento): array
    {
        $vars = Textos::contextoVariaveis($doc, $lib);
        $iconesDoc = Texto::comoMapa($doc['icones']);
        $grupos = [];
        foreach (Documento::registroCampos($lib) as $chave => $def) {
            $chave = (string) $chave;
            if (str_contains($chave, '*')) {
                continue;
            }
            $valor = null;
            if (Texto::pegar($def, 'tipo') === 'icone') {
                $manual = Texto::pegar($iconesDoc, $chave);
                $id = is_string($manual) && Icones::acharIcone($lib, $manual) !== null ? $manual : Icones::ICONE_RESERVA;
                $valor = ['id' => $id, 'svg' => Icones::svg($lib, $id, $acabamento)];
            } elseif (self::ehCampoTexto($def)) {
                $valor = Textos::textoEfetivo($doc, $lib, $chave, $vars);
            }
            $grupos[$def['grupo']][] = ['campo' => $def['campo'], 'def' => $def, 'chave' => $chave, 'valor' => $valor];
        }
        $listas = [];
        foreach (Documento::registroListas($lib) as $lista => $ldef) {
            $lista = (string) $lista;
            $campos = [];
            foreach (Texto::comoMapa(Texto::pegar($ldef, 'campos')) as $campo => $def) {
                $campos[] = ['campo' => (string) $campo, 'def' => Texto::comoMapa($def)];
            }
            [, $max] = Textos::limitesLista($lib, $lista);
            $ids = array_slice(Textos::itensLista($doc, $lib, $lista), 0, $max);
            $itens = [];
            foreach ($ids as $pos => $id) {
                $textos = [];
                $icone = null;
                foreach ($campos as ['campo' => $campo, 'def' => $def]) {
                    if (Texto::pegar($def, 'tipo') === 'icone') {
                        $iconeId = Icones::iconeDoItem($doc, $lib, $lista, $id, $pos, $vars);
                        $icone = ['id' => $iconeId, 'svg' => Icones::svg($lib, $iconeId, $acabamento)];
                    } elseif (self::ehCampoTexto($def)) {
                        $textos[$campo] = Textos::textoEfetivo($doc, $lib, $lista . '.' . $id . '.' . $campo, $vars);
                    }
                }
                $base = '';
                foreach (['t', 'n'] as $c) {
                    if (isset($textos[$c]) && $textos[$c] !== '') {
                        $base = $textos[$c];
                        break;
                    }
                }
                $itens[] = ['id' => $id, 'textos' => $textos, 'icone' => $icone, 'altBase' => $base];
            }
            $listas[$lista] = ['campos' => $campos, 'itens' => $itens];
        }
        return ['vars' => $vars, 'imagens' => Texto::comoMapa($doc['imagens']), 'grupos' => $grupos, 'listas' => $listas];
    }

    /** `c` da view, construído POR SEÇÃO (sizes dependem da opção; lcp, da posição). */
    private static function montarC(array $ctx, array $opcaoDef, bool $lcp, array $op): array
    {
        $sizes = Texto::comoMapa(Texto::pegar($opcaoDef, 'sizes'));
        $imagem = static fn (string $chave, string $rotulo, string $altBase): array => Html::imagem([
            'chave' => $chave,
            'midiaId' => Texto::pegar($ctx['imagens'], $chave),
            'rotulo' => $rotulo,
            'alt' => self::altCom($altBase, $ctx['vars']['nome']),
            'sizes' => self::sizesPara($sizes, $chave),
            'lcp' => $lcp,
        ], $op);
        $c = [];
        foreach ($ctx['grupos'] as $grupo => $campos) {
            $g = [];
            foreach ($campos as $campo) {
                if (Texto::pegar($campo['def'], 'tipo') === 'imagem') {
                    $rotulo = self::rotuloImagem($campo['def']);
                    $g[$campo['campo']] = $imagem($campo['chave'], $rotulo, $rotulo);
                } else {
                    $g[$campo['campo']] = $campo['valor'];
                }
            }
            $c[(string) $grupo] = $g;
        }
        foreach ($ctx['listas'] as $lista => $info) {
            $g = $c[$lista] ?? [];
            $n = count($info['itens']);
            $itens = [];
            foreach ($info['itens'] as $pos => $it) {
                $item = [
                    'id' => $it['id'],
                    'k' => $lista . '.' . $it['id'],
                    'i' => $pos + 1,
                    'primeiro' => $pos === 0,
                    'ultimo' => $pos === $n - 1,
                    'par' => ($pos + 1) % 2 === 0,
                ];
                foreach ($info['campos'] as ['campo' => $campo, 'def' => $def]) {
                    $tipo = Texto::pegar($def, 'tipo');
                    if ($tipo === 'imagem') {
                        $item[$campo] = $imagem($lista . '.' . $it['id'] . '.' . $campo, self::rotuloImagem($def), $it['altBase']);
                    } elseif ($tipo === 'icone') {
                        $item[$campo] = $it['icone'];
                    } else {
                        $item[$campo] = $it['textos'][$campo] ?? '';
                    }
                }
                $itens[] = $item;
            }
            $g['itens'] = $itens;
            $g['qtd'] = $n;
            $g['tem'] = $n > 0;
            for ($p = 1; $p <= min(self::POSICOES, $n); $p++) {
                $g['p' . $p] = $itens[$p - 1];
            }
            $c[(string) $lista] = $g;
        }
        return $c;
    }

    /**
     * Ícones utilitários no peso do acabamento: [seta => "<svg…>", …] (direto dos utilitários,
     * mesmo que a lista principal tenha um ícone com o mesmo id).
     */
    private static function montarUtilitarios(array $lib, string $acabamento): array
    {
        $u = [];
        $utilitarios = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar($lib, 'icones')), 'utilitarios'));
        foreach ($utilitarios as $nome => $def) {
            $u[(string) $nome] = Icones::svgDaDefinicao($def, $acabamento);
        }
        return $u;
    }

    /** `d` da view: dados derivados, já formatados (§5.3). */
    public static function montarDados(array $doc, array $lib, mixed $opcoes, ?array $contexto = null, array $u = []): array
    {
        $op = Html::normalizarOpcoes($opcoes);
        $vars = $contexto ?? Textos::contextoVariaveis($doc, $lib);
        $dados = Documento::completarDados(Texto::pegar($doc, 'dados'));
        $nicho = Documento::nichoDe($doc, $lib);
        $exemplo = Texto::comoMapa(Texto::pegar($nicho, 'exemplo'));
        $ufDado = mb_strtoupper(Texto::colapsarEspacos($dados['uf']), 'UTF-8');
        $uf = $ufDado !== ''
            ? $ufDado
            : (Texto::colapsarEspacos($dados['cidade']) === '' ? mb_strtoupper(Texto::colapsarEspacos(Texto::pegar($exemplo, 'uf')), 'UTF-8') : '');
        $cidadeUf = $vars['cidade'] !== '' && $uf !== '' ? $vars['cidade'] . ' - ' . $uf : $vars['cidade'] . $uf;
        $whatsapp = Texto::colapsarEspacos($dados['whatsapp']);
        if ($whatsapp === '') {
            $whatsapp = Texto::colapsarEspacos(Texto::pegar($exemplo, 'whatsapp'));
        }
        $mensagem = Textos::substituirVariaveis(Texto::textoDe(Texto::pegar($nicho, 'mensagemWhatsapp')), $vars);
        $telefone = Texto::colapsarEspacos($dados['telefone']);
        $temTelefone = strlen(Dados::digitosNacionais($telefone)) >= 8;
        $email = Texto::colapsarEspacos($dados['email']);
        $temEmail = Dados::validarEmail($email);
        $enderecoLinhas = Dados::linhasEndereco($dados);
        $endereco = implode(' - ', $enderecoLinhas);
        $consultaMapa = $endereco !== '' ? $endereco : $cidadeUf;
        $horarios = Dados::formatarHorarios($dados['horarios']);
        $registro = Dados::formatarRegistro($dados, Documento::especialidade($doc, $lib));
        $redes = [];
        foreach (Documento::REDES as $rede) {
            $url = Dados::urlRede($rede, $dados['redes'][$rede]);
            if ($url !== '') {
                $redes[] = ['rede' => $rede, 'rotulo' => Dados::rotuloRede($rede), 'url' => $url, 'svg' => Texto::textoDe(Texto::pegar($u, $rede))];
            }
        }
        $letra = Dados::inicial($vars['nome']);
        return [
            'nome' => $vars['nome'],
            'cidade' => $vars['cidade'],
            'uf' => $uf,
            'cidadeUf' => $cidadeUf,
            'inicial' => $letra,
            'whatsapp' => $whatsapp !== '' ? Dados::formatarTelefone($whatsapp) : '',
            'whatsappLink' => Dados::linkWhatsapp($whatsapp, $mensagem),
            'temWhatsapp' => Dados::validarWhatsapp($whatsapp),
            'telefone' => $temTelefone ? Dados::formatarTelefone($telefone) : '',
            'telefoneLink' => $temTelefone ? Dados::linkTelefone($telefone) : '',
            'temTelefone' => $temTelefone,
            'email' => $temEmail ? $email : '',
            'emailLink' => $temEmail ? 'mailto:' . $email : '',
            'temEmail' => $temEmail,
            'endereco' => $endereco,
            'enderecoLinhas' => $enderecoLinhas,
            'temEndereco' => $enderecoLinhas !== [],
            'mapaLink' => $consultaMapa !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . Texto::codificarUri($consultaMapa) : '',
            'mapaEmbed' => $consultaMapa !== '' ? 'https://www.google.com/maps?q=' . Texto::codificarUri($consultaMapa) . '&output=embed' : '',
            'horarios' => $horarios,
            'horariosTexto' => Dados::horariosTexto($horarios),
            'temHorarios' => $horarios !== [],
            'registro' => $registro,
            'temRegistro' => $registro !== '',
            'redes' => $redes,
            'temRedes' => $redes !== [],
            'logo' => Html::logo(['midiaId' => $dados['logo'], 'nome' => $vars['nome'], 'inicial' => $letra], $op),
            'ano' => $op['ano'],
            'segmento' => $vars['segmento'],
        ];
    }

    /** Menu (§5.6): seções com `menu`, na ordem da página, rótulo do nicho ?? manifesto, até 5. */
    private static function montarMenu(array $validas, array $nicho): array
    {
        $menu = [];
        $doNicho = Texto::comoMapa(Texto::pegar($nicho, 'menu'));
        foreach ($validas as $v) {
            if (count($menu) >= self::MENU_MAXIMO) {
                break;
            }
            $rotuloManifest = Texto::pegar($v['manifest'], 'menu');
            if (!is_string($rotuloManifest) || $rotuloManifest === '') {
                continue;
            }
            $r = Texto::pegar($doNicho, $v['tipo']);
            $menu[] = ['rotulo' => is_string($r) && $r !== '' ? $r : $rotuloManifest, 'href' => '#' . $v['ancora']];
        }
        return $menu;
    }

    private static function ancoraDe(array $manifest, string $tipo): string
    {
        if ($tipo === 'header') {
            return 'topo';
        }
        $a = Texto::pegar($manifest, 'ancora');
        return is_string($a) && preg_match('/^[a-z][a-z0-9-]*$/D', $a) ? $a : $tipo;
    }

    /**
     * Prepara e renderiza o site inteiro.
     * Nunca lança por dado faltando: seção/opção/template desconhecidos são pulados com aviso.
     * `secoes[].html` é o invólucro completo; `alvoPular` é a âncora do primeiro conteúdo após o header.
     *
     * @return array{html: string, cssPaleta: string, classesRaiz: string, secoes: list<array>, avisos: list<array>, alvoPular: string}
     */
    public static function prepararSite(mixed $docEntrada, mixed $lib, mixed $opcoes = []): array
    {
        $avisos = [];
        $avisar = static function (string $codigo, string $mensagem, array $extra = []) use (&$avisos): void {
            $avisos[] = array_merge(['codigo' => $codigo, 'mensagem' => $mensagem], $extra);
        };
        $op = Html::normalizarOpcoes($opcoes);
        $biblioteca = Texto::comoMapa($lib);
        $bruto = Texto::comoMapa($docEntrada);
        $doc = Documento::migrar($bruto, $biblioteca);
        $nicho = Documento::nichoDe($doc, $biblioteca);
        if (!Texto::ehMapa(Texto::pegar(Texto::comoMapa(Texto::pegar($biblioteca, 'nichos')), $doc['nicho']))) {
            $avisar('nicho_desconhecido', 'Nicho desconhecido: "' . $doc['nicho'] . '".');
        }
        $estiloBruto = Texto::comoMapa(Texto::pegar($bruto, 'estilo'));
        $estilo = $doc['estilo'];
        if (Texto::temChave($estiloBruto, 'cor') && Paleta::normalizarCor($estiloBruto['cor']) === null) {
            $avisar('cor_invalida', 'A cor do site é inválida; usando a cor padrão.');
        }
        $acabamento = $estilo['acabamento'];
        $fonte = $estilo['fonte'];
        $paleta = Paleta::gerarPaleta($estilo['cor']);

        $validas = [];
        $vistos = [];
        $secoesLib = Texto::comoMapa(Texto::pegar($biblioteca, 'secoes'));
        foreach ($doc['secoes'] as $indice => $s) {
            $sec = Texto::pegar($secoesLib, $s['tipo']);
            if (!Texto::ehMapa($sec)) {
                $avisar('secao_desconhecida', 'Seção desconhecida: "' . $s['tipo'] . '".', ['indice' => $indice]);
                continue;
            }
            $manifest = Texto::comoMapa(Texto::pegar($sec, 'manifest'));
            $opcaoDef = null;
            foreach (Texto::comoLista(Texto::pegar($manifest, 'opcoes')) as $o) {
                if (Texto::ehMapa($o) && Texto::pegar($o, 'id') === $s['opcao']) {
                    $opcaoDef = $o;
                    break;
                }
            }
            if ($opcaoDef === null) {
                $avisar('opcao_desconhecida', 'Opção desconhecida: "' . $s['tipo'] . '/' . $s['opcao'] . '".', ['indice' => $indice]);
                continue;
            }
            $template = Texto::pegar(Texto::comoMapa(Texto::pegar($sec, 'templates')), $s['opcao']);
            if (!is_string($template)) {
                $avisar('template_ausente', 'Template ausente: "' . $s['tipo'] . '/' . $s['opcao'] . '".', ['indice' => $indice]);
                continue;
            }
            if (isset($vistos[$s['tipo']])) {
                $avisar('secao_duplicada', 'A seção "' . $s['tipo'] . '" aparece mais de uma vez; só a primeira é mostrada.', ['indice' => $indice]);
                continue;
            }
            $vistos[$s['tipo']] = true;
            $validas[] = [
                'indice' => $indice, 'tipo' => $s['tipo'], 'opcao' => $s['opcao'], 'manifest' => $manifest,
                'opcaoDef' => $opcaoDef, 'template' => $template, 'ancora' => self::ancoraDe($manifest, $s['tipo']),
            ];
        }

        $fundos = Tons::calcularFundos(array_map(static fn (array $v) => Texto::pegar($v['opcaoDef'], 'tom'), $validas));
        $contexto = self::montarContexto($doc, $biblioteca, $acabamento);
        $u = self::montarUtilitarios($biblioteca, $acabamento);
        $d = self::montarDados($doc, $biblioteca, $op, $contexto['vars'], $u);
        $e = [
            'acabamento' => $acabamento, 'fonte' => $fonte,
            'classico' => $acabamento === 'classico', 'moderno' => $acabamento === 'moderno', 'direto' => $acabamento === 'direto',
        ];
        $menu = self::montarMenu($validas, $nicho);
        $modo = ['editor' => $op['modo'] === 'editor', 'publicar' => $op['modo'] === 'publicar'];
        $parciais = [];
        foreach (Texto::comoMapa(Texto::pegar($biblioteca, 'parciais')) as $nome => $t) {
            if (is_string($t)) {
                $parciais[(string) $nome] = $t;
            }
        }
        $motor = self::motor($parciais);
        $posicaoLcp = -1;
        foreach ($validas as $pos => $v) {
            if ($v['tipo'] !== 'header') {
                $posicaoLcp = $pos;
                break;
            }
        }
        $corClara = $paleta['tokens']['--on-p'] === Paleta::TEXTO_CLARO;

        $secoes = [];
        foreach ($validas as $pos => $v) {
            $fundo = $fundos[$pos];
            $c = self::montarC($contexto, $v['opcaoDef'], $pos === $posicaoLcp, $op);
            // `dados` e `conteudo` são apelidos de `d` e `c` para usar dentro de laços de itens
            // cujos campos se chamam d (serv, dif, passos) ou c (equipe, dep) e escondem a raiz.
            $view = [
                'c' => $c,
                'd' => $d,
                's' => [
                    'tipo' => $v['tipo'], 'opcao' => $v['opcao'], 'fundo' => $fundo,
                    'escuro' => $fundo === 'escuro' || ($fundo === 'cor' && $corClara), 'indice' => $v['indice'],
                ],
                'e' => $e,
                'u' => $u,
                'menu' => $menu,
                'modo' => $modo,
                'dados' => $d,
                'conteudo' => $c,
            ];
            try {
                $interno = $motor->render($v['template'], $view);
            } catch (\Throwable $erro) {
                $avisar('erro_template', 'Erro no template "' . $v['tipo'] . '/' . $v['opcao'] . '".', ['indice' => $v['indice'], 'detalhe' => $erro->getMessage()]);
                continue;
            }
            $secoes[] = [
                'indice' => $v['indice'], 'tipo' => $v['tipo'], 'opcao' => $v['opcao'], 'fundo' => $fundo, 'ancora' => $v['ancora'],
                'html' => Html::involucro($v['tipo'], $v['opcao'], $fundo, $v['ancora'], $v['indice'], $interno),
            ];
        }

        $partes = array_map(static fn (array $s): string => $s['html'], $secoes);
        if ($estilo['whatsappFlutuante'] && $d['temWhatsapp']) {
            $partes[] = Html::botaoWhatsapp($d['whatsappLink'], Texto::pegar($u, 'whatsapp'));
        }
        $alvo = '';
        foreach ($secoes as $s) {
            if ($s['tipo'] !== 'header') {
                $alvo = $s['ancora'];
                break;
            }
        }
        return [
            'html' => implode("\n", $partes),
            'cssPaleta' => Paleta::cssPaleta($paleta['tokens']),
            'classesRaiz' => 'rk k-' . $acabamento . ' f-' . $fonte,
            'secoes' => $secoes,
            'avisos' => $avisos,
            'alvoPular' => $alvo,
        ];
    }
}
