<?php

declare(strict_types=1);

namespace Rankly\Http;

/**
 * Requisição HTTP imutável. Nos testes é criada diretamente; na web, por doGlobais().
 */
final class Requisicao
{
    /** Tamanho máximo do corpo JSON aceito (o documento do site tem limite próprio menor). */
    public const MAX_JSON = 2 * 1024 * 1024;

    public readonly string $caminho;
    private array $cabecalhos = [];
    private bool $jsonLido = false;
    private mixed $jsonCache = null;

    /**
     * @param array<string,string> $cabecalhos nomes em qualquer caixa
     * @param array<string,mixed> $arquivos no formato de $_FILES (um arquivo por campo)
     * @param array<string,mixed> $servidor subconjunto de $_SERVER (REMOTE_ADDR, HTTPS…)
     */
    public function __construct(
        public readonly string $metodo,
        string $caminho,
        public readonly array $query = [],
        array $cabecalhos = [],
        public readonly string $corpo = '',
        public readonly array $cookies = [],
        public readonly array $form = [],
        public readonly array $arquivos = [],
        public readonly array $servidor = [],
    ) {
        foreach ($cabecalhos as $nome => $valor) {
            $this->cabecalhos[strtolower((string) $nome)] = (string) $valor;
        }
        $this->caminho = self::normalizarCaminho($caminho);
    }

    /** Requisição atual a partir das variáveis globais do PHP. */
    public static function doGlobais(): self
    {
        $cabecalhos = [];
        foreach ($_SERVER as $chave => $valor) {
            if (str_starts_with($chave, 'HTTP_')) {
                $cabecalhos[str_replace('_', '-', strtolower(substr($chave, 5)))] = (string) $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $cabecalhos['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $cabecalhos['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $tipo = strtolower($cabecalhos['content-type'] ?? '');
        $corpo = '';
        if (!str_starts_with($tipo, 'multipart/form-data')) {
            $corpo = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON + 1);
        }
        $servidor = [];
        foreach (['REMOTE_ADDR', 'HTTPS', 'SERVER_PORT', 'HTTP_HOST', 'REQUEST_SCHEME', 'SERVER_NAME'] as $chave) {
            if (isset($_SERVER[$chave])) {
                $servidor[$chave] = (string) $_SERVER[$chave];
            }
        }
        $caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($caminho) ? $caminho : '/',
            $_GET,
            $cabecalhos,
            $corpo,
            $_COOKIE,
            $_POST,
            $_FILES,
            $servidor,
        );
    }

    /** Sem barra final (exceto "/"), barras repetidas colapsadas. */
    public static function normalizarCaminho(string $caminho): string
    {
        $c = preg_replace('#/+#', '/', '/' . ltrim($caminho, '/')) ?? '/';
        return $c === '/' ? '/' : rtrim($c, '/');
    }

    public function cabecalho(string $nome): ?string
    {
        return $this->cabecalhos[strtolower($nome)] ?? null;
    }

    public function cookie(string $nome): ?string
    {
        $v = $this->cookies[$nome] ?? null;
        return is_string($v) ? $v : null;
    }

    public function parametroQuery(string $nome): ?string
    {
        $v = $this->query[$nome] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Content-Type é JSON? */
    public function ehJson(): bool
    {
        return str_contains(strtolower($this->cabecalho('content-type') ?? ''), 'application/json');
    }

    /** O cliente espera JSON (fetch/XHR) em vez de uma página? */
    public function querJson(): bool
    {
        $aceita = strtolower($this->cabecalho('accept') ?? '');
        // fetch()/XHR mandam "Sec-Fetch-Dest: empty"; envio normal de formulário manda "document".
        return str_contains($aceita, 'application/json')
            || strtolower($this->cabecalho('sec-fetch-dest') ?? '') === 'empty'
            || strtolower($this->cabecalho('x-requested-with') ?? '') === 'xmlhttprequest'
            || $this->ehJson();
    }

    /**
     * Corpo JSON decodificado (arrays associativos).
     *
     * @throws ErroHttp 400/413 corpo inválido ou grande demais
     */
    public function json(): mixed
    {
        if (!$this->jsonLido) {
            $this->jsonLido = true;
            if (strlen($this->corpo) > self::MAX_JSON) {
                throw new ErroHttp(413, 'grande_demais', 'Os dados enviados são grandes demais.');
            }
            if (trim($this->corpo) === '') {
                $this->jsonCache = null;
            } else {
                try {
                    $this->jsonCache = json_decode($this->corpo, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (\JsonException) {
                    throw ErroHttp::requisicaoRuim('Os dados enviados não estão em JSON válido.');
                }
            }
        }
        return $this->jsonCache;
    }

    /** Corpo como mapa: JSON (objeto) ou formulário. Nunca lança por corpo vazio. */
    public function dados(): array
    {
        if ($this->ehJson()) {
            $j = $this->json();
            if ($j !== null && (!is_array($j) || ($j !== [] && array_is_list($j)))) {
                throw ErroHttp::requisicaoRuim('Os dados enviados precisam ser um objeto JSON.');
            }
            return $j ?? [];
        }
        if ($this->form !== []) {
            return $this->form;
        }
        if ($this->corpo !== '' && str_contains(strtolower($this->cabecalho('content-type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($this->corpo, $campos);
            return $campos;
        }
        // Sem Content-Type declarado: tenta JSON (clientes simples).
        if ($this->corpo !== '' && in_array(ltrim($this->corpo)[0] ?? '', ['{'], true)) {
            $j = $this->json();
            return is_array($j) ? $j : [];
        }
        return [];
    }

    /** Arquivo enviado (formato $_FILES) ou null. */
    public function arquivo(string $campo): ?array
    {
        $a = $this->arquivos[$campo] ?? null;
        if (!is_array($a) || !isset($a['error']) || is_array($a['error'])) {
            return null;
        }
        return $a;
    }

    /** IP do cliente; CF-Connecting-IP só quando a configuração confia na Cloudflare. */
    public function ip(bool $confiarCloudflare = false): string
    {
        if ($confiarCloudflare) {
            $cf = trim($this->cabecalho('cf-connecting-ip') ?? '');
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
                return $cf;
            }
        }
        $ip = (string) ($this->servidor['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr($this->cabecalho('user-agent') ?? '', 0, 300);
    }

    /** A conexão chegou por HTTPS (direto ou via proxy que informa X-Forwarded-Proto)? */
    public function seguro(): bool
    {
        $https = strtolower((string) ($this->servidor['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || strtolower($this->cabecalho('x-forwarded-proto') ?? '') === 'https';
    }
}
