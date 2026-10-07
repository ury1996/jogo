<?php

declare(strict_types=1);

namespace Rankly;

use Rankly\Lib\Config;
use Rankly\Lib\Db;
use Rankly\Lib\Email\Mailer;
use Rankly\Lib\Log;
use Rankly\Lib\Tarefas;
use Rankly\Preparo\Biblioteca;

/**
 * Contêiner simples da aplicação (contrato §11.1): configuração, banco, biblioteca,
 * relógio (fixável nos testes), fila de tarefas, log e URLs dos sites.
 */
final class Aplicacao
{
    private ?Db $db = null;
    private ?array $biblioteca = null;
    private ?array $bundle = null;
    private ?\DateTimeImmutable $agoraFixo = null;
    private ?Tarefas $tarefas = null;
    private ?Log $log = null;
    private ?Mailer $mailer = null;
    private ?object $gerador = null;

    private function __construct(private readonly array $config, private readonly string $raiz)
    {
    }

    /** null → carrega config/config.php (ou o arquivo em RANKLY_CONFIG). */
    public static function iniciar(?array $config = null): self
    {
        $raiz = dirname(__DIR__);
        if ($config === null) {
            $arquivo = getenv('RANKLY_CONFIG');
            $config = Config::carregarArquivo(is_string($arquivo) && $arquivo !== '' ? $arquivo : $raiz . '/config/config.php');
        }
        return new self(Config::mesclar(Config::padrao(), $config), $raiz);
    }

    /** Chaves com ponto: 'db.dsn', 'smtp.host'. */
    public function config(string $chave, mixed $padrao = null): mixed
    {
        return Config::pegar($this->config, $chave, $padrao);
    }

    public function producao(): bool
    {
        return $this->config('ambiente') === 'prod';
    }

    /** Raiz do projeto (sem / final). */
    public function raiz(): string
    {
        return $this->raiz;
    }

    /** 'sites' | 'media' | 'var' | 'biblioteca' → caminho absoluto, sem / final. */
    public function dir(string $nome): string
    {
        $chave = match ($nome) {
            'sites' => 'dir_sites',
            'media' => 'dir_media',
            'var' => 'dir_var',
            'biblioteca' => 'dir_biblioteca',
            default => throw new \InvalidArgumentException("Pasta desconhecida: {$nome}"),
        };
        $valor = (string) $this->config($chave, $nome);
        if ($valor === '') {
            $valor = $nome;
        }
        $absoluto = str_starts_with($valor, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $valor) ? $valor : $this->raiz . '/' . $valor;
        return rtrim($absoluto, '/');
    }

    /** Subpasta de var/ (criada se faltar): 'cache', 'logs', 'sessoes', 'emails', 'locks'. */
    public function dirVar(string $sub): string
    {
        $dir = $this->dir('var') . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar {$dir}.");
        }
        return $dir;
    }

    public function db(): Db
    {
        return $this->db ??= Db::conectar((array) $this->config('db', []));
    }

    /** Bundle da biblioteca (§6.1), com cache em memória e em var/cache. */
    public function biblioteca(): array
    {
        if ($this->biblioteca === null) {
            $bundle = $this->bundleBiblioteca();
            $this->biblioteca = $bundle['dados'] ?? json_decode($bundle['json'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $this->biblioteca;
    }

    /**
     * Bundle pronto para a API: versão (sha1 do conteúdo) e JSON.
     * O cache em var/cache é indexado por uma impressão barata (caminhos, tamanhos e
     * datas dos arquivos), para não recalcular o sha1 do conteúdo a cada requisição.
     *
     * @return array{versao: string, json: string, dados?: array}
     */
    public function bundleBiblioteca(): array
    {
        if ($this->bundle !== null) {
            return $this->bundle;
        }
        $dir = $this->dir('biblioteca');
        $impressao = hash_init('sha1');
        foreach (Biblioteca::listarArquivos($dir) as $rel) {
            $info = @stat($dir . '/' . $rel);
            hash_update($impressao, $rel . "\0" . ($info['size'] ?? 0) . "\0" . ($info['mtime'] ?? 0) . "\n");
        }
        $chave = hash_final($impressao);
        $arquivo = null;
        try {
            $arquivo = $this->dirVar('cache') . '/biblioteca-' . $chave . '.json';
        } catch (\Throwable) {
            // sem var/ gravável: segue sem cache em disco
        }
        if ($arquivo !== null && is_file($arquivo)) {
            $conteudo = (string) file_get_contents($arquivo);
            $sep = strpos($conteudo, "\n");
            if ($sep !== false) {
                return $this->bundle = ['versao' => substr($conteudo, 0, $sep), 'json' => substr($conteudo, $sep + 1)];
            }
        }
        $dados = Biblioteca::carregar($dir);
        $json = Biblioteca::json($dados);
        if ($arquivo !== null) {
            $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $dados['versao'] . "\n" . $json) !== false) {
                @rename($tmp, $arquivo);
            }
            foreach (glob(dirname($arquivo) . '/biblioteca-*.json') ?: [] as $velho) {
                if ($velho !== $arquivo && filemtime($velho) < time() - 3600) {
                    @unlink($velho);
                }
            }
        }
        return $this->bundle = ['versao' => (string) $dados['versao'], 'json' => $json, 'dados' => $dados];
    }

    /** Hora atual em UTC (ou a fixada por definirAgora). */
    public function agora(): \DateTimeImmutable
    {
        return $this->agoraFixo ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function definirAgora(?\DateTimeImmutable $t): void
    {
        $this->agoraFixo = $t?->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Hora atual no formato gravado no banco ("Y-m-d H:i:s", UTC). */
    public function agoraSql(string $deslocamento = ''): string
    {
        $t = $this->agora();
        if ($deslocamento !== '') {
            $t = $t->modify($deslocamento);
        }
        return $t->format('Y-m-d H:i:s');
    }

    public function tarefas(): Tarefas
    {
        return $this->tarefas ??= new Tarefas($this);
    }

    public function log(): Log
    {
        return $this->log ??= new Log($this->dir('var') . '/logs', fn (): \DateTimeImmutable => $this->agora());
    }

    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer($this);
    }

    /** protocolo_sites://{slug}.{dominio_sites} */
    public function urlSite(string $slug): string
    {
        return $this->config('protocolo_sites', 'https') . '://' . $slug . '.' . $this->config('dominio_sites');
    }

    /** Gerador de sites (Rankly\Gerador\Gerador), substituível nos testes. */
    public function gerador(): object
    {
        if ($this->gerador !== null) {
            return $this->gerador;
        }
        $classe = 'Rankly\\Gerador\\Gerador';
        if (!class_exists($classe)) {
            throw new \RuntimeException('Gerador indisponível (Rankly\\Gerador\\Gerador não encontrado).');
        }
        return $this->gerador = new $classe($this);
    }

    public function definirGerador(?object $gerador): void
    {
        $this->gerador = $gerador;
    }

    /** Segredo para o HMAC dos IPs (segredo_ip; em dev, cai para segredo_app). */
    public function segredoIp(): string
    {
        $segredo = (string) $this->config('segredo_ip', '');
        if ($segredo === '' && !$this->producao()) {
            $segredo = (string) $this->config('segredo_app', '');
        }
        if ($segredo === '') {
            throw new \RuntimeException('Configure segredo_ip em config/config.php.');
        }
        return $segredo;
    }
}
