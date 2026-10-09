<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Banco de imagens Pixabay (https://pixabay.com/api/docs/): busca de fotos e download para
 * dentro do sistema. Regras do Pixabay que seguimos:
 * - as URLs devolvidas servem só para mostrar o resultado da busca na hora; a foto escolhida é
 *   baixada para o nosso servidor (nada de link permanente para o Pixabay);
 * - respostas guardadas em cache por 24 h (mesmo termo, página e orientação não vão de novo);
 * - mostrar de onde vêm as imagens ("Imagens do Pixabay") sempre que houver resultados.
 * Segurança: download só de https://pixabay.com e https://cdn.pixabay.com, sem redirecionamentos
 * (o servidor nunca busca endereços internos), com limite de tamanho e de tempo. A chave vai na
 * URL (é como a API funciona): essas URLs nunca são registradas em log.
 */
final class Pixabay
{
    private const API = 'https://pixabay.com/api/';
    public const HOSTS_IMAGENS = ['pixabay.com', 'cdn.pixabay.com'];
    public const POR_PAGINA = 24;
    public const MAX_PAGINA = 20;
    public const MAX_TERMO = 100;
    /** Validade do cache das respostas (exigência do Pixabay: 24 horas). */
    public const CACHE_SEGUNDOS = 86400;

    private const ORIENTACOES = [
        'paisagem' => 'horizontal', 'horizontal' => 'horizontal', 'landscape' => 'horizontal',
        'retrato' => 'vertical', 'vertical' => 'vertical', 'portrait' => 'vertical',
    ];

    /**
     * @var callable|null fn(string $url, array $cabecalhos, int $tempo, int $maxBytes): array{0:int,1:string,2?:string}
     *   [status HTTP (0 = falha de rede), corpo, Content-Type]. Usado nos testes.
     */
    private $transporte;

    public function __construct(
        private readonly string $chave,
        private readonly ?string $pastaCache = null,
        private readonly int $tempoLimite = 15,
        ?callable $transporte = null,
    ) {
        $this->transporte = $transporte;
    }

    /**
     * Busca fotos. → {fotos:[foto normalizada], pagina, total, temMais}
     *
     * @throws ErroBancoImagens
     */
    public function buscar(string $termo, int $pagina = 1, ?string $orientacao = null): array
    {
        $termo = self::limparTermo($termo);
        if ($termo === '') {
            throw new ErroBancoImagens('Digite o que você procura (ex.: "consultório odontológico").', 422);
        }
        $pagina = max(1, min(self::MAX_PAGINA, $pagina));
        $params = [
            'q' => $termo, 'lang' => 'pt', 'image_type' => 'photo', 'safesearch' => 'true',
            'per_page' => self::POR_PAGINA, 'page' => $pagina,
        ];
        $o = self::orientacao($orientacao);
        if ($o !== null) {
            $params['orientation'] = $o;
        }
        $json = $this->pedirJson($params);
        $fotos = [];
        foreach ((array) ($json['hits'] ?? []) as $bruta) {
            $f = is_array($bruta) ? self::normalizar($bruta) : null;
            if ($f !== null) {
                $fotos[] = $f;
            }
        }
        $total = max(0, (int) ($json['totalHits'] ?? 0));
        return [
            'fotos' => $fotos,
            'pagina' => $pagina,
            'total' => $total,
            'temMais' => $pagina * self::POR_PAGINA < $total && $pagina < self::MAX_PAGINA,
        ];
    }

    /**
     * Uma foto pelo id. → foto normalizada + 'download' (versão grande, até 1280 px).
     *
     * @throws ErroBancoImagens
     */
    public function foto(int $id): array
    {
        if ($id < 1) {
            throw new ErroBancoImagens('Foto não encontrada no banco de imagens.', 404);
        }
        $json = $this->pedirJson(['id' => $id]);
        $bruta = $json['hits'][0] ?? null;
        $f = is_array($bruta) ? self::normalizar($bruta) : null;
        if ($f === null) {
            throw new ErroBancoImagens('Foto não encontrada no banco de imagens.', 404);
        }
        $download = is_string($bruta['largeImageURL'] ?? null) ? $bruta['largeImageURL'] : '';
        if (!self::urlPermitida($download)) {
            throw new ErroBancoImagens('O banco de imagens devolveu uma foto sem endereço válido.', 502);
        }
        return $f + ['download' => $download];
    }

    /**
     * Baixa uma imagem do Pixabay para um arquivo temporário (quem chama apaga).
     *
     * @throws ErroBancoImagens host não permitido, tamanho acima do limite, falha de rede
     */
    public function baixar(string $url, int $maxBytes, ?string $pasta = null): string
    {
        // Segue no máximo 2 redirecionamentos, cada um conferido (só hosts do Pixabay).
        for ($saltos = 0; ; $saltos++) {
            if (!self::urlPermitida($url)) {
                throw new ErroBancoImagens('Endereço de imagem não permitido.', 422);
            }
            [$status, $corpo, $tipo, $destino] = $this->transportar($url, [], max(5, $this->tempoLimite * 2), $maxBytes);
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $destino === '' || $saltos >= 2) {
                break;
            }
            $url = $destino;
        }
        if ($status === -1 || strlen($corpo) > $maxBytes) {
            throw new ErroBancoImagens('A foto do banco de imagens é grande demais.', 413);
        }
        if ($status !== 200) {
            throw self::erroHttp($status, '');
        }
        $tipo = strtolower(trim(explode(';', (string) $tipo)[0]));
        if ($corpo === '' || ($tipo !== '' && !in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true))) {
            throw new ErroBancoImagens('O banco de imagens devolveu um arquivo que não é foto.', 502);
        }
        $pasta ??= sys_get_temp_dir();
        $arquivo = tempnam($pasta, 'banco-');
        if ($arquivo === false || file_put_contents($arquivo, $corpo) !== strlen($corpo)) {
            if (is_string($arquivo)) {
                @unlink($arquivo);
            }
            throw new \RuntimeException('Não foi possível gravar a foto baixada.');
        }
        return $arquivo;
    }

    /** Só https://pixabay.com/… ou https://cdn.pixabay.com/… (sem usuário, senha nem outra porta). */
    public static function urlPermitida(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return false;
        }
        return in_array(strtolower((string) ($p['host'] ?? '')), self::HOSTS_IMAGENS, true)
            && preg_match('#^https://(cdn\.)?pixabay\.com(:443)?/[^\s\\\\]*$#iD', $url) === 1;
    }

    /**
     * Foto da API → {id, largura, altura, miniatura, media, alt, autor, autorUrl, url, cor}
     * (null se faltar o id ou as imagens não forem dos hosts permitidos).
     */
    public static function normalizar(array $f): ?array
    {
        $id = $f['id'] ?? null;
        $miniatura = is_string($f['webformatURL'] ?? null) ? $f['webformatURL'] : '';
        $media = is_string($f['largeImageURL'] ?? null) ? $f['largeImageURL'] : $miniatura;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        if (!self::urlPermitida($miniatura) || !self::urlPermitida($media)) {
            return null;
        }
        $usuario = self::textoCurto($f['user'] ?? '', 80);
        $usuarioId = (int) ($f['user_id'] ?? 0);
        return [
            'id' => (int) $id,
            'largura' => max(0, (int) ($f['imageWidth'] ?? 0)),
            'altura' => max(0, (int) ($f['imageHeight'] ?? 0)),
            'miniatura' => $miniatura,
            'media' => $media,
            'alt' => '', // o Pixabay só tem etiquetas soltas; o alt fica o do espaço da foto
            'autor' => $usuario,
            'autorUrl' => $usuario !== '' && $usuarioId > 0 && preg_match('/^[\w.-]+$/u', $usuario)
                ? 'https://pixabay.com/users/' . rawurlencode($usuario) . '-' . $usuarioId . '/' : '',
            'url' => self::urlPixabay($f['pageURL'] ?? ''),
            'cor' => '',
        ];
    }

    /** Termo de busca: uma linha, espaços colapsados, até MAX_TERMO caracteres. */
    public static function limparTermo(string $termo): string
    {
        $termo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $termo) ?? '';
        $termo = trim(preg_replace('/\s+/u', ' ', $termo) ?? '');
        return mb_substr($termo, 0, self::MAX_TERMO, 'UTF-8');
    }

    /** "horizontal" | "vertical" (aceita paisagem/retrato) ou null; "quadrada" não existe no Pixabay. */
    public static function orientacao(?string $o): ?string
    {
        return $o === null ? null : (self::ORIENTACOES[strtolower(trim($o))] ?? null);
    }

    /** A orientação pedida é conhecida (inclusive "quadrada", que vira "todas")? */
    public static function orientacaoValida(string $o): bool
    {
        $o = strtolower(trim($o));
        return isset(self::ORIENTACOES[$o]) || in_array($o, ['quadrada', 'square', 'todas'], true);
    }

    private static function textoCurto(mixed $v, int $max): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        $v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '') ?? '');
        return mb_substr($v, 0, $max, 'UTF-8');
    }

    /** Link para uma página do pixabay.com ou ''. */
    private static function urlPixabay(mixed $v): string
    {
        if (!is_string($v) || !preg_match('#^https://pixabay\.com/[^\s"<>\\\\]*$#iD', $v)) {
            return '';
        }
        return $v;
    }

    /**
     * GET na API com cache de 24 h por parâmetros (sem a chave no nome do arquivo).
     *
     * @throws ErroBancoImagens
     */
    private function pedirJson(array $params): array
    {
        if (trim($this->chave) === '') {
            throw new ErroBancoImagens('O banco de imagens não está configurado neste servidor.', 503);
        }
        ksort($params);
        $arquivoCache = null;
        if ($this->pastaCache !== null) {
            $arquivoCache = rtrim($this->pastaCache, '/') . '/' . sha1(json_encode($params)) . '.json';
            if (is_file($arquivoCache) && time() - (int) filemtime($arquivoCache) < self::CACHE_SEGUNDOS) {
                $json = json_decode((string) file_get_contents($arquivoCache), true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        $url = self::API . '?' . http_build_query(['key' => $this->chave] + $params, '', '&', PHP_QUERY_RFC3986);
        [$status, $corpo] = $this->transportar($url, ['Accept: application/json'], $this->tempoLimite, 2 * 1024 * 1024);
        if ($status !== 200) {
            throw self::erroHttp($status, $corpo);
        }
        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            throw new ErroBancoImagens('O banco de imagens devolveu uma resposta inesperada. Tente de novo.', 502);
        }
        if ($arquivoCache !== null && (is_dir(dirname($arquivoCache)) || @mkdir(dirname($arquivoCache), 0775, true))) {
            @file_put_contents($arquivoCache, json_encode($json), LOCK_EX);
        }
        return $json;
    }

    private static function erroHttp(int $status, string $corpo): ErroBancoImagens
    {
        return match (true) {
            $status === 0, $status === -1 => new ErroBancoImagens('O banco de imagens (Pixabay) não respondeu. Tente de novo em instantes.', 503),
            $status === 429 => new ErroBancoImagens('O limite de buscas no banco de imagens foi atingido por agora. Tente de novo em um minuto.', 429),
            ($status === 400 && stripos($corpo, 'key') !== false), $status === 401, $status === 403
                => new ErroBancoImagens('A chave do Pixabay é inválida. Confira a PIXABAY_API_KEY (ou pixabay.chave no config.php).', 502),
            $status === 404 => new ErroBancoImagens('Foto não encontrada no banco de imagens.', 404),
            $status >= 500 => new ErroBancoImagens('O banco de imagens (Pixabay) está fora do ar no momento. Tente de novo em instantes.', 503),
            default => new ErroBancoImagens('O banco de imagens recusou o pedido (erro ' . $status . ').', 502),
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string} [status (0 = falha de rede, -1 = passou do limite), corpo,
     *   Content-Type, destino do redirecionamento (Location) ou '']
     */
    private function transportar(string $url, array $cabecalhos, int $tempo, int $maxBytes): array
    {
        if ($this->transporte !== null) {
            $r = ($this->transporte)($url, $cabecalhos, $tempo, $maxBytes);
            return [(int) ($r[0] ?? 0), (string) ($r[1] ?? ''), (string) ($r[2] ?? ''), (string) ($r[3] ?? '')];
        }
        $corpo = '';
        $estourou = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $tempo,
            CURLOPT_USERAGENT => 'ConstrutorRankly/1.0',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $parte) use (&$corpo, &$estourou, $maxBytes): int {
                if (strlen($corpo) + strlen($parte) > $maxBytes) {
                    $estourou = true;
                    return 0; // interrompe a transferência
                }
                $corpo .= $parte;
                return strlen($parte);
            },
        ]);
        $ok = curl_exec($ch);
        $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tipo = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        $destino = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        curl_close($ch);
        if ($estourou) {
            return [-1, '', $tipo, ''];
        }
        return [$status, $ok === false ? '' : $corpo, $tipo, $destino];
    }
}
