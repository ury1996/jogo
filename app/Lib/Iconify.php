<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Ícones do Iconify (https://iconify.design): busca em coleções abertas e entrega o desenho já
 * pronto para guardar no documento do site (doc.iconesExtras). O site publicado nunca depende do
 * Iconify: o SVG fica dentro do HTML, como os ícones da biblioteca.
 *
 * - Só coleções monocromáticas com licença que dispensa crédito visível (MIT, ISC, Apache 2.0,
 *   CC0). Coleções animadas ou coloridas ficam de fora (a cor do ícone segue a do site).
 * - O SVG é montado aqui a partir do "body" do Iconify com uma lista fechada de elementos e
 *   atributos de desenho (path, g, circle…; d, fill, stroke…), sem nada que execute código ou
 *   busque recursos. svgValido() confere o mesmo formato quando o documento é salvo, então um
 *   documento adulterado não consegue enfiar outra coisa no site.
 * - Phosphor vem nos três pesos da biblioteca (light → fino, duotone, fill → preenchido) e segue
 *   o acabamento do site; as outras coleções têm um desenho só.
 * - A busca do Iconify é em inglês: o editor manda "dicas" (nomes em inglês dos ícones da
 *   biblioteca que casaram com o termo) e, havendo IA ligada, o termo é traduzido uma vez e fica
 *   em cache. Respostas guardadas por 7 dias em var/cache/iconify.
 */
final class Iconify
{
    public const API_PADRAO = 'https://api.iconify.design';
    public const MAX_TERMO = 60;
    public const MAX_DICAS = 3;
    public const MAX_RESULTADOS = 60;
    public const MAX_SVG_BYTES = 16384;
    public const CACHE_SEGUNDOS = 7 * 86400;
    /** Id de ícone do Iconify ("prefixo:nome"); o mesmo formato vale em doc.icones e doc.iconesExtras. */
    public const RE_ID = '/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** Coleções aceitas (prefixo → nome mostrado no editor). Todas MIT, ISC, Apache 2.0 ou CC0. */
    public const COLECOES = [
        'ph' => 'Phosphor',
        'material-symbols' => 'Material Symbols',
        'mdi' => 'Material Design',
        'tabler' => 'Tabler',
        'lucide' => 'Lucide',
        'hugeicons' => 'Huge Icons',
        'healthicons' => 'Health Icons',
        'mingcute' => 'MingCute',
        'ri' => 'Remix',
        'iconoir' => 'Iconoir',
        'carbon' => 'Carbon',
        'fluent' => 'Fluent',
        'heroicons' => 'Heroicons',
        'bi' => 'Bootstrap',
        'uil' => 'Unicons',
        'majesticons' => 'Majesticons',
        'akar-icons' => 'Akar',
        'ion' => 'Ionicons',
        'icon-park-outline' => 'IconPark',
        'medical-icon' => 'Medical Icons',
        'maki' => 'Maki',
    ];
    private const LICENCAS = ['MIT', 'ISC', 'Apache-2.0', 'CC0-1.0'];

    private const PESOS_PHOSPHOR = ['fino' => 'light', 'duotone' => 'duotone', 'preenchido' => 'fill'];
    private const RE_VARIANTE_PHOSPHOR = '/-(thin|light|bold|fill|duotone)$/D';

    /** Elementos e atributos de desenho aceitos (o resto recusa o ícone inteiro). */
    private const ELEMENTOS = ['g', 'path', 'circle', 'rect', 'ellipse', 'line', 'polyline', 'polygon'];
    private const ATRIBUTOS = [
        'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'fill-opacity', 'stroke-opacity', 'fill-rule',
        'clip-rule', 'transform', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'width', 'height', 'x1', 'y1', 'x2', 'y2',
        'points', 'vector-effect',
    ];
    /** Valores: números, comandos de caminho, cores (#hex, currentColor, none), transform(…). */
    private const RE_VALOR = '/^[A-Za-z0-9 .,#%()+\-]*$/D';
    private const RE_RAIZ = '#^<svg viewBox="(-?\d+(?:\.\d+)? -?\d+(?:\.\d+)? \d+(?:\.\d+)? \d+(?:\.\d+)?)" fill="currentColor" aria-hidden="true" focusable="false">(.*)</svg>$#Ds';

    /**
     * @var callable|null fn(string $url, int $tempo, int $maxBytes): array{0:int,1:string}
     *   [status HTTP (0 = falha de rede), corpo]. Usado nos testes.
     */
    private $transporte;
    /** @var callable|null fn(string $termo): list<string> tradução para o inglês (IA) */
    private $tradutor;

    /** @param list<string> $colecoes prefixos ligados (subconjunto de COLECOES; vazio = todos) */
    public function __construct(
        private readonly string $api = self::API_PADRAO,
        private readonly ?string $pastaCache = null,
        private readonly array $colecoes = [],
        private readonly int $tempoLimite = 12,
        ?callable $transporte = null,
        ?callable $tradutor = null,
    ) {
        $this->transporte = $transporte;
        $this->tradutor = $tradutor;
    }

    /** Prefixos em uso (os pedidos na configuração que estão na lista aceita). */
    public function prefixos(): array
    {
        $pedidos = array_values(array_filter($this->colecoes, static fn ($p): bool => is_string($p) && isset(self::COLECOES[$p])));
        return $pedidos !== [] ? $pedidos : array_keys(self::COLECOES);
    }

    public static function idValido(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 100 && preg_match(self::RE_ID, $id) === 1;
    }

    /** Termo de busca: minúsculas, só letras, números, espaço e hífen, até 60 caracteres. */
    public static function limparTermo(string $termo): string
    {
        $t = mb_strtolower(trim($termo), 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N} \-]+/u', ' ', $t) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_substr($t, 0, self::MAX_TERMO, 'UTF-8');
    }

    /**
     * Busca ícones. $dicas: palavras em inglês que ajudam (nomes de ícones da biblioteca).
     * → {icones: [{id, nome, colecao, svg: {fino?, duotone?, preenchido?}}], colecoes: {prefixo: nome}}
     *
     * @param list<string> $dicas
     * @throws ErroBancoImagens com status e mensagem para o usuário
     */
    public function buscar(string $termo, array $dicas = []): array
    {
        $termo = self::limparTermo($termo);
        if ($termo === '') {
            throw new ErroBancoImagens('Digite o que você procura (ex.: "dente", "balança", "casa").', 422);
        }
        $consultas = [$termo];
        foreach ($dicas as $d) {
            $d = is_string($d) ? self::limparTermo(str_replace('-', ' ', $d)) : '';
            if ($d !== '' && !in_array($d, $consultas, true) && count($consultas) <= self::MAX_DICAS) {
                $consultas[] = $d;
            }
        }
        $chave = ['v' => 2, 'consultas' => $consultas, 'colecoes' => $this->prefixos()];
        $cache = $this->lerCache('busca-' . sha1((string) json_encode($chave)));
        if ($cache !== null) {
            return $cache;
        }
        $ids = $this->procurar($consultas);
        // Poucos resultados (em geral, termo em português): a IA traduz uma vez e soma a busca.
        if ($this->tradutor !== null && count($ids) < 24) {
            $traducao = $this->traduzir($termo);
            if ($traducao !== []) {
                $ids = array_values(array_unique([...$ids, ...$this->procurar($traducao)]));
            }
        }
        $resultado = ['icones' => $this->carregar(array_slice($ids, 0, self::MAX_RESULTADOS)), 'colecoes' => []];
        foreach ($resultado['icones'] as $i) {
            $resultado['colecoes'][$i['colecao']] = self::COLECOES[$i['colecao']] ?? $i['colecao'];
        }
        $this->gravarCache('busca-' . sha1((string) json_encode($chave)), $resultado);
        return $resultado;
    }

    /**
     * Ids encontrados (na ordem do Iconify, sem repetir; Phosphor sem o peso no nome).
     *
     * @param list<string> $consultas
     * @return list<string>
     */
    private function procurar(array $consultas): array
    {
        $prefixos = $this->prefixos();
        $urls = [];
        foreach ($consultas as $c) {
            $urls[] = rtrim($this->api, '/') . '/search?' . http_build_query(
                ['query' => $c, 'limit' => 64, 'prefixes' => implode(',', $prefixos)], '', '&', PHP_QUERY_RFC3986,
            );
        }
        $ids = [];
        foreach ($this->pedirVarios($urls) as $n => [$status, $corpo]) {
            $palavras = explode(' ', str_replace('-', ' ', $consultas[$n]));
            if ($status !== 200) {
                throw self::erroHttp($status);
            }
            $json = json_decode($corpo, true);
            if (!is_array($json) || !is_array($json['icons'] ?? null)) {
                throw new ErroBancoImagens('O Iconify devolveu uma resposta inesperada. Tente de novo.', 502);
            }
            $licencas = [];
            foreach ((array) ($json['collections'] ?? []) as $p => $info) {
                $licencas[(string) $p] = is_array($info) ? (string) ($info['license']['spdx'] ?? '') : '';
            }
            foreach ($json['icons'] as $id) {
                if (!self::idValido($id)) {
                    continue;
                }
                [$prefixo, $nome] = explode(':', $id, 2);
                if (!in_array($prefixo, $prefixos, true) || (isset($licencas[$prefixo]) && !in_array($licencas[$prefixo], self::LICENCAS, true))) {
                    continue;
                }
                if ($prefixo === 'ph') {
                    $nome = preg_replace(self::RE_VARIANTE_PHOSPHOR, '', $nome) ?? $nome;
                }
                $chave = $prefixo . ':' . $nome;
                $ids[$chave] = min($ids[$chave] ?? 9, self::relevancia($nome, $palavras));
            }
        }
        // Mais relevantes primeiro (ordem do Iconify no empate). O termo só no meio de outra
        // palavra ("bluetooth" para "tooth") sai quando há resultados bons o bastante.
        $bons = count(array_filter($ids, static fn (int $r): bool => $r < 2));
        $posicao = array_flip(array_keys($ids));
        $ordem = array_keys($ids);
        usort($ordem, static fn (string $a, string $b): int => [$ids[$a], $posicao[$a]] <=> [$ids[$b], $posicao[$b]]);
        return array_values(array_filter($ordem, static fn (string $id): bool => $ids[$id] < 2 || $bons < 8));
    }

    /**
     * 0 = o nome começa pelas palavras buscadas ("tooth", "tooth-outline"); 1 = todas aparecem
     * como palavras do nome ("cog-6-tooth"); 2 = só dentro de outra palavra ("bluetooth").
     *
     * @param list<string> $palavras
     */
    private static function relevancia(string $nome, array $palavras): int
    {
        $partes = explode('-', $nome);
        $palavras = array_values(array_filter($palavras, static fn (string $p): bool => $p !== ''));
        if ($palavras === [] || array_diff($palavras, $partes) !== []) {
            return 2;
        }
        return array_slice($partes, 0, count($palavras)) === $palavras ? 0 : 1;
    }

    /**
     * Desenhos dos ids (um pedido por coleção, em paralelo). Ícone que não passa no filtro some.
     *
     * @param list<string> $ids
     * @return list<array{id: string, nome: string, colecao: string, svg: array<string, string>}>
     */
    private function carregar(array $ids): array
    {
        $porColecao = [];
        foreach ($ids as $id) {
            [$prefixo, $nome] = explode(':', $id, 2);
            $nomes = $prefixo === 'ph' ? array_map(static fn (string $p): string => "{$nome}-{$p}", self::PESOS_PHOSPHOR) : [$nome];
            foreach ($nomes as $n) {
                $porColecao[$prefixo][$n] = true;
            }
        }
        $urls = [];
        foreach ($porColecao as $prefixo => $nomes) {
            $urls[$prefixo] = rtrim($this->api, '/') . '/' . rawurlencode((string) $prefixo) . '.json?icons=' . implode(',', array_map('rawurlencode', array_keys($nomes)));
        }
        $dados = [];
        $respostas = $this->pedirVarios(array_values($urls));
        foreach (array_keys($urls) as $i => $prefixo) {
            [$status, $corpo] = $respostas[$i];
            if ($status !== 200) {
                throw self::erroHttp($status);
            }
            $json = json_decode($corpo, true);
            $dados[$prefixo] = is_array($json) ? $json : [];
        }
        $r = [];
        foreach ($ids as $id) {
            [$prefixo, $nome] = explode(':', $id, 2);
            $json = $dados[$prefixo] ?? [];
            $svg = [];
            if ($prefixo === 'ph') {
                foreach (self::PESOS_PHOSPHOR as $peso => $variante) {
                    $s = self::svgDoJson($json, "{$nome}-{$variante}");
                    if ($s !== null) {
                        $svg[$peso] = $s;
                    }
                }
            } else {
                $s = self::svgDoJson($json, $nome);
                if ($s !== null) {
                    $svg['fino'] = $s;
                }
            }
            if ($svg !== []) {
                $r[] = ['id' => $id, 'nome' => str_replace('-', ' ', $nome), 'colecao' => $prefixo, 'svg' => $svg];
            }
        }
        return $r;
    }

    /** SVG de um ícone do JSON de uma coleção do Iconify (segue um apelido simples, sem transformação). */
    private static function svgDoJson(array $json, string $nome): ?string
    {
        $icones = is_array($json['icons'] ?? null) ? $json['icons'] : [];
        $icone = $icones[$nome] ?? null;
        if (!is_array($icone)) {
            $apelido = $json['aliases'][$nome] ?? null;
            $pai = is_array($apelido) ? ($apelido['parent'] ?? null) : null;
            $soPai = is_array($apelido) && array_diff(array_keys($apelido), ['parent']) === [];
            $icone = $soPai && is_string($pai) && is_array($icones[$pai] ?? null) ? $icones[$pai] : null;
        }
        if (!is_array($icone) || !is_string($icone['body'] ?? null) || isset($icone['rotate']) || !empty($icone['hFlip']) || !empty($icone['vFlip'])) {
            return null;
        }
        $num = static fn (mixed $v, float $padrao): float => is_int($v) || is_float($v) ? (float) $v : $padrao;
        $largura = $num($icone['width'] ?? $json['width'] ?? null, 16.0);
        $altura = $num($icone['height'] ?? $json['height'] ?? null, 16.0);
        $esquerda = $num($icone['left'] ?? $json['left'] ?? null, 0.0);
        $topo = $num($icone['top'] ?? $json['top'] ?? null, 0.0);
        return self::montarSvg($icone['body'], $esquerda, $topo, $largura, $altura);
    }

    /** SVG final no formato da biblioteca (sem tamanho, cor do texto, escondido do leitor de tela). */
    public static function montarSvg(string $corpo, float $esquerda, float $topo, float $largura, float $altura): ?string
    {
        if ($largura <= 0 || $altura <= 0 || $largura > 10000 || $altura > 10000) {
            return null;
        }
        $limpo = self::corpoCanonico($corpo);
        if ($limpo === null || $limpo === '') {
            return null;
        }
        $n = static fn (float $x): string => rtrim(rtrim(number_format($x, 3, '.', ''), '0'), '.') ?: '0';
        $svg = '<svg viewBox="' . $n($esquerda) . ' ' . $n($topo) . ' ' . $n($largura) . ' ' . $n($altura)
            . '" fill="currentColor" aria-hidden="true" focusable="false">' . $limpo . '</svg>';
        return strlen($svg) <= self::MAX_SVG_BYTES ? $svg : null;
    }

    /** O SVG está exatamente no formato que montarSvg() produz (o que vale para doc.iconesExtras)? */
    public static function svgValido(mixed $svg): bool
    {
        if (!is_string($svg) || $svg === '' || strlen($svg) > self::MAX_SVG_BYTES || !preg_match(self::RE_RAIZ, $svg, $m)) {
            return false;
        }
        return $m[2] !== '' && self::corpoCanonico($m[2]) === $m[2];
    }

    /**
     * Corpo do ícone refeito só com os elementos e atributos aceitos, ou null se houver qualquer
     * outra coisa (texto, script, estilo, referência, imagem, animação…).
     */
    private static function corpoCanonico(string $corpo): ?string
    {
        if (preg_match('/<!|<\?/', $corpo)) {
            return null; // DOCTYPE, ENTITY, CDATA, comentário, instrução de processamento
        }
        $dom = new \DOMDocument();
        $antes = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadXML('<svg xmlns="http://www.w3.org/2000/svg">' . $corpo . '</svg>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($antes);
        }
        if (!$ok || $dom->documentElement === null) {
            return null;
        }
        return self::serializarFilhos($dom->documentElement, 0);
    }

    private static function serializarFilhos(\DOMNode $no, int $profundidade): ?string
    {
        if ($profundidade > 8) {
            return null;
        }
        $saida = '';
        foreach ($no->childNodes as $filho) {
            if ($filho instanceof \DOMText) {
                if (trim($filho->data) !== '') {
                    return null;
                }
                continue;
            }
            if (!$filho instanceof \DOMElement || $filho->namespaceURI !== 'http://www.w3.org/2000/svg'
                || !in_array($filho->localName, self::ELEMENTOS, true)) {
                return null;
            }
            $attrs = '';
            foreach ($filho->attributes ?? [] as $a) {
                /** @var \DOMAttr $a */
                $nome = $a->nodeName;
                $valor = (string) $a->value;
                if ($a->namespaceURI !== null || !in_array($nome, self::ATRIBUTOS, true)
                    || !preg_match(self::RE_VALOR, $valor) || stripos($valor, 'url') !== false || strlen($valor) > 8000) {
                    return null;
                }
                $attrs .= ' ' . $nome . '="' . $valor . '"';
            }
            $dentro = self::serializarFilhos($filho, $profundidade + 1);
            if ($dentro === null) {
                return null;
            }
            $saida .= $dentro === '' ? "<{$filho->localName}{$attrs}/>" : "<{$filho->localName}{$attrs}>{$dentro}</{$filho->localName}>";
        }
        return $saida;
    }

    /** Termo em português → até 3 palavras-chave em inglês (pela IA, com cache sem prazo). */
    private function traduzir(string $termo): array
    {
        $arquivo = 'traducao-' . sha1($termo);
        $cache = $this->lerCache($arquivo, PHP_INT_MAX);
        if ($cache !== null) {
            return array_values(array_filter($cache['termos'] ?? [], 'is_string'));
        }
        try {
            $termos = ($this->tradutor)($termo);
        } catch (\Throwable) {
            return [];
        }
        $limpos = [];
        foreach (is_array($termos) ? $termos : [] as $t) {
            $t = is_string($t) ? self::limparTermo($t) : '';
            if ($t !== '' && preg_match('/^[a-z0-9 \-]+$/D', $t) && !in_array($t, $limpos, true) && count($limpos) < 3) {
                $limpos[] = $t;
            }
        }
        $this->gravarCache($arquivo, ['termos' => $limpos]);
        return $limpos;
    }

    private function lerCache(string $nome, int $validade = self::CACHE_SEGUNDOS): ?array
    {
        if ($this->pastaCache === null) {
            return null;
        }
        $arquivo = rtrim($this->pastaCache, '/') . '/' . $nome . '.json';
        if (!is_file($arquivo) || time() - (int) filemtime($arquivo) >= $validade) {
            return null;
        }
        $json = json_decode((string) file_get_contents($arquivo), true);
        return is_array($json) ? $json : null;
    }

    private function gravarCache(string $nome, array $dados): void
    {
        if ($this->pastaCache === null || (!is_dir($this->pastaCache) && !@mkdir($this->pastaCache, 0775, true))) {
            return;
        }
        @file_put_contents(rtrim($this->pastaCache, '/') . '/' . $nome . '.json', json_encode($dados, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** Apaga do cache as buscas com mais de 7 dias (limpeza diária). Devolve quantas. */
    public static function limparCache(string $pasta): int
    {
        $n = 0;
        foreach (glob(rtrim($pasta, '/') . '/busca-*.json') ?: [] as $arquivo) {
            if (time() - (int) @filemtime($arquivo) >= self::CACHE_SEGUNDOS && @unlink($arquivo)) {
                $n++;
            }
        }
        return $n;
    }

    private static function erroHttp(int $status): ErroBancoImagens
    {
        return match (true) {
            $status <= 0 => new ErroBancoImagens('O Iconify não respondeu. Confira a internet do servidor e tente de novo em instantes.', 503),
            $status === 429 => new ErroBancoImagens('Muitas buscas de ícones agora. Tente de novo em um minuto.', 429),
            $status >= 500 => new ErroBancoImagens('O Iconify está fora do ar no momento. Tente de novo em instantes.', 503),
            default => new ErroBancoImagens('O Iconify recusou a busca (erro ' . $status . ').', 502),
        };
    }

    /**
     * GETs (em paralelo quando não há transporte de teste).
     *
     * @param list<string> $urls
     * @return list<array{0: int, 1: string}>
     */
    private function pedirVarios(array $urls): array
    {
        $max = 4 * 1024 * 1024;
        if ($this->transporte !== null) {
            return array_map(function (string $u) use ($max): array {
                $r = ($this->transporte)($u, $this->tempoLimite, $max);
                return [(int) ($r[0] ?? 0), (string) ($r[1] ?? '')];
            }, $urls);
        }
        $multi = curl_multi_init();
        $handles = [];
        $corpos = [];
        foreach ($urls as $i => $url) {
            $corpos[$i] = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $this->tempoLimite,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'ConstrutorRankly/1.0',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $parte) use (&$corpos, $i, $max): int {
                    if (strlen($corpos[$i]) + strlen($parte) > $max) {
                        return 0;
                    }
                    $corpos[$i] .= $parte;
                    return strlen($parte);
                },
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $estado = curl_multi_exec($multi, $ativos);
            if ($ativos > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($ativos > 0 && $estado === CURLM_OK);
        $r = [];
        foreach ($handles as $i => $ch) {
            $erro = curl_errno($ch) !== 0;
            $r[$i] = [$erro ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $erro ? '' : $corpos[$i]];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $r;
    }
}
