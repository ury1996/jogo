<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Http\Resposta;

/**
 * Sessão própria [M27] em arquivos (var/sessoes), com cookie rk_sessao
 * (HttpOnly, SameSite=Lax, Secure em produção). O arquivo tem o nome do sha256 do id,
 * então quem listar a pasta não obtém ids válidos. Expira após "sessao_dias" sem uso.
 */
final class Sessao
{
    public const COOKIE = 'rk_sessao';
    /** Regrava o arquivo (renovando a validade) no máximo a cada 5 minutos. */
    private const INTERVALO_TOQUE = 300;

    private ?string $id = null;
    private array $dados = [];
    private int $criada = 0;
    private int $ultimo = 0;
    private bool $gravar = false;
    private bool $enviarCookie = false;
    private bool $apagarCookie = false;
    /** @var list<string> ids cujos arquivos devem ser apagados ao salvar */
    private array $descartados = [];

    private function __construct(private readonly Aplicacao $app)
    {
    }

    /** Carrega a sessão do cookie (ou começa vazia, sem id, até algo ser gravado). */
    public static function carregar(Aplicacao $app, ?string $cookie): self
    {
        $s = new self($app);
        if ($cookie === null || !preg_match('/^[a-f0-9]{64}$/D', $cookie)) {
            return $s;
        }
        $arquivo = $s->arquivo($cookie);
        $conteudo = @file_get_contents($arquivo);
        if ($conteudo === false) {
            $s->apagarCookie = true;
            return $s;
        }
        $registro = json_decode($conteudo, true);
        $agora = $s->agora();
        if (!is_array($registro) || !is_array($registro['dados'] ?? null)
            || (int) ($registro['ultimo'] ?? 0) + $s->duracao() < $agora) {
            @unlink($arquivo);
            $s->apagarCookie = true;
            return $s;
        }
        $s->id = $cookie;
        $s->dados = $registro['dados'];
        $s->criada = (int) ($registro['criada'] ?? $agora);
        $s->ultimo = (int) $registro['ultimo'];
        if ($agora - $s->ultimo >= self::INTERVALO_TOQUE) {
            $s->gravar = true;
            $s->enviarCookie = true;
        }
        return $s;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return array_key_exists($chave, $this->dados) ? $this->dados[$chave] : $padrao;
    }

    public function set(string $chave, mixed $valor): void
    {
        $this->garantirId();
        $this->dados[$chave] = $valor;
        $this->gravar = true;
    }

    public function remover(string $chave): void
    {
        if (array_key_exists($chave, $this->dados)) {
            unset($this->dados[$chave]);
            $this->gravar = true;
        }
    }

    /** Troca o id (ex.: no login), mantendo os dados — evita fixação de sessão. */
    public function regenerar(): void
    {
        if ($this->id !== null) {
            $this->descartados[] = $this->id;
        }
        $this->id = self::novoId();
        $this->criada = $this->agora();
        $this->gravar = true;
        $this->enviarCookie = true;
        $this->apagarCookie = false;
    }

    /** Encerra a sessão (logout): apaga o arquivo e o cookie. */
    public function destruir(): void
    {
        if ($this->id !== null) {
            $this->descartados[] = $this->id;
        }
        $this->id = null;
        $this->dados = [];
        $this->gravar = false;
        $this->enviarCookie = false;
        $this->apagarCookie = true;
    }

    /** Persiste as mudanças e anexa o cookie à resposta quando necessário. */
    public function salvar(Resposta $resposta, bool $seguro): void
    {
        foreach ($this->descartados as $id) {
            @unlink($this->arquivo($id));
        }
        $this->descartados = [];
        if ($this->id !== null && $this->gravar) {
            $this->ultimo = $this->agora();
            $dir = $this->app->dirVar('sessoes');
            $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(8));
            $json = json_encode(['criada' => $this->criada, 'ultimo' => $this->ultimo, 'dados' => $this->dados], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->arquivo($this->id))) {
                @unlink($tmp);
                throw new \RuntimeException('Não foi possível gravar a sessão.');
            }
            @chmod($this->arquivo($this->id), 0600);
            $this->gravar = false;
        }
        if ($this->id !== null && $this->enviarCookie) {
            $resposta->cookie(self::COOKIE, $this->id, [
                'maxAge' => $this->duracao(), 'expira' => $this->agora(), 'seguro' => $seguro, 'httpOnly' => true, 'sameSite' => 'Lax',
            ]);
            $this->enviarCookie = false;
        } elseif ($this->id === null && $this->apagarCookie) {
            $resposta->cookie(self::COOKIE, 'x', ['maxAge' => 0, 'expira' => $this->agora(), 'seguro' => $seguro, 'httpOnly' => true, 'sameSite' => 'Lax']);
            $this->apagarCookie = false;
        }
    }

    /** Apaga arquivos de sessões vencidas (limpeza diária). Devolve quantos apagou. */
    public static function limparVencidas(Aplicacao $app): int
    {
        $dir = $app->dir('var') . '/sessoes';
        $limite = $app->agora()->getTimestamp() - max(1, (int) $app->config('sessao_dias', 7)) * 86400;
        $n = 0;
        foreach (glob($dir . '/*.json') ?: [] as $arquivo) {
            $registro = json_decode((string) @file_get_contents($arquivo), true);
            if (!is_array($registro) || (int) ($registro['ultimo'] ?? 0) < $limite) {
                if (@unlink($arquivo)) {
                    $n++;
                }
            }
        }
        foreach (glob($dir . '/.tmp-*') ?: [] as $tmp) {
            if (@filemtime($tmp) < time() - 3600) {
                @unlink($tmp);
            }
        }
        return $n;
    }

    private function garantirId(): void
    {
        if ($this->id === null) {
            $this->id = self::novoId();
            $this->criada = $this->agora();
            $this->enviarCookie = true;
            $this->apagarCookie = false;
        }
    }

    private static function novoId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function arquivo(string $id): string
    {
        return $this->app->dirVar('sessoes') . '/' . hash('sha256', $id) . '.json';
    }

    private function agora(): int
    {
        return $this->app->agora()->getTimestamp();
    }

    private function duracao(): int
    {
        return max(1, (int) $this->app->config('sessao_dias', 7)) * 86400;
    }
}
