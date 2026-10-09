<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Lib\Slug;

/**
 * Releases atômicas dos sites publicados [M10]:
 *
 *   sites/.releases/{slug}/{versao}-{token}/…   (pastas 0755, arquivos 0644)
 *   sites/{slug} → .releases/{slug}/{versao}-{token}   (link simbólico relativo)
 *
 * A troca é um rename() de um link temporário sobre sites/{slug}: o visitante vê a versão
 * antiga inteira ou a nova inteira, nunca um site pela metade. Só mexe em disco; o banco
 * fica com o Gerador.
 *
 * Sem links simbólicos (hospedagem compartilhada que bloqueia symlink()): a release no ar fica
 * anotada em sites/.releases/{slug}/.no-ar, trocado também por rename(). Nesse modo os sites
 * só são servidos pelo PHP (ServidorEstatico, via pastaNoAr()), que é o caso do modo
 * sites_no_caminho usado na hospedagem.
 */
final class Publicador
{
    public const PASTA_RELEASES = '.releases';
    private const RE_RELEASE = '/^(\d{1,9})-([a-z0-9]{4,32})$/D';
    /** Pastas parciais (publicação interrompida) mais velhas que isto são apagadas. */
    private const PARCIAL_VELHA_SEGUNDOS = 3600;
    /** Arquivo com o nome da release no ar, quando não há link simbólico. */
    public const ARQUIVO_NO_AR = '.no-ar';

    private readonly bool $usarLinks;

    /** $usarLinks null: usa links simbólicos se o PHP tiver a função symlink(). */
    public function __construct(private readonly string $dirSites, ?bool $usarLinks = null)
    {
        $this->usarLinks = $usarLinks ?? function_exists('symlink');
    }

    /** Nome novo de release: {versao}-{8 hex}. */
    public static function nomeRelease(int $versao): string
    {
        return $versao . '-' . bin2hex(random_bytes(4));
    }

    public static function releaseValida(string $nome): bool
    {
        return (bool) preg_match(self::RE_RELEASE, $nome);
    }

    /** Versão contida no nome da release ("12-ab34cd56" → 12). */
    public static function versaoDaRelease(string $nome): ?int
    {
        return preg_match(self::RE_RELEASE, $nome, $m) ? (int) $m[1] : null;
    }

    private function exigirSlug(string $slug): void
    {
        if (!Slug::valido($slug)) {
            throw new \InvalidArgumentException('Endereço (slug) do site inválido.');
        }
    }

    public function dirReleases(string $slug): string
    {
        $this->exigirSlug($slug);
        return $this->dirSites . '/' . self::PASTA_RELEASES . '/' . $slug;
    }

    public function dirRelease(string $slug, string $release): string
    {
        if (!self::releaseValida($release)) {
            throw new \InvalidArgumentException('Nome de release inválido.');
        }
        return $this->dirReleases($slug) . '/' . $release;
    }

    /** Caminho público do site (o link simbólico; sem links, não existe). */
    public function caminhoSite(string $slug): string
    {
        $this->exigirSlug($slug);
        return $this->dirSites . '/' . $slug;
    }

    /**
     * Grava uma release completa. $arquivos = caminho relativo → conteúdo; $copias = caminho
     * relativo → arquivo de origem. Escreve numa pasta parcial e só renomeia para o nome
     * final quando tudo deu certo. Devolve o caminho da release (ainda fora do ar).
     *
     * @param array<string, string> $arquivos
     * @param array<string, string> $copias
     */
    public function gravar(string $slug, string $release, array $arquivos, array $copias): string
    {
        $final = $this->dirRelease($slug, $release);
        $parcial = $this->dirReleases($slug) . '/.' . $release . '.parcial';
        if (file_exists($final) || file_exists($parcial)) {
            throw new \RuntimeException('Release já existe: ' . $release);
        }
        self::criarPasta($parcial);
        try {
            foreach ($arquivos as $rel => $conteudo) {
                $destino = $parcial . '/' . self::relativoSeguro($rel);
                self::criarPasta(dirname($destino));
                if (file_put_contents($destino, $conteudo, LOCK_EX) === false) {
                    throw new \RuntimeException("Não foi possível gravar {$rel}.");
                }
                @chmod($destino, 0644);
            }
            foreach ($copias as $rel => $origem) {
                $destino = $parcial . '/' . self::relativoSeguro($rel);
                self::criarPasta(dirname($destino));
                if (!is_file($origem) || !@copy($origem, $destino)) {
                    throw new \RuntimeException("Não foi possível copiar {$rel}.");
                }
                @chmod($destino, 0644);
            }
            if (!@rename($parcial, $final)) {
                throw new \RuntimeException('Não foi possível concluir a gravação da release.');
            }
        } catch (\Throwable $e) {
            self::apagarArvore($parcial);
            throw $e;
        }
        return $final;
    }

    /** Release no ar: a do link sites/{slug} ou, sem link, a anotada em .no-ar (null se nenhuma). */
    public function releaseAtual(string $slug): ?string
    {
        $link = $this->caminhoSite($slug);
        if (is_link($link)) {
            $nome = basename((string) readlink($link));
            return self::releaseValida($nome) ? $nome : null;
        }
        if (file_exists($link)) {
            return null; // pasta antiga (antes das releases)
        }
        $arquivo = $this->arquivoNoAr($slug);
        clearstatcache(true, $arquivo);
        $nome = is_file($arquivo) ? trim((string) @file_get_contents($arquivo)) : '';
        return self::releaseValida($nome) && is_dir($this->dirRelease($slug, $nome)) ? $nome : null;
    }

    /** Pasta real com os arquivos do site no ar (link, pasta antiga ou .no-ar), ou null. */
    public function pastaNoAr(string $slug): ?string
    {
        $link = $this->caminhoSite($slug);
        // O cache de realpath do PHP (por processo, realpath_cache_ttl = 120 s) guardaria o
        // destino antigo do link depois de uma troca atômica [M10]: limpa só este caminho.
        clearstatcache(true, $link);
        if (is_link($link) || file_exists($link)) {
            $real = realpath($link);
            return $real !== false && is_dir($real) ? $real : null;
        }
        $release = $this->releaseAtual($slug);
        $real = $release !== null ? realpath($this->dirRelease($slug, $release)) : false;
        return $real !== false && is_dir($real) ? $real : null;
    }

    private function arquivoNoAr(string $slug): string
    {
        return $this->dirReleases($slug) . '/' . self::ARQUIVO_NO_AR;
    }

    /** Grava .no-ar por arquivo temporário + rename (quem lê vê o nome antigo ou o novo inteiro). */
    private function gravarNoAr(string $slug, string $release): bool
    {
        $arquivo = $this->arquivoNoAr($slug);
        $tmp = $arquivo . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $release . "\n", LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $arquivo)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function apagarNoAr(string $slug): void
    {
        $arquivo = $this->arquivoNoAr($slug);
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    /**
     * Aponta sites/{slug} para a release (troca atômica por rename de um link temporário; sem
     * links simbólicos, por rename do arquivo .no-ar). Se sites/{slug} for uma PASTA real
     * (publicação antiga, antes das releases), ela é movida para
     * .releases/{slug}/{versaoLegado}-legado{hex} antes; o nome é devolvido em 'legado' para o
     * Gerador registrar.
     *
     * @return array{anterior: ?string, legado: ?string}
     */
    public function apontar(string $slug, string $release, int $versaoLegado = 0): array
    {
        $dir = $this->dirRelease($slug, $release);
        if (!is_dir($dir) || is_link($dir)) {
            throw new \RuntimeException('Release inexistente: ' . $release);
        }
        $link = $this->caminhoSite($slug);
        $anterior = $this->releaseAtual($slug);
        $tmp = null;
        if ($this->usarLinks) {
            $tmp = $this->dirSites . '/.tmp-' . $slug . '-' . bin2hex(random_bytes(4));
            if (!@symlink(self::PASTA_RELEASES . '/' . $slug . '/' . $release, $tmp)) {
                $tmp = null; // symlink() bloqueado: usa o .no-ar
            }
        }
        $legado = null;
        try {
            if (!is_link($link) && is_dir($link)) {
                $legado = max(0, $versaoLegado) . '-legado' . bin2hex(random_bytes(3));
                if (!@rename($link, $this->dirReleases($slug) . '/' . $legado)) {
                    throw new \RuntimeException('Não foi possível mover a publicação antiga para as releases.');
                }
            } elseif (!is_link($link) && file_exists($link)) {
                throw new \RuntimeException("sites/{$slug} existe e não é uma pasta nem um link.");
            }
            $trocou = $tmp !== null ? @rename($tmp, $link) : $this->gravarNoAr($slug, $release);
            if (!$trocou) {
                if ($legado !== null) {
                    @rename($this->dirReleases($slug) . '/' . $legado, $link); // devolve a pasta antiga ao ar
                }
                throw new \RuntimeException('Não foi possível trocar a versão publicada do site.');
            }
            if ($tmp !== null) {
                $this->apagarNoAr($slug); // sobra de quando não havia links
            } elseif (is_link($link)) {
                @unlink($link); // link de antes: agora vale o .no-ar
            }
        } catch (\Throwable $e) {
            if ($tmp !== null && is_link($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }
        return ['anterior' => $anterior, 'legado' => $legado];
    }

    /** Volta o site para $release (ou o tira do ar, se null). Usado para desfazer uma troca. */
    public function restaurar(string $slug, ?string $release): void
    {
        if ($release !== null && is_dir($this->dirRelease($slug, $release))) {
            $this->apontar($slug, $release);
            return;
        }
        $this->despublicar($slug);
    }

    /** Tira o site do ar (remove o link e o .no-ar; as releases ficam). */
    public function despublicar(string $slug): void
    {
        $link = $this->caminhoSite($slug);
        if (is_link($link)) {
            @unlink($link);
        }
        $this->apagarNoAr($slug);
    }

    /**
     * Releases completas do site, da mais nova para a mais velha.
     *
     * @return list<string>
     */
    public function listar(string $slug): array
    {
        $dir = $this->dirReleases($slug);
        if (!is_dir($dir)) {
            return [];
        }
        $r = [];
        foreach (scandir($dir) ?: [] as $nome) {
            if (self::releaseValida($nome) && is_dir($dir . '/' . $nome) && !is_link($dir . '/' . $nome)) {
                $r[$nome] = [(int) self::versaoDaRelease($nome), (int) @filemtime($dir . '/' . $nome)];
            }
        }
        uksort($r, static fn (string $a, string $b): int => [$r[$b][0], $r[$b][1], $b] <=> [$r[$a][0], $r[$a][1], $a]);
        return array_keys($r);
    }

    /**
     * Mantém as $manter releases mais novas (e sempre a que está no ar e as $proteger);
     * apaga as demais e as pastas parciais esquecidas. Devolve as removidas.
     *
     * @param list<string> $proteger
     * @return list<string>
     */
    public function limpar(string $slug, int $manter, array $proteger = []): array
    {
        $manter = max(1, $manter);
        $atual = $this->releaseAtual($slug);
        $removidas = [];
        foreach (array_slice($this->listar($slug), $manter) as $nome) {
            if ($nome === $atual || in_array($nome, $proteger, true)) {
                continue;
            }
            self::apagarArvore($this->dirRelease($slug, $nome));
            $removidas[] = $nome;
        }
        $dir = $this->dirReleases($slug);
        foreach (glob($dir . '/.*.parcial', GLOB_ONLYDIR) ?: [] as $parcial) {
            if ((int) @filemtime($parcial) < time() - self::PARCIAL_VELHA_SEGUNDOS) {
                self::apagarArvore($parcial);
            }
        }
        foreach (glob($dir . '/' . self::ARQUIVO_NO_AR . '.tmp-*') ?: [] as $tmp) {
            if ((int) @filemtime($tmp) < time() - self::PARCIAL_VELHA_SEGUNDOS) {
                @unlink($tmp);
            }
        }
        foreach (glob($this->dirSites . '/.tmp-' . $slug . '-*') ?: [] as $tmp) {
            if (is_link($tmp) && (int) @lstat($tmp)['mtime'] < time() - self::PARCIAL_VELHA_SEGUNDOS) {
                @unlink($tmp);
            }
        }
        return $removidas;
    }

    /** Caminho relativo seguro (sem "..", sem absoluto, sem segmentos ocultos). */
    public static function relativoSeguro(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, "\0")) {
            throw new \InvalidArgumentException("Caminho inválido na release: {$rel}");
        }
        foreach (explode('/', $rel) as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..' || str_starts_with($parte, '.')) {
                throw new \InvalidArgumentException("Caminho inválido na release: {$rel}");
            }
        }
        return $rel;
    }

    private static function criarPasta(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar a pasta ' . basename($dir) . '.');
        }
        @chmod($dir, 0755);
    }

    /** Apaga uma pasta e tudo dentro (sem seguir links simbólicos). */
    public static function apagarArvore(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}
