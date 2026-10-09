<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

use Rankly\Preparo\Documento;
use Rankly\Preparo\Texto;
use Rankly\Preparo\Textos;

/**
 * Escreve os textos do site com IA a partir de uma descrição do negócio.
 *
 * A IA só preenche CHAVES DE CONTEÚDO que já existem (com o limite de caracteres de cada
 * uma), então o layout continua certo sozinho. Travas do nosso lado, valham o que valer
 * as instruções dadas à IA:
 *  - nunca escreve "alegações" (números, depoimentos, nota do Google, clientes) nem nomes da
 *    equipe — isso fica com o exemplo e passa pela lista de verificação da publicação;
 *  - descarta textos com termos proibidos pelo conselho do nicho (OAB, CFO/CFM, CRC…);
 *  - corta no limite de caracteres, limpa formatação e retokeniza nome/cidade ({nome}/{cidade});
 *  - listas respeitam o mínimo e o máximo de itens do manifesto.
 *
 * Como a IA escreve (copy "de agência", não de formulário):
 *  - primeiro uma ESTRATÉGIA (público, desejo, receio, promessa central e provas tiradas da
 *    descrição), depois os textos a partir dela — o site vira uma conversa só, do destaque à chamada final;
 *  - cada campo vai com o seu PAPEL (o que aquele texto precisa fazer) e um tamanho-alvo;
 *  - o guia de copy do ramo (biblioteca/nichos: público, desejos, receios, objeções, clichês a evitar)
 *    e o TOM DE VOZ escolhido (ou o recomendado para o ramo) entram nas instruções;
 *  - o RevisorCopy aponta o que um redator mandaria refazer (clichê, repetição, cidade demais,
 *    passou do limite, frase cortada…) e só esses campos voltam para a IA numa segunda rodada.
 *
 * Devolve um "patch" que o editor aplica como UMA alteração desfazível (nada é salvo aqui).
 */
final class GeradorConteudo
{
    /** Campos escalares que a IA não escreve (rótulos de botão e afins ficam com o padrão). */
    private const CAMPOS_FORA = ['cta', 'cta2', 'botao', 'link', 'mapa'];

    /** Grupos inteiros fora: alegação (nota do Google) e rótulos dos atalhos (presos aos dados: WhatsApp, horário, endereço). */
    private const GRUPOS_FORA = ['aval', 'atalhos'];

    /** Chaves escalares fora. */
    private const CHAVES_FORA = ['rodape.texto'];

    /** Listas que a IA preenche e os campos de cada uma (depoimentos, números, clientes e equipe ficam de fora). */
    private const LISTAS = [
        'serv' => ['t', 'd'],
        'dif' => ['t', 'd'],
        'passos' => ['t', 'd'],
        'faq' => ['q', 'a'],
        'sobrel' => ['t'],
    ];

    public const MAX_DESCRICAO = 1500;
    public const MAX_SEO_TITULO = 65;
    public const MAX_SEO_DESCRICAO = 160;

    /** Tons de voz que a pessoa pode escolher (vazio = o recomendado para o ramo). */
    public const TONS = [
        'acolhedor' => 'acolhedor: próximo e caloroso, tranquiliza quem está inseguro; fala com "você" em frases simples e gentis',
        'sofisticado' => 'sofisticado: elegante e seguro, com vocabulário refinado sem ser rebuscado; poucas palavras, bem escolhidas; sem exclamações',
        'direto' => 'direto: objetivo e enérgico; verbos de ação, frases curtas, foco no resultado e no próximo passo',
        'tecnico' => 'técnico e confiável: sério e preciso; transmite autoridade com os termos certos da área, sempre explicados em linguagem simples',
        'leve' => 'leve e simpático: conversa como gente, com bom humor discreto; sem gírias e sem exageros',
    ];

    /** O que cada texto precisa fazer na página (por chave exata ou pelo nome do campo). */
    private const PAPEIS = [
        'hero.titulo' => 'promessa principal do site: o resultado que o cliente quer + o serviço principal + a cidade, numa frase natural. Não basta "Serviço em Cidade"',
        'hero.texto' => 'como vocês entregam a promessa e para quem: cite 2 ou 3 serviços e um fato concreto da descrição',
        'hero.eyebrow' => 'o que o negócio é, em 2 a 4 palavras (ex.: "Clínica odontológica")',
        'hero.selo' => 'um diferencial factual curto citado na descrição (ex.: "Atendimento aos sábados"); se não houver, devolva vazio',
        'form.titulo' => 'convite curto para pedir contato pelo formulário, com verbo de ação',
        'form.nota' => 'frase curta que tira o receio de enviar (ex.: sem compromisso); não prometa prazo de resposta',
        'serv.titulo' => 'título dos serviços com o serviço principal e a cidade (ajuda no Google)',
        'serv.texto' => 'para quem são os serviços e como vocês ajudam a escolher o certo',
        'dif.titulo' => 'por que escolher vocês, dito como benefício para o cliente (evite "Por que escolher a Empresa")',
        'sobre.titulo' => 'título do "sobre" que mostra a forma de trabalhar ou o propósito, não só o nome',
        'sobre.texto' => 'quem são, como trabalham e para quem, só com fatos da descrição; 2 a 4 frases curtas, terminando com frase completa',
        'passos.titulo' => 'como é começar com vocês, prometendo simplicidade',
        'passos.texto' => 'tranquiliza: explica que o caminho é claro do primeiro contato ao resultado',
        'equipe.titulo' => 'título da equipe focado em como ela cuida do cliente (sem nomes e sem anos de experiência)',
        'equipe.texto' => 'como a equipe trabalha e trata o cliente, sem inventar formação, números ou nomes',
        'dep.titulo' => 'título da seção de depoimentos (não invente quantidades nem notas)',
        'cli.titulo' => 'título da faixa de clientes atendidos (não invente quantidades)',
        'faq.titulo' => 'título das perguntas frequentes',
        'faq.texto' => 'convida a tirar as dúvidas antes de agendar ou contratar',
        'faq.ajuda' => 'convite curto para quem ainda tem dúvida chamar no WhatsApp',
        'cta.titulo' => 'convite final: benefício + ação, que dá vontade de chamar agora',
        'cta.texto' => 'reforça a promessa e diminui o risco do primeiro passo (ex.: avaliação, conversa inicial)',
        'contato.titulo' => 'título do contato, convidando a falar com vocês',
        'contato.texto' => 'como e onde falar com vocês, com o canal mais rápido primeiro',
        'rodape.sobre' => 'uma frase que resume o negócio: o que faz, para quem e onde',
        'eyebrow' => 'rótulo curto acima do título (2 a 4 palavras) que nomeia o assunto da seção',
        'titulo' => 'título da seção: diz algo novo e concreto, sem ponto final',
        'texto' => 'texto de apoio da seção: uma ou duas frases completas',
        'serv.t' => 'nome do serviço como o cliente procura no Google (2 a 5 palavras)',
        'serv.d' => 'o que o cliente ganha e como é feito, sem repetir o nome do serviço',
        'dif.t' => 'o diferencial em 2 a 4 palavras, tirado da descrição',
        'dif.d' => 'explica ou prova o diferencial com um fato concreto da descrição',
        'passos.t' => 'nome curto da etapa, começando com verbo (ex.: "Agende sua avaliação")',
        'passos.d' => 'o que acontece nessa etapa, do ponto de vista do cliente',
        'faq.q' => 'pergunta como o cliente faria, sobre uma dúvida ou receio real antes de contratar',
        'faq.a' => 'resposta direta já na primeira frase, honesta e sem prometer resultado',
        'sobrel.t' => 'item curto e verificável sobre a forma de trabalhar (tirado da descrição)',
    ];

    /** Rascunhos que demoraram mais que isso não passam pela revisão (o pedido ficaria longo demais). */
    private const REVISAO_ATE_SEGUNDOS = 60;

    public function __construct(private readonly Provedor $provedor, private readonly bool $revisar = true)
    {
    }

    /**
     * @param array  $doc       documento do site (versaoEsquema 2)
     * @param array  $lib       bundle da biblioteca
     * @param string $descricao o que a pessoa escreveu sobre o negócio
     * @param string $escopo    "site" ou um tipo de seção ("servicos", "faq"…)
     * @param string $tom       chave de TONS, ou vazio para o tom recomendado para o ramo
     * @return array{textos: array<string,string>, remover: list<string>, listas: array<string,list<string>>,
     *               iconesRemover: list<string>, seo: ?array{titulo: string, descricao: string},
     *               palavrasChave: list<string>, avisos: list<string>, revisados: int}
     * @throws ErroIa
     */
    public function gerar(array $doc, array $lib, string $descricao, string $escopo = 'site', string $tom = ''): array
    {
        $descricao = Texto::colapsarEspacos(mb_substr(trim($descricao), 0, self::MAX_DESCRICAO, 'UTF-8'));
        [$escalares, $listas] = $this->camposDoEscopo($lib, $escopo);
        if ($escalares === [] && $listas === []) {
            throw new ErroIa('Esta seção não tem textos que a IA possa escrever.');
        }
        $comSeo = $escopo === 'site';
        $instrucoes = $this->instrucoes($doc, $lib, $tom);
        $inicio = microtime(true);
        $resposta = $this->provedor->gerarJson(
            $instrucoes,
            $this->pedido($doc, $lib, $descricao, $escalares, $listas, $comSeo),
            $this->esquema($escalares, $listas, $comSeo),
        );
        $revisados = 0;
        if ($this->revisar && microtime(true) - $inicio < self::REVISAO_ATE_SEGUNDOS) {
            [$resposta, $revisados] = $this->revisar($doc, $lib, $instrucoes, $descricao, $resposta, $escalares, $listas, $comSeo);
        }
        $patch = $this->filtrar($doc, $lib, $resposta, $escalares, $listas, $comSeo);
        $patch['revisados'] = $revisados;
        return $patch;
    }

    /**
     * Segunda rodada: o RevisorCopy aponta os problemas e só esses campos voltam para a IA.
     * Se a revisão falhar, fica o rascunho (as travas do filtro continuam valendo).
     *
     * @return array{0: array, 1: int} [resposta (rascunho com as correções), campos revisados]
     */
    private function revisar(array $doc, array $lib, string $instrucoes, string $descricao, array $rascunho,
        array $escalares, array $listas, bool $comSeo): array
    {
        $c = $this->contexto($doc, $lib);
        $problemas = RevisorCopy::avaliar($rascunho, $escalares, $listas, $c, $descricao, $comSeo);
        if ($problemas === []) {
            return [$rascunho, 0];
        }
        $escRev = array_intersect_key($escalares, $problemas);
        $lisRev = [];
        foreach ($listas as $nome => $l) {
            if (isset($problemas['lista:' . $nome])) {
                $lisRev[$nome] = $l;
            }
        }
        $seoRev = $comSeo && isset($problemas['seo']);
        if ($escRev === [] && $lisRev === [] && !$seoRev) {
            return [$rascunho, 0];
        }
        $pedido = "Você é o mesmo redator e recebeu a revisão do rascunho abaixo. Reescreva SOMENTE os campos listados em \"problemas\", "
            . "resolvendo cada problema apontado, com a mesma estratégia, o mesmo tom e as mesmas regras. "
            . "Mantenha a coerência com o resto do rascunho e não repita expressões que já estão nele. "
            . "Para uma lista, devolva a lista inteira corrigida. Devolva só o JSON pedido.\n\nDados:\n"
            . json_encode([
                'negocio' => $this->negocio($c, $descricao),
                'estrategia' => Texto::comoMapa($rascunho['estrategia'] ?? []),
                'rascunho' => array_intersect_key($rascunho, array_flip(['textos', 'listas', 'seo'])),
                'problemas' => $problemas,
                'campos' => $this->descreverCampos($escRev),
                'listas' => $this->descreverListas($doc, $lib, $lisRev),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        try {
            $correcao = $this->provedor->gerarJson($instrucoes, $pedido, $this->esquema($escRev, $lisRev, $seoRev, false));
        } catch (ErroIa) {
            return [$rascunho, 0];
        }
        $revisado = $rascunho;
        $novos = Texto::comoMapa($correcao['textos'] ?? []);
        foreach (array_keys($escRev) as $chave) {
            if (is_string($novos[$chave] ?? null) && trim($novos[$chave]) !== '') {
                $revisado['textos'][$chave] = $novos[$chave];
            }
        }
        $novasListas = Texto::comoMapa($correcao['listas'] ?? []);
        foreach (array_keys($lisRev) as $nome) {
            if (Texto::comoLista($novasListas[$nome] ?? []) !== []) {
                $revisado['listas'][$nome] = $novasListas[$nome];
            }
        }
        if ($seoRev && is_array($correcao['seo'] ?? null)) {
            $revisado['seo'] = $correcao['seo'];
        }

        // A correção não pode piorar: campo que ficou com problema grave (termo proibido, itens de
        // menos, vazio, longo demais) sem ter antes volta ao rascunho.
        $antes = RevisorCopy::graves($problemas);
        $depois = RevisorCopy::graves(RevisorCopy::avaliar($revisado, $escalares, $listas, $c, $descricao, $comSeo));
        $revisados = 0;
        foreach ([...array_keys($escRev), ...array_map(static fn (string $n): string => 'lista:' . $n, array_keys($lisRev)), ...($seoRev ? ['seo'] : [])] as $campo) {
            [$secao, $chave] = match (true) {
                $campo === 'seo' => ['seo', null],
                str_starts_with($campo, 'lista:') => ['listas', substr($campo, 6)],
                default => ['textos', $campo],
            };
            $novo = $chave === null ? ($revisado[$secao] ?? null) : ($revisado[$secao][$chave] ?? null);
            $velho = $chave === null ? ($rascunho[$secao] ?? null) : ($rascunho[$secao][$chave] ?? null);
            if ($novo === $velho) {
                continue;
            }
            if (isset($depois[$campo]) && !isset($antes[$campo])) {
                if ($chave === null) {
                    $revisado[$secao] = $velho;
                } else {
                    $revisado[$secao][$chave] = $velho;
                }
                continue;
            }
            $revisados++;
        }
        return [$revisado, $revisados];
    }

    /**
     * Campos que a IA pode escrever no escopo.
     *
     * @return array{0: array<string, array>, 1: array<string, array{campos: array<string, array>, min: int, max: int, rotulo: string}>}
     */
    public function camposDoEscopo(array $lib, string $escopo): array
    {
        $escalares = [];
        $listas = [];
        foreach (Documento::registroCampos($lib) as $chave => $def) {
            if (!in_array($def['tipo'] ?? 'texto', ['texto', 'texto-longo'], true)) {
                continue;
            }
            if ($escopo !== 'site' && ($def['dono'] ?? '') !== $escopo) {
                continue;
            }
            if (isset($def['grupo'])) {
                if (in_array($def['campo'], self::CAMPOS_FORA, true) || in_array($def['grupo'], self::GRUPOS_FORA, true)
                    || in_array($chave, self::CHAVES_FORA, true)) {
                    continue;
                }
                $escalares[$chave] = $def;
                continue;
            }
            $lista = (string) ($def['lista'] ?? '');
            if (!in_array($def['campo'] ?? '', self::LISTAS[$lista] ?? [], true)) {
                continue;
            }
            [$min, $max] = Textos::limitesLista($lib, $lista);
            $listas[$lista] ??= ['campos' => [], 'min' => $min, 'max' => min($max, 10),
                'rotulo' => (string) (Documento::registroListas($lib)[$lista]['rotuloItem'] ?? $lista)];
            $listas[$lista]['campos'][(string) $def['campo']] = $def;
        }
        ksort($escalares);
        ksort($listas);
        return [$escalares, $listas];
    }

    private function contexto(array $doc, array $lib): array
    {
        $nicho = Documento::nichoDe($doc, $lib);
        $esp = Documento::especialidade($doc, $lib) ?? [];
        $vars = Textos::contextoVariaveis($doc, $lib);
        $conf = Texto::comoMapa($nicho['conformidade'] ?? []);
        $copyNicho = Texto::comoMapa($nicho['copy'] ?? []);
        $copyEsp = Texto::comoMapa($esp['copy'] ?? []);
        return [
            'nome' => (string) ($vars['nome'] ?? ''),
            'cidade' => (string) ($vars['cidade'] ?? ''),
            'uf' => (string) ($doc['dados']['uf'] ?? ''),
            'nicho' => (string) ($nicho['nome'] ?? ''),
            'segmento' => (string) ($esp['segmento'] ?? ($vars['segmento'] ?? '')),
            'aviso' => (string) ($conf['aviso'] ?? ''),
            'proibidos' => array_values(array_filter(array_map('strval', (array) ($conf['termosProibidos'] ?? [])))),
            'tom' => (string) ($copyEsp['tom'] ?? ($copyNicho['tom'] ?? '')),
            'evitar' => array_values(array_filter(array_map('strval', (array) ($copyNicho['evitar'] ?? [])))),
            'guia' => array_filter([
                'publico' => $copyEsp['publico'] ?? null,
                'desejos' => $copyEsp['desejos'] ?? null,
                'receios' => $copyEsp['receios'] ?? null,
                'objecoes' => $copyEsp['objecoes'] ?? null,
                'comoDecidem' => $copyNicho['comoDecidem'] ?? null,
            ]),
        ];
    }

    /** Tom efetivo: o escolhido, senão o recomendado para o ramo, senão acolhedor. */
    public function tomEfetivo(array $doc, array $lib, string $tom = ''): string
    {
        if (isset(self::TONS[$tom])) {
            return $tom;
        }
        $recomendado = $this->contexto($doc, $lib)['tom'];
        return isset(self::TONS[$recomendado]) ? $recomendado : 'acolhedor';
    }

    public function instrucoes(array $doc, array $lib, string $tom = ''): string
    {
        $c = $this->contexto($doc, $lib);
        $evitar = array_values(array_unique(array_merge(
            ['excelência', 'qualidade e compromisso', 'soluções completas', 'atendimento diferenciado', 'profissionais altamente qualificados',
                'tecnologia de ponta', 'referência', 'venha conhecer', 'tudo o que você precisa'],
            $c['evitar'],
        )));
        $linhas = [
            'Você é um redator publicitário sênior, especialista em sites que geram contatos para pequenos negócios brasileiros e em SEO local.',
            'Escreva em português do Brasil, natural, correto e fácil de ler.',
            '',
            'COMO TRABALHAR',
            '- Preencha "estrategia" antes de tudo: para quem é o site, o que essa pessoa quer, o que a faz hesitar, a promessa central e as provas (só fatos da descrição).',
            '- Escreva todos os textos a partir dessa estratégia, como uma conversa só: o destaque promete, os serviços mostram como, os diferenciais e o "sobre" provam, as perguntas tiram os receios e a chamada final convida para o próximo passo.',
            '- Cada campo traz o seu "papel": cumpra esse papel. Fique perto do tamanho "alvo" e nunca passe do "max" (contando espaços).',
            '',
            'COPY QUE CONVENCE',
            '- Fale com o cliente ("você") sobre o que ele ganha. Benefício primeiro, característica depois.',
            '- Seja específico: um detalhe concreto da descrição (bairro, horário, convênio, forma de atendimento, etapa do serviço) vale mais que qualquer adjetivo.',
            '- Sem frases vazias. Evite: ' . implode(', ', $evitar) . '.',
            '- Cada título diz algo novo. Não repita a mesma expressão em vários campos: mesmo que esteja na descrição, use no máximo duas vezes no site todo.',
            '- Varie o começo das frases e dos itens das listas.',
            '- Títulos e rótulos com só a primeira letra maiúscula (exceto nomes próprios) e sem ponto final. Textos com frases completas e ponto final.',
            '- Texto puro: sem markdown, emojis, aspas em volta, quebras de linha ou exclamações em série.',
            '',
            'VERDADE (obrigatório)',
            '- Use SOMENTE fatos da descrição do negócio e dos dados informados. NUNCA invente números, anos de experiência, quantidade de clientes ou atendimentos, prêmios, certificações, equipamentos, tecnologias, horários, convênios, depoimentos, nomes de pessoas, preços, descontos ou promoções.',
            '- Se a descrição não traz um diferencial, explique como o serviço funciona e o que o cliente recebe, sem afirmar superioridade.',
            '- O guia do ramo serve para entender o cliente; não afirme sobre o negócio nada que a descrição não diga.',
            '',
            'SEO LOCAL',
            '- O serviço principal e a cidade aparecem, de forma natural, no título e no texto do destaque, no título dos serviços, no texto "sobre" e na descrição para o Google. Nos outros campos, cite a cidade só se soar natural (no máximo mais duas vezes).',
            '- Escreva só o nome da cidade, sem a sigla do estado.',
            '- Nomeie os serviços como o cliente procura no Google.',
            '',
            'TOM DE VOZ: ' . self::TONS[$this->tomEfetivo($doc, $lib, $tom)] . '.',
        ];
        if ($c['aviso'] !== '') {
            $linhas[] = 'Regras de publicidade da profissão (obrigatórias): ' . $c['aviso'];
        }
        if ($c['proibidos'] !== []) {
            $linhas[] = 'Nunca use estes termos, nem em outro sentido (ex.: "o melhor dia" também é proibido): ' . implode(', ', $c['proibidos']) . '.';
        }
        return implode("\n", $linhas);
    }

    /** Dados do negócio enviados à IA (a descrição é a única fonte de fatos). */
    private function negocio(array $c, string $descricao): array
    {
        return array_filter([
            'nome' => $c['nome'],
            'cidade' => $c['cidade'],
            'uf' => $c['uf'],
            'ramo' => $c['nicho'],
            'especialidade' => $c['segmento'],
            'descricao' => $descricao,
            'guiaDoRamo' => $c['guia'] ?: null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** Papel do campo na página: pela chave exata, senão pelo nome do campo, senão o rótulo. */
    private static function papel(string $chave, array $def): string
    {
        $campo = (string) ($def['campo'] ?? '');
        $lista = (string) ($def['lista'] ?? '');
        return self::PAPEIS[$chave] ?? self::PAPEIS[$lista . '.' . $campo] ?? self::PAPEIS[$campo] ?? (string) ($def['rotulo'] ?? $chave);
    }

    /** Tamanho que fica bom no layout: uns 70% do máximo. */
    private static function alvo(int $max): int
    {
        return max(10, (int) round($max * 0.7));
    }

    private function descreverCampos(array $escalares): array
    {
        $campos = [];
        foreach ($escalares as $chave => $def) {
            $max = (int) ($def['max'] ?? 200);
            $campos[] = ['chave' => $chave, 'papel' => self::papel($chave, $def), 'alvo' => self::alvo($max), 'max' => $max];
        }
        return $campos;
    }

    private function descreverListas(array $doc, array $lib, array $listas): array
    {
        $itens = [];
        foreach ($listas as $nome => $l) {
            $campos = [];
            foreach ($l['campos'] as $campo => $def) {
                $max = (int) ($def['max'] ?? 160);
                $campos[$campo] = ['papel' => self::papel($nome . '.*.' . $campo, $def + ['lista' => $nome, 'campo' => $campo]), 'alvo' => self::alvo($max), 'max' => $max];
            }
            $atuais = [];
            foreach (Textos::itensLista($doc, $lib, $nome) as $id) {
                $t = Textos::textoEfetivo($doc, $lib, $nome . '.' . $id . '.' . array_key_first($l['campos']));
                if ($t !== '') {
                    $atuais[] = $t;
                }
            }
            $itens[] = ['lista' => $nome, 'item' => $l['rotulo'], 'minimo' => $l['min'], 'maximo' => $l['max'],
                'campos' => $campos, 'atuais' => array_slice($atuais, 0, 10)];
        }
        return $itens;
    }

    private function pedido(array $doc, array $lib, string $descricao, array $escalares, array $listas, bool $comSeo): string
    {
        $c = $this->contexto($doc, $lib);
        $pedido = [
            'negocio' => $this->negocio($c, $descricao),
            'campos' => $this->descreverCampos($escalares),
            'listas' => $this->descreverListas($doc, $lib, $listas),
        ];
        $texto = "Escreva os textos do site deste negócio. Devolva só o JSON pedido.\n"
            . "Em \"estrategia\", o raciocínio que guia os textos. Em \"textos\", uma entrada por campo de \"campos\", cumprindo o \"papel\" e perto do \"alvo\" de caracteres (nunca acima de \"max\").\n"
            . "Em \"listas\", para cada lista, entre \"minimo\" e \"maximo\" itens, cada campo cumprindo o papel e dentro do limite; "
            . "\"atuais\" mostra os itens de hoje (mantenha os que a descrição confirmar e acrescente os que ela citar).\n";
        if ($comSeo) {
            $texto .= "Em \"seo\": \"titulo\" com até 60 caracteres no formato \"Nome · Serviço principal em Cidade\" e "
                . "\"descricao\" com 140 a 155 caracteres, com a promessa e um convite. Em \"palavrasChave\", até 8 buscas locais que os textos atendem.\n";
        }
        return $texto . "\nDados:\n" . json_encode($pedido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** Ordem de leitura da página: a IA escreve na mesma ordem em que o visitante lê. */
    private const ORDEM_GRUPOS = ['hero', 'form', 'serv', 'dif', 'sobre', 'passos', 'equipe', 'dep', 'cli', 'faq', 'cta', 'contato', 'rodape'];

    public function esquema(array $escalares, array $listas, bool $comSeo, bool $comEstrategia = true): array
    {
        $chaves = array_keys($escalares);
        usort($chaves, static function (string $a, string $b): int {
            $pa = array_search(explode('.', $a)[0], self::ORDEM_GRUPOS, true);
            $pb = array_search(explode('.', $b)[0], self::ORDEM_GRUPOS, true);
            return [$pa === false ? 99 : $pa, $a] <=> [$pb === false ? 99 : $pb, $b];
        });
        $textos = [];
        foreach ($chaves as $chave) {
            $def = $escalares[$chave];
            $textos[$chave] = ['type' => 'STRING', 'description' => self::papel($chave, $def) . '; até ' . (int) ($def['max'] ?? 200) . ' caracteres'];
        }
        $props = [];
        $obrigatorios = [];
        if ($comEstrategia) {
            $props['estrategia'] = ['type' => 'OBJECT', 'properties' => [
                'publico' => ['type' => 'STRING', 'description' => 'para quem é o site, em uma frase'],
                'desejo' => ['type' => 'STRING', 'description' => 'o resultado que esse cliente quer'],
                'receio' => ['type' => 'STRING', 'description' => 'o que o faz hesitar antes de contratar'],
                'promessa' => ['type' => 'STRING', 'description' => 'a promessa central do site, em uma frase, só com fatos da descrição'],
                'provas' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'fatos concretos da descrição que sustentam a promessa'],
            ], 'required' => ['publico', 'desejo', 'receio', 'promessa', 'provas'],
                'propertyOrdering' => ['publico', 'desejo', 'receio', 'promessa', 'provas']];
            $obrigatorios[] = 'estrategia';
        }
        if ($textos !== []) {
            $props['textos'] = ['type' => 'OBJECT', 'properties' => $textos, 'required' => array_keys($textos), 'propertyOrdering' => array_keys($textos)];
            $obrigatorios[] = 'textos';
        }
        if ($listas !== []) {
            $ls = [];
            foreach ($listas as $nome => $l) {
                $campos = [];
                foreach ($l['campos'] as $campo => $def) {
                    $campos[$campo] = ['type' => 'STRING', 'description' => self::papel($nome . '.*.' . $campo, $def + ['lista' => $nome, 'campo' => $campo])
                        . '; até ' . (int) ($def['max'] ?? 160) . ' caracteres'];
                }
                $ls[$nome] = ['type' => 'ARRAY', 'minItems' => $l['min'], 'maxItems' => $l['max'],
                    'items' => ['type' => 'OBJECT', 'properties' => $campos, 'required' => array_keys($campos), 'propertyOrdering' => array_keys($campos)]];
            }
            $props['listas'] = ['type' => 'OBJECT', 'properties' => $ls, 'required' => array_keys($ls)];
            $obrigatorios[] = 'listas';
        }
        if ($comSeo) {
            $props['seo'] = ['type' => 'OBJECT', 'properties' => [
                'titulo' => ['type' => 'STRING', 'description' => 'até 60 caracteres'],
                'descricao' => ['type' => 'STRING', 'description' => '140 a 155 caracteres'],
            ], 'required' => ['titulo', 'descricao'], 'propertyOrdering' => ['titulo', 'descricao']];
            $props['palavrasChave'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
            array_push($obrigatorios, 'seo', 'palavrasChave');
        }
        return ['type' => 'OBJECT', 'properties' => $props, 'required' => $obrigatorios, 'propertyOrdering' => array_keys($props)];
    }

    /** Aplica todas as travas sobre a resposta da IA e monta o patch. */
    public function filtrar(array $doc, array $lib, array $resposta, array $escalares, array $listas, bool $comSeo): array
    {
        $c = $this->contexto($doc, $lib);
        $dados = ['nome' => $c['nome'], 'cidade' => $c['cidade']];
        $avisos = [];
        $patch = ['textos' => [], 'remover' => [], 'listas' => [], 'iconesRemover' => [], 'seo' => null, 'palavrasChave' => [], 'avisos' => []];
        $descartados = 0;

        $aceitar = function (mixed $valor, int $max, bool $longo) use ($c, &$descartados): ?string {
            $t = self::limpar($valor, $longo);
            if ($t === '') {
                return null;
            }
            if (self::termoProibido($t, $c['proibidos']) !== null) {
                $descartados++;
                return null;
            }
            return self::cortar($t, $max);
        };

        $textos = Texto::comoMapa($resposta['textos'] ?? []);
        foreach ($escalares as $chave => $def) {
            $t = $aceitar($textos[$chave] ?? null, (int) ($def['max'] ?? 200), ($def['tipo'] ?? '') === 'texto-longo');
            if ($t === null) {
                continue;
            }
            if (in_array($def['campo'] ?? '', ['titulo', 'eyebrow'], true)) {
                $t = self::semPontoFinal($t);
            }
            $this->gravarTexto($patch, $doc, $lib, $chave, Textos::retokenizar($t, $dados));
        }

        $respListas = Texto::comoMapa($resposta['listas'] ?? []);
        $usados = $this->idsEmUso($doc, $lib);
        foreach ($listas as $nome => $l) {
            $validos = [];
            foreach (Texto::comoLista($respListas[$nome] ?? []) as $item) {
                $item = Texto::comoMapa($item);
                $linha = [];
                foreach ($l['campos'] as $campo => $def) {
                    $t = $aceitar($item[$campo] ?? null, (int) ($def['max'] ?? 160), ($def['tipo'] ?? '') === 'texto-longo');
                    if ($t === null) {
                        continue 2;
                    }
                    if ($campo === 't') {
                        $t = self::semPontoFinal($t);
                    }
                    $linha[$campo] = Textos::retokenizar($t, $dados);
                }
                $validos[] = $linha;
                if (count($validos) >= $l['max']) {
                    break;
                }
            }
            if (count($validos) < $l['min']) {
                if ($validos !== [] || isset($respListas[$nome])) {
                    $avisos[] = 'A IA não escreveu itens suficientes para "' . $l['rotulo'] . '"; a lista ficou como estava.';
                }
                continue;
            }
            // Reaproveita os ids atuais na ordem (as fotos dos itens continuam); os ícones escolhidos à
            // mão são soltos para voltar ao automático pelo novo título; itens que sobrarem saem da lista.
            $atuais = Textos::itensLista($doc, $lib, $nome);
            $ids = [];
            foreach ($validos as $i => $linha) {
                $id = $atuais[$i] ?? Textos::novoIdItem($usados);
                $usados[] = $id;
                $ids[] = $id;
                foreach ($linha as $campo => $t) {
                    $this->gravarTexto($patch, $doc, $lib, $nome . '.' . $id . '.' . $campo, $t);
                }
                if (isset($doc['icones'][$nome . '.' . $id])) {
                    $patch['iconesRemover'][] = $nome . '.' . $id;
                }
            }
            foreach (array_slice($atuais, count($ids)) as $sobra) {
                foreach (array_keys(Texto::comoMapa($doc['textos'] ?? [])) as $k) {
                    if (str_starts_with((string) $k, $nome . '.' . $sobra . '.')) {
                        $patch['remover'][] = (string) $k;
                    }
                }
            }
            $patch['listas'][$nome] = $ids;
        }

        if ($comSeo) {
            $seo = Texto::comoMapa($resposta['seo'] ?? []);
            $titulo = $aceitar($seo['titulo'] ?? null, self::MAX_SEO_TITULO, false);
            $desc = $aceitar($seo['descricao'] ?? null, self::MAX_SEO_DESCRICAO, false);
            if ($titulo !== null && $desc !== null) {
                $patch['seo'] = ['titulo' => $titulo, 'descricao' => $desc];
            }
            foreach (Texto::comoLista($resposta['palavrasChave'] ?? []) as $p) {
                $p = self::limpar($p, false);
                if ($p !== '' && mb_strlen($p, 'UTF-8') <= 80 && count($patch['palavrasChave']) < 8) {
                    $patch['palavrasChave'][] = $p;
                }
            }
        }

        if ($descartados > 0) {
            $avisos[] = $descartados === 1
                ? 'Um texto sugerido pela IA usava um termo proibido para a sua profissão e foi descartado.'
                : $descartados . ' textos sugeridos pela IA usavam termos proibidos para a sua profissão e foram descartados.';
        }
        if ($patch['textos'] === [] && $patch['listas'] === [] && $patch['seo'] === null) {
            throw new ErroIa('A IA não devolveu nenhum texto aproveitável. Tente detalhar mais a descrição.', true);
        }
        $patch['remover'] = array_values(array_unique($patch['remover']));
        $patch['avisos'] = $avisos;
        return $patch;
    }

    /** Texto igual ao padrão sai de `textos` (volta a propagar); senão entra. */
    private function gravarTexto(array &$patch, array $doc, array $lib, string $chave, string $texto): void
    {
        if ($texto === Textos::textoPadrao($doc, $lib, $chave)) {
            if (array_key_exists($chave, Texto::comoMapa($doc['textos'] ?? []))) {
                $patch['remover'][] = $chave;
            }
            return;
        }
        $patch['textos'][$chave] = $texto;
    }

    /** @return list<string> ids de itens já usados em qualquer lista (para não repetir). */
    private function idsEmUso(array $doc, array $lib): array
    {
        $ids = [];
        foreach (array_keys(Documento::registroListas($lib)) as $lista) {
            array_push($ids, ...Textos::itensLista($doc, $lib, (string) $lista));
        }
        return $ids;
    }

    /** Texto puro numa linha (ou parágrafos simples no texto longo): sem markdown, aspas de moldura nem controles. */
    public static function limpar(mixed $v, bool $longo): string
    {
        if (!is_string($v)) {
            return '';
        }
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $t = preg_replace('/\*\*|__|`|^#+\s*/mu', '', $t) ?? '';
        $t = preg_replace('/\s*\R\s*/u', ' ', $t) ?? '';
        $t = Texto::colapsarEspacos(trim($t));
        if (preg_match('/^["“”\'«](.*)["“”\'»]$/u', $t, $m)) {
            $t = trim($m[1]);
        }
        return $t;
    }

    /** Título não leva ponto final (reticências e interrogação ficam). */
    public static function semPontoFinal(string $t): string
    {
        return preg_match('/[^.]\.$/u', $t) ? mb_substr($t, 0, -1, 'UTF-8') : $t;
    }

    /**
     * Corta no limite: de preferência no fim da última frase completa (se sobrar mais da metade
     * do texto); senão sem quebrar palavra e sem terminar em vírgula ou conectivo solto.
     */
    public static function cortar(string $t, int $max): string
    {
        if (mb_strlen($t, 'UTF-8') <= $max) {
            return $t;
        }
        if (preg_match('/^.*[.!?…](?=\s)/su', mb_substr($t, 0, $max + 1, 'UTF-8'), $m) && mb_strlen($m[0], 'UTF-8') >= $max * 0.5) {
            return $m[0];
        }
        $corte = mb_substr($t, 0, $max + 1, 'UTF-8');
        $espaco = mb_strrpos($corte, ' ', 0, 'UTF-8');
        $corte = $espaco !== false && $espaco > $max * 0.6 ? mb_substr($corte, 0, $espaco, 'UTF-8') : mb_substr($t, 0, $max, 'UTF-8');
        $corte = preg_replace('/(?:[\s,;:\-–—]+|\s+(?:e|ou|de|da|do|das|dos|com|para|em|a|o|que))+$/u', '', rtrim($corte)) ?? $corte;
        return rtrim($corte);
    }

    /** Primeiro termo proibido presente (comparação sem acento e por palavra inteira), ou null. */
    public static function termoProibido(string $texto, array $proibidos): ?string
    {
        $norm = ' ' . preg_replace('/[^a-z0-9%]+/', ' ', Texto::normalizar($texto)) . ' ';
        foreach ($proibidos as $termo) {
            $t = trim((string) preg_replace('/[^a-z0-9%]+/', ' ', Texto::normalizar((string) $termo)));
            if ($t !== '' && str_contains($norm, ' ' . $t . ' ')) {
                return (string) $termo;
            }
        }
        return null;
    }
}
