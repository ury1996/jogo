<?php

declare(strict_types=1);

namespace Rankly\Http;

/**
 * Resposta HTTP: JSON, arquivo, redirecionamento ou texto. Montada pelos controladores
 * e enviada no fim por enviar() (nos testes, inspecionada diretamente).
 */
final class Resposta
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string,string> nome canônico → valor */
    private array $cabecalhos = [];
    /** @var list<string> linhas Set-Cookie */
    private array $cookies = [];

    public function __construct(
        public int $status = 200,
        public string $corpo = '',
        array $cabecalhos = [],
        public ?string $arquivo = null,
    ) {
        foreach ($cabecalhos as $nome => $valor) {
            $this->cabecalho((string) $nome, (string) $valor);
        }
    }

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self($status, json_encode($dados, self::JSON_FLAGS), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** Arquivo do disco, transmitido sem carregar na memória. */
    public static function arquivo(string $caminho, string $tipo, array $cabecalhos = []): self
    {
        $r = new self(200, '', ['Content-Type' => $tipo] + $cabecalhos, $caminho);
        $tamanho = @filesize($caminho);
        if ($tamanho !== false) {
            $r->cabecalho('Content-Length', (string) $tamanho);
        }
        return $r;
    }

    public static function redirecionar(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    public static function texto(string $texto, int $status = 200, string $tipo = 'text/plain; charset=utf-8'): self
    {
        return new self($status, $texto, ['Content-Type' => $tipo]);
    }

    public static function vazia(int $status = 204): self
    {
        return new self($status);
    }

    /** Define (ou substitui) um cabeçalho. */
    public function cabecalho(string $nome, string $valor): self
    {
        if (preg_match('/[\r\n]/', $nome . $valor)) {
            throw new \InvalidArgumentException('Cabeçalho com quebra de linha.');
        }
        foreach (array_keys($this->cabecalhos) as $existente) {
            if (strcasecmp($existente, $nome) === 0) {
                unset($this->cabecalhos[$existente]);
            }
        }
        $this->cabecalhos[$nome] = $valor;
        return $this;
    }

    /** Define o cabeçalho só se ainda não existir. */
    public function cabecalhoPadrao(string $nome, string $valor): self
    {
        return $this->obterCabecalho($nome) === null ? $this->cabecalho($nome, $valor) : $this;
    }

    public function obterCabecalho(string $nome): ?string
    {
        foreach ($this->cabecalhos as $existente => $valor) {
            if (strcasecmp($existente, $nome) === 0) {
                return $valor;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    public function cabecalhos(): array
    {
        return $this->cabecalhos;
    }

    /**
     * Acrescenta um cookie (Set-Cookie).
     *
     * @param array{expira?: int, maxAge?: int, caminho?: string, seguro?: bool, httpOnly?: bool, sameSite?: string} $op
     */
    public function cookie(string $nome, string $valor, array $op = []): self
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/D', $nome) || preg_match('/[\x00-\x20;,"\\\\\x7f]/', $valor)) {
            throw new \InvalidArgumentException('Cookie inválido.');
        }
        $linha = $nome . '=' . $valor . '; Path=' . ($op['caminho'] ?? '/');
        if (isset($op['maxAge'])) {
            $linha .= '; Max-Age=' . (int) $op['maxAge'];
            $linha .= '; Expires=' . gmdate('D, d M Y H:i:s', ($op['expira'] ?? time()) + (int) $op['maxAge']) . ' GMT';
        }
        if ($op['seguro'] ?? false) {
            $linha .= '; Secure';
        }
        if ($op['httpOnly'] ?? true) {
            $linha .= '; HttpOnly';
        }
        $linha .= '; SameSite=' . ($op['sameSite'] ?? 'Lax');
        $this->cookies[] = $linha;
        return $this;
    }

    /** @return list<string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** Corpo JSON decodificado (útil nos testes). */
    public function dados(): mixed
    {
        return json_decode($this->corpo, true);
    }

    /** Envia status, cabeçalhos, cookies e corpo. */
    public function enviar(bool $soCabecalhos = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->cabecalhos as $nome => $valor) {
                header($nome . ': ' . $valor);
            }
            foreach ($this->cookies as $linha) {
                header('Set-Cookie: ' . $linha, false);
            }
        }
        if ($soCabecalhos || $this->status === 204 || $this->status === 304) {
            return;
        }
        if ($this->arquivo !== null) {
            $h = @fopen($this->arquivo, 'rb');
            if ($h !== false) {
                fpassthru($h);
                fclose($h);
            }
            return;
        }
        echo $this->corpo;
    }
}
