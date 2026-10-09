<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Banco de imagens Pexels (https://www.pexels.com/api/documentation/): busca de fotos e
 * download para dentro do sistema.
 *
 * - A chave vai no cabeçalho Authorization (nunca na URL, para não cair em logs).
 * - Download só de https://images.pexels.com (sem redirecionamentos): o servidor nunca é
 *   usado para buscar endereços internos (SSRF), com limite de tamanho e de tempo.
 * - Erros viram ErroPexels com mensagem pronta para o usuário.
 */
final class Pexels
{
    private const API = 'https://api.pexels.com/v1';
    public const HOST_IMAGENS = 'images.pexels.com';
    public const POR_PAGINA = 24;
    public const MAX_PAGINA = 40;
    public const MAX_TERMO = 100;
    /** Lado maior da foto importada (o editor também reduz uploads a 2400 px). */
    public const LADO_IMPORTACAO = 2400;

    private const ORIENTACOES = [
        'landscape' => 'landscape', 'portrait' => 'portrait', 'square' => 'square',
        'paisagem' => 'landscape', 'horizontal' => 'landscape', 'retrato' => 'portrait',
        'vertical' => 'portrait', 'quadrada' => 'square',
    ];

    /**
     * @var callable|null fn(string $url, array $cabecalhos, int $tempo, int $maxBytes): array{0:int,1:string,2?:string}
     *   [status HTTP (0 = falha de rede), corpo, Content-Type]. Usado nos testes.
     */
    private $transporte;

    public function __construct(
        private readonly string $chave,
        private readonly int $tempoLimite = 15,
        ?callable $transporte = null,
    ) {
        $this->transporte = $transporte;
    }

    /**
     * GET /v1/search. → {fotos:[foto normalizada], pagina, total, temMais}
     *
     * @throws ErroPexels
     */
    public function buscar(string $termo, int $pagina = 1, ?string $orientacao = null): array
    {
        $termo = self::limparTermo($termo);
        if ($termo === '') {
            throw new ErroPexels('Digite o que você procura (ex.: "consultório odontológico").', 422);
        }
        $pagina = max(1, min(self::MAX_PAGINA, $pagina));
        $params = ['query' => $termo, 'page' => $pagina, 'per_page' => self::POR_PAGINA, 'locale' => 'pt-BR'];
        $o = self::orientacao($orientacao);
        if ($o !== null) {
            $params['orientation'] = $o;
        }
        $json = $this->pedirJson(self::API . '/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $fotos = [];
        foreach ((array) ($json['photos'] ?? []) as $bruta) {
            $f = is_array($bruta) ? self::normalizar($bruta) : null;
            if ($f !== null) {
                $fotos[] = $f;
            }
        }
        $total = max(0, (int) ($json['total_results'] ?? 0));
        return [
            'fotos' => $fotos,
            'pagina' => $pagina,
            'total' => $total,
            'temMais' => !empty($json['next_page']) && $pagina < self::MAX_PAGINA,
        ];
    }

    /**
     * GET /v1/photos/{id}. → foto normalizada + 'download' (URL da versão a importar).
     *
     * @throws ErroPexels
     */
    public function foto(int $id): array
    {
        if ($id < 1) {
            throw new ErroPexels('Foto não encontrada no banco de imagens.', 404);
        }
        $json = $this->pedirJson(self::API . '/photos/' . $id, 'Foto não encontrada no banco de imagens.');
        $f = self::normalizar($json);
        $download = self::urlDownload($json);
        if ($f === null || $download === null) {
            throw new ErroPexels('O banco de imagens devolveu uma foto sem endereço válido.', 502);
        }
        return $f + ['download' => $download];
    }

    /**
     * Baixa uma imagem do Pexels para um arquivo temporário (quem chama apaga).
     *
     * @throws ErroPexels host não permitido, tamanho acima do limite, falha de rede
     */
    public function baixar(string $url, int $maxBytes, ?string $pasta = null): string
    {
        if (!self::urlPermitida($url)) {
            throw new ErroPexels('Endereço de imagem não permitido.', 422);
        }
        [$status, $corpo, $tipo] = $this->transportar($url, [], max(5, $this->tempoLimite * 2), $maxBytes) + [2 => ''];
        if ($status === -1 || strlen($corpo) > $maxBytes) {
            throw new ErroPexels('A foto do banco de imagens é grande demais.', 413);
        }
        if ($status !== 200) {
            throw self::erroHttp($status, 'Não foi possível baixar a foto do banco de imagens.');
        }
        $tipo = strtolower(trim(explode(';', (string) $tipo)[0]));
        if ($corpo === '' || ($tipo !== '' && !in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true))) {
            throw new ErroPexels('O banco de imagens devolveu um arquivo que não é foto.', 502);
        }
        $pasta ??= sys_get_temp_dir();
        $arquivo = tempnam($pasta, 'pexels-');
        if ($arquivo === false || file_put_contents($arquivo, $corpo) !== strlen($corpo)) {
            if (is_string($arquivo)) {
                @unlink($arquivo);
            }
            throw new \RuntimeException('Não foi possível gravar a foto baixada.');
        }
        return $arquivo;
    }

    /** Só https://images.pexels.com/… (sem usuário, senha nem porta diferente de 443). */
    public static function urlPermitida(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return false;
        }
        return strtolower((string) ($p['host'] ?? '')) === self::HOST_IMAGENS
            && preg_match('#^https://images\.pexels\.com(:443)?/[^\s\\\\]*$#iD', $url) === 1;
    }

    /**
     * Foto da API → {id, largura, altura, miniatura, media, alt, autor, autorUrl, url, cor}
     * (null se faltar o id ou as imagens não forem do host permitido).
     */
    public static function normalizar(array $f): ?array
    {
        $id = $f['id'] ?? null;
        $src = is_array($f['src'] ?? null) ? $f['src'] : [];
        $miniatura = is_string($src['medium'] ?? null) ? $src['medium'] : '';
        $media = is_string($src['large'] ?? null) ? $src['large'] : $miniatura;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        if (!self::urlPermitida($miniatura) || !self::urlPermitida($media)) {
            return null;
        }
        $cor = is_string($f['avg_color'] ?? null) && preg_match('/^#[0-9a-f]{6}$/iD', $f['avg_color']) ? strtolower($f['avg_color']) : '';
        return [
            'id' => (int) $id,
            'largura' => max(0, (int) ($f['width'] ?? 0)),
            'altura' => max(0, (int) ($f['height'] ?? 0)),
            'miniatura' => $miniatura,
            'media' => $media,
            'alt' => self::textoCurto($f['alt'] ?? '', 160),
            'autor' => self::textoCurto($f['photographer'] ?? '', 80),
            'autorUrl' => self::urlPexels($f['photographer_url'] ?? ''),
            'url' => self::urlPexels($f['url'] ?? ''),
            'cor' => $cor,
        ];
    }

    /**
     * Versão a importar: o original limitado a ~2400 px no lado maior (parâmetros w/h da CDN
     * do Pexels); sem original, src.large2x.
     */
    public static function urlDownload(array $f): ?string
    {
        $src = is_array($f['src'] ?? null) ? $f['src'] : [];
        $original = is_string($src['original'] ?? null) ? $src['original'] : '';
        if (self::urlPermitida($original) && !str_contains($original, '?')) {
            $w = (int) ($f['width'] ?? 0);
            $h = (int) ($f['height'] ?? 0);
            if (max($w, $h) <= self::LADO_IMPORTACAO && $w > 0 && $h > 0) {
                return $original . '?auto=compress&cs=tinysrgb';
            }
            $lado = $w >= $h ? 'w' : 'h';
            return $original . '?auto=compress&cs=tinysrgb&' . $lado . '=' . self::LADO_IMPORTACAO;
        }
        $grande = is_string($src['large2x'] ?? null) ? $src['large2x'] : '';
        return self::urlPermitida($grande) ? $grande : null;
    }

    /** Termo de busca: uma linha, espaços colapsados, até MAX_TERMO caracteres. */
    public static function limparTermo(string $termo): string
    {
        $termo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $termo) ?? '';
        $termo = trim(preg_replace('/\s+/u', ' ', $termo) ?? '');
        return mb_substr($termo, 0, self::MAX_TERMO, 'UTF-8');
    }

    /** "landscape" | "portrait" | "square" (aceita os nomes em português) ou null. */
    public static function orientacao(?string $o): ?string
    {
        return $o === null ? null : (self::ORIENTACOES[strtolower(trim($o))] ?? null);
    }

    private static function textoCurto(mixed $v, int $max): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        $v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '') ?? '');
        return mb_substr($v, 0, $max, 'UTF-8');
    }

    /** Link para uma página do pexels.com (crédito do autor) ou ''. */
    private static function urlPexels(mixed $v): string
    {
        if (!is_string($v) || !preg_match('#^https://(www\.)?pexels\.com/[^\s"<>\\\\]*$#iD', $v)) {
            return '';
        }
        return $v;
    }

    /** @throws ErroPexels */
    private function pedirJson(string $url, string $naoEncontrado = 'Não encontrado no banco de imagens.'): array
    {
        if (trim($this->chave) === '') {
            throw new ErroPexels('O banco de imagens não está configurado neste servidor.', 503);
        }
        [$status, $corpo] = $this->transportar($url, ['Authorization: ' . $this->chave, 'Accept: application/json'], $this->tempoLimite, 2 * 1024 * 1024);
        if ($status !== 200) {
            throw self::erroHttp($status, $naoEncontrado);
        }
        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            throw new ErroPexels('O banco de imagens devolveu uma resposta inesperada. Tente de novo.', 502);
        }
        return $json;
    }

    private static function erroHttp(int $status, string $naoEncontrado): ErroPexels
    {
        return match (true) {
            $status === 0, $status === -1 => new ErroPexels('O banco de imagens (Pexels) não respondeu. Tente de novo em instantes.', 503),
            $status === 429 => new ErroPexels('O limite de buscas no banco de imagens foi atingido por agora. Tente de novo mais tarde.', 429),
            $status === 401, $status === 403 => new ErroPexels('A chave do Pexels é inválida. Confira pexels.chave no config.php.', 502),
            $status === 404 => new ErroPexels($naoEncontrado, 404),
            $status >= 500 => new ErroPexels('O banco de imagens (Pexels) está fora do ar no momento. Tente de novo em instantes.', 503),
            default => new ErroPexels('O banco de imagens recusou o pedido (erro ' . $status . ').', 502),
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string} [status (0 = falha de rede, -1 = passou do limite), corpo, Content-Type]
     */
    private function transportar(string $url, array $cabecalhos, int $tempo, int $maxBytes): array
    {
        if ($this->transporte !== null) {
            $r = ($this->transporte)($url, $cabecalhos, $tempo, $maxBytes);
            return [(int) ($r[0] ?? 0), (string) ($r[1] ?? ''), (string) ($r[2] ?? '')];
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
        curl_close($ch);
        if ($estourou) {
            return [-1, '', $tipo];
        }
        return [$status, $ok === false ? '' : $corpo, $tipo];
    }
}
