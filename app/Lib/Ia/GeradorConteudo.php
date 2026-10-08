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

    public function __construct(private readonly Provedor $provedor)
    {
    }

    /**
     * @param array  $doc       documento do site (versaoEsquema 2)
     * @param array  $lib       bundle da biblioteca
     * @param string $descricao o que a pessoa escreveu sobre o negócio
     * @param string $escopo    "site" ou um tipo de seção ("servicos", "faq"…)
     * @return array{textos: array<string,string>, remover: list<string>, listas: array<string,list<string>>,
     *               iconesRemover: list<string>, seo: ?array{titulo: string, descricao: string},
     *               palavrasChave: list<string>, avisos: list<string>}
     * @throws ErroIa
     */
    public function gerar(array $doc, array $lib, string $descricao, string $escopo = 'site'): array
    {
        $descricao = Texto::colapsarEspacos(mb_substr(trim($descricao), 0, self::MAX_DESCRICAO, 'UTF-8'));
        [$escalares, $listas] = $this->camposDoEscopo($lib, $escopo);
        if ($escalares === [] && $listas === []) {
            throw new ErroIa('Esta seção não tem textos que a IA possa escrever.');
        }
        $comSeo = $escopo === 'site';
        $resposta = $this->provedor->gerarJson(
            $this->instrucoes($doc, $lib),
            $this->pedido($doc, $lib, $descricao, $escalares, $listas, $comSeo),
            $this->esquema($escalares, $listas, $comSeo),
        );
        return $this->filtrar($doc, $lib, $resposta, $escalares, $listas, $comSeo);
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
        return [
            'nome' => (string) ($vars['nome'] ?? ''),
            'cidade' => (string) ($vars['cidade'] ?? ''),
            'uf' => (string) ($doc['dados']['uf'] ?? ''),
            'nicho' => (string) ($nicho['nome'] ?? ''),
            'segmento' => (string) ($esp['segmento'] ?? ($vars['segmento'] ?? '')),
            'aviso' => (string) ($conf['aviso'] ?? ''),
            'proibidos' => array_values(array_filter(array_map('strval', (array) ($conf['termosProibidos'] ?? [])))),
        ];
    }

    public function instrucoes(array $doc, array $lib): string
    {
        $c = $this->contexto($doc, $lib);
        $regras = [
            'Você é redator de sites para pequenos negócios brasileiros e especialista em SEO local.',
            'Escreva em português do Brasil, com tom profissional, claro e acolhedor. Frases curtas e diretas.',
            'Use SOMENTE fatos da descrição do negócio e dos dados informados. NUNCA invente números, anos de experiência, quantidade de clientes ou atendimentos, prêmios, certificações, depoimentos, nomes de pessoas, preços, descontos ou promoções.',
            'Respeite o limite de caracteres de cada campo (contando espaços). Prefira ficar bem abaixo do limite.',
            'Escreva texto puro: sem markdown, sem emojis, sem aspas em volta, sem quebras de linha.',
            'SEO local: use naturalmente o serviço principal junto com a cidade no título do destaque, no texto do destaque, no título dos serviços e na descrição para o Google. Não repita palavras-chave de forma artificial.',
            'Perguntas frequentes: dúvidas reais que um cliente teria antes de contratar, com respostas objetivas e verdadeiras (sem prometer resultado).',
            'Rótulos acima do título (campos "eyebrow") são curtos, de 2 a 5 palavras.',
            'O campo hero.selo é um diferencial factual curto citado na descrição (ex.: "Atendimento aos sábados"). Se a descrição não trouxer nenhum, devolva texto vazio.',
        ];
        if ($c['aviso'] !== '') {
            $regras[] = 'Regras de publicidade da profissão (obrigatórias): ' . $c['aviso'];
        }
        if ($c['proibidos'] !== []) {
            $regras[] = 'Nunca use estes termos: ' . implode(', ', $c['proibidos']) . '.';
        }
        return implode("\n", array_map(static fn (string $r): string => '- ' . $r, $regras));
    }

    private function pedido(array $doc, array $lib, string $descricao, array $escalares, array $listas, bool $comSeo): string
    {
        $c = $this->contexto($doc, $lib);
        $campos = [];
        foreach ($escalares as $chave => $def) {
            $campos[] = ['chave' => $chave, 'max' => (int) ($def['max'] ?? 200), 'sobre' => (string) ($def['rotulo'] ?? $chave)];
        }
        $itens = [];
        foreach ($listas as $nome => $l) {
            $limites = [];
            foreach ($l['campos'] as $campo => $def) {
                $limites[$campo] = (int) ($def['max'] ?? 160);
            }
            $atuais = [];
            foreach (Textos::itensLista($doc, $lib, $nome) as $id) {
                $t = Textos::textoEfetivo($doc, $lib, $nome . '.' . $id . '.' . array_key_first($l['campos']));
                if ($t !== '') {
                    $atuais[] = $t;
                }
            }
            $itens[] = ['lista' => $nome, 'item' => $l['rotulo'], 'minimo' => $l['min'], 'maximo' => $l['max'],
                'limites' => $limites, 'atuais' => array_slice($atuais, 0, 10)];
        }
        $pedido = [
            'negocio' => [
                'nome' => $c['nome'],
                'cidade' => trim($c['cidade'] . ($c['uf'] !== '' ? ' - ' . $c['uf'] : '')),
                'ramo' => $c['nicho'],
                'especialidade' => $c['segmento'],
                'descricao' => $descricao,
            ],
            'campos' => $campos,
            'listas' => $itens,
        ];
        $texto = "Escreva os textos do site deste negócio. Devolva só o JSON pedido.\n"
            . "Em \"textos\", uma entrada por campo de \"campos\" (respeitando \"max\" caracteres).\n"
            . "Em \"listas\", para cada lista, entre \"minimo\" e \"maximo\" itens, cada campo dentro do limite; "
            . "\"atuais\" mostra os itens de hoje (mantenha os que a descrição confirmar e acrescente os que ela citar).\n";
        if ($comSeo) {
            $texto .= "Em \"seo\": \"titulo\" com até 60 caracteres no formato \"Nome · Serviço principal em Cidade\" e "
                . "\"descricao\" com 140 a 155 caracteres. Em \"palavrasChave\", até 8 buscas locais que os textos atendem.\n";
        }
        return $texto . "\nDados:\n" . json_encode($pedido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function esquema(array $escalares, array $listas, bool $comSeo): array
    {
        $textos = [];
        foreach ($escalares as $chave => $def) {
            $textos[$chave] = ['type' => 'STRING', 'description' => (string) ($def['rotulo'] ?? $chave) . '; até ' . (int) ($def['max'] ?? 200) . ' caracteres'];
        }
        $props = [];
        $obrigatorios = [];
        if ($textos !== []) {
            $props['textos'] = ['type' => 'OBJECT', 'properties' => $textos, 'required' => array_keys($textos)];
            $obrigatorios[] = 'textos';
        }
        if ($listas !== []) {
            $ls = [];
            foreach ($listas as $nome => $l) {
                $campos = [];
                foreach ($l['campos'] as $campo => $def) {
                    $campos[$campo] = ['type' => 'STRING', 'description' => (string) ($def['rotulo'] ?? $l['rotulo']) . '; até ' . (int) ($def['max'] ?? 160) . ' caracteres'];
                }
                $ls[$nome] = ['type' => 'ARRAY', 'minItems' => $l['min'], 'maxItems' => $l['max'],
                    'items' => ['type' => 'OBJECT', 'properties' => $campos, 'required' => array_keys($campos)]];
            }
            $props['listas'] = ['type' => 'OBJECT', 'properties' => $ls, 'required' => array_keys($ls)];
            $obrigatorios[] = 'listas';
        }
        if ($comSeo) {
            $props['seo'] = ['type' => 'OBJECT', 'properties' => [
                'titulo' => ['type' => 'STRING', 'description' => 'até 60 caracteres'],
                'descricao' => ['type' => 'STRING', 'description' => '140 a 155 caracteres'],
            ], 'required' => ['titulo', 'descricao']];
            $props['palavrasChave'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
            array_push($obrigatorios, 'seo', 'palavrasChave');
        }
        return ['type' => 'OBJECT', 'properties' => $props, 'required' => $obrigatorios];
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

    /** Corta no limite sem quebrar palavra e sem terminar em vírgula ou conectivo solto. */
    public static function cortar(string $t, int $max): string
    {
        if (mb_strlen($t, 'UTF-8') <= $max) {
            return $t;
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
