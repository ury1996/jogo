<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Atualização automática numa hospedagem compartilhada (instalada pelo instalar.php).
 *
 * O GitHub Actions testa cada envio e publica o pacote na release "hospedagem" do repositório
 * (asset rankly-hospedagem.zip; o texto da release traz "codigo=<commit>"). Aqui, o cron:
 *
 *   1. a cada `atualizacao.intervalo_min` minutos consulta a release (token só de leitura);
 *   2. se o commit mudou, baixa o pacote, troca o código (mantendo config, fotos, sites e var/)
 *      e sincroniza a parte web na pasta pública (`atualizacao.dir_web`);
 *   3. deixa marcada a "pós-atualização": a PRÓXIMA execução do cron, já com o código novo,
 *      aplica as migrações do banco e republica os sites (este processo ainda tem as classes
 *      antigas na memória, por isso não faz isso agora).
 *
 * Nada de senha da hospedagem fica no GitHub: é a hospedagem que busca. Se algo falhar antes da
 * troca, nada muda; se falhar no meio da troca, a versão antiga volta.
 * Estado em var/atualizacao.json (última verificação, versão instalada, último erro).
 */
final class Atualizador
{
    public const ASSET = 'rankly-hospedagem.zip';
    private const API = 'https://api.github.com';
    private const MAX_BYTES = 80 * 1024 * 1024;
    /** O que é da instalação e passa de uma versão para a outra. */
    private const MANTER = ['config/config.php', 'media', 'sites', 'var'];

    /** @var callable fn(string $url, array $cabecalhos, ?string $salvarEm): array{0:int,1:string,2:array<string,string>} */
    private $http;

    public function __construct(private readonly Aplicacao $app, ?callable $http = null)
    {
        $this->http = $http ?? self::httpCurl(...);
    }

    /** Repositório, token e pasta pública configurados? */
    public function configurado(): bool
    {
        return $this->repo() !== '' && $this->token() !== '' && $this->dirWeb() !== '';
    }

    /** Commit da versão instalada (VERSAO.txt do pacote), ou ''. */
    public function versaoInstalada(): string
    {
        $txt = (string) @file_get_contents($this->app->raiz() . '/VERSAO.txt');
        return preg_match('/C[oó]digo:\s*([0-9a-f]{7,40})/u', $txt, $m) ? $m[1] : '';
    }

    /**
     * Chamado pelo cron. Devolve uma frase para o log quando faz algo (ou null).
     * $forcar ignora o intervalo e a opção "automatica".
     */
    public function talvezAtualizar(bool $forcar = false): ?string
    {
        if ($this->posPendente()) {
            return $this->posAtualizacao();
        }
        if (!$this->configurado() || (!$forcar && $this->app->config('atualizacao.automatica', true) !== true)) {
            return null;
        }
        $estado = $this->estado();
        $intervalo = max(1, (int) $this->app->config('atualizacao.intervalo_min', 5)) * 60;
        if (!$forcar && time() - (int) ($estado['verificadoEm'] ?? 0) < $intervalo) {
            return null;
        }
        $estado['verificadoEm'] = time();
        try {
            $nova = $this->verificar();
            $estado['ultimoErro'] = null;
            $this->salvarEstado($estado);
            if ($nova === null) {
                return null;
            }
            $this->aplicar($nova);
            return "Atualizado para {$nova['codigo']}; banco e sites no próximo cron.";
        } catch (\Throwable $e) {
            $estado['ultimoErro'] = gmdate('Y-m-d H:i') . ' ' . $e->getMessage();
            $this->salvarEstado($estado);
            $this->app->log()->erro('atualizacao: ' . $e->getMessage());
            return 'Falha na atualização: ' . $e->getMessage();
        }
    }

    /**
     * Pacote mais novo que o instalado → ['codigo' => sha, 'url' => endereço do asset na API], senão null.
     *
     * @throws \RuntimeException sem acesso ao GitHub, release ou asset ausente
     */
    public function verificar(): ?array
    {
        $tag = (string) $this->app->config('atualizacao.tag', 'hospedagem');
        [$status, $corpo] = ($this->http)(self::API . '/repos/' . $this->repo() . '/releases/tags/' . rawurlencode($tag), $this->cabecalhos('application/vnd.github+json'), null);
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('O token do GitHub foi recusado (vencido ou sem acesso ao repositório).');
        }
        if ($status === 404) {
            throw new \RuntimeException("Ainda não existe o pacote \"{$tag}\" no GitHub (o Actions publica depois do primeiro envio).");
        }
        if ($status !== 200) {
            throw new \RuntimeException("GitHub respondeu {$status} ao consultar a versão.");
        }
        $release = json_decode($corpo, true);
        if (!is_array($release) || !preg_match('/codigo=([0-9a-f]{7,40})/', (string) ($release['body'] ?? ''), $m)) {
            throw new \RuntimeException('A release do GitHub não informa o commit (codigo=…).');
        }
        $codigo = $m[1];
        $instalada = $this->versaoInstalada();
        if ($instalada !== '' && (str_starts_with($codigo, $instalada) || str_starts_with($instalada, $codigo))) {
            return null;
        }
        foreach ((array) ($release['assets'] ?? []) as $a) {
            if (is_array($a) && ($a['name'] ?? '') === self::ASSET && is_string($a['url'] ?? null)) {
                return ['codigo' => $codigo, 'url' => $a['url']];
            }
        }
        throw new \RuntimeException('A release não tem o arquivo ' . self::ASSET . '.');
    }

    /**
     * Baixa e troca o código. Depois disso o processo deve terminar (o resto é do próximo cron).
     *
     * @param array{codigo: string, url: string} $nova
     */
    public function aplicar(array $nova): void
    {
        $raiz = $this->app->raiz();
        $pai = dirname($raiz);
        $temp = $pai . '/.rankly-novo-' . bin2hex(random_bytes(4));
        $zipArq = $temp . '.zip';
        try {
            $this->baixar($nova['url'], $zipArq);
            $zip = new \ZipArchive();
            if ($zip->open($zipArq) !== true || !$zip->extractTo($temp)) {
                throw new \RuntimeException('O pacote baixado está corrompido.');
            }
            $zip->close();
            $novo = $temp . '/rankly';
            if (!is_file($novo . '/app/bootstrap.php') || !is_file($novo . '/vendor/autoload.php') || !is_file($novo . '/VERSAO.txt')) {
                throw new \RuntimeException('O pacote baixado está incompleto.');
            }
            $this->trocar($raiz, $novo);
        } finally {
            self::apagar($temp);
            @unlink($zipArq);
        }
        // A pasta var/ (com o estado) foi levada para a versão nova: grava lá.
        $estado = $this->estado();
        $estado['instaladoEm'] = time();
        $estado['posPendente'] = $nova['codigo'];
        $this->salvarEstado($estado);
        $this->app->log()->info('atualizacao: código trocado para ' . $nova['codigo']);
    }

    /** Pós-atualização (já com o código novo): migrações do banco e republicação dos sites. */
    public function posAtualizacao(): string
    {
        $db = $this->app->db();
        $aplicadas = (new Migrador($db, Migrador::dirPadrao($db)))->aplicar();
        $ok = 0;
        $falhas = [];
        Executores::registrarTodos($this->app);
        foreach ($db->todos("SELECT id, slug FROM sites WHERE status = 'publicado' ORDER BY id") as $s) {
            try {
                Executores::republicar($this->app, (int) $s['id'], null);
                $ok++;
            } catch (\Throwable $e) {
                $falhas[] = (string) $s['slug'];
                $this->app->log()->aviso('atualizacao: não republicou ' . $s['slug'] . ': ' . $e->getMessage());
            }
        }
        $estado = $this->estado();
        $estado['versao'] = $estado['posPendente'] ?? $this->versaoInstalada();
        unset($estado['posPendente']);
        $this->salvarEstado($estado);
        return 'Pós-atualização: ' . count($aplicadas) . " migração(ões), {$ok} site(s) republicado(s)"
            . ($falhas !== [] ? '; falharam: ' . implode(', ', $falhas) : '') . '.';
    }

    public function posPendente(): bool
    {
        return !empty($this->estado()['posPendente']);
    }

    /** Estado salvo (var/atualizacao.json). */
    public function estado(): array
    {
        $dados = json_decode((string) @file_get_contents($this->arquivoEstado()), true);
        return is_array($dados) ? $dados : [];
    }

    /* ------------------------------------------------------------------ troca do código */

    /** Põe $novo no lugar de $raiz mantendo o que é da instalação; volta atrás se falhar no meio. */
    private function trocar(string $raiz, string $novo): void
    {
        $antigo = $raiz . '.antigo-' . gmdate('YmdHis');
        if (!@rename($raiz, $antigo)) {
            throw new \RuntimeException('Não consegui separar a versão atual.');
        }
        try {
            if (!@rename($novo, $raiz)) {
                throw new \RuntimeException('Não consegui pôr a versão nova no lugar.');
            }
            foreach (self::MANTER as $item) {
                if (file_exists($antigo . '/' . $item) || is_link($antigo . '/' . $item)) {
                    self::apagar($raiz . '/' . $item);
                    if (!@rename($antigo . '/' . $item, $raiz . '/' . $item)) {
                        throw new \RuntimeException("Não consegui manter {$item}.");
                    }
                }
            }
            if (is_file($antigo . '/.htaccess')) {
                @copy($antigo . '/.htaccess', $raiz . '/.htaccess');
            }
            $this->sincronizarWeb($raiz . '/public_html', $this->dirWeb());
        } catch (\Throwable $e) {
            // Volta tudo: o que já foi movido para a nova retorna à antiga, e a antiga ao lugar.
            foreach (self::MANTER as $item) {
                if (!file_exists($antigo . '/' . $item) && (file_exists($raiz . '/' . $item) || is_link($raiz . '/' . $item))) {
                    @rename($raiz . '/' . $item, $antigo . '/' . $item);
                }
            }
            if (is_dir($raiz)) {
                @rename($raiz, $raiz . '.falhou-' . gmdate('YmdHis'));
            }
            @rename($antigo, $raiz);
            throw $e;
        }
        self::apagar($antigo);
    }

    /**
     * Copia a parte web para a pasta pública: sobrescreve, cria o que falta e apaga do editor/ e
     * da api/ o que não existe mais. rankly-raiz.php (gerado pelo instalador) fica como está.
     */
    private function sincronizarWeb(string $de, string $para): void
    {
        if (!is_dir($para)) {
            throw new \RuntimeException("Pasta pública não encontrada: {$para}");
        }
        $ignorar = ['rankly-raiz.php', 'router-dev.php'];
        self::copiar($de, $para, $ignorar);
        foreach (['editor', 'api'] as $sub) {
            self::podar($de . '/' . $sub, $para . '/' . $sub);
        }
    }

    private static function copiar(string $de, string $para, array $ignorar = []): void
    {
        if (!is_dir($para) && !@mkdir($para, 0755, true)) {
            throw new \RuntimeException("Não consegui criar {$para}.");
        }
        foreach (scandir($de) ?: [] as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $ignorar, true)) {
                continue;
            }
            if (is_dir($de . '/' . $item)) {
                self::copiar($de . '/' . $item, $para . '/' . $item);
            } elseif (!@copy($de . '/' . $item, $para . '/' . $item)) {
                throw new \RuntimeException("Não consegui copiar {$item} para a pasta pública.");
            }
        }
    }

    /** Apaga de $destino o que não existe em $origem (arquivos de versões antigas). */
    private static function podar(string $origem, string $destino): void
    {
        foreach (is_dir($destino) ? (scandir($destino) ?: []) : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (!file_exists($origem . '/' . $item)) {
                self::apagar($destino . '/' . $item);
            } elseif (is_dir($destino . '/' . $item)) {
                self::podar($origem . '/' . $item, $destino . '/' . $item);
            }
        }
    }

    public static function apagar(string $p): void
    {
        if (is_link($p) || is_file($p)) {
            @unlink($p);
            return;
        }
        if (!is_dir($p)) {
            return;
        }
        foreach (scandir($p) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::apagar($p . '/' . $f);
            }
        }
        @rmdir($p);
    }

    /* ------------------------------------------------------------------ GitHub */

    /** Baixa o asset: a API responde 302 para um endereço temporário, que NÃO recebe o token. */
    private function baixar(string $urlAsset, string $destino): void
    {
        [$status, , $cab] = ($this->http)($urlAsset, $this->cabecalhos('application/octet-stream'), null);
        $url = $urlAsset;
        $cabecalhos = $this->cabecalhos('application/octet-stream');
        if ($status >= 300 && $status < 400 && isset($cab['location'])) {
            $url = $cab['location'];
            $cabecalhos = ['User-Agent: rankly-atualizador'];
        } elseif ($status !== 200) {
            throw new \RuntimeException("GitHub respondeu {$status} ao baixar o pacote.");
        }
        [$status] = ($this->http)($url, $cabecalhos, $destino);
        if ($status !== 200 || !is_file($destino)) {
            throw new \RuntimeException("Falha ao baixar o pacote ({$status}).");
        }
        $tam = (int) filesize($destino);
        if ($tam < 1024 || $tam > self::MAX_BYTES || (string) file_get_contents($destino, false, null, 0, 2) !== 'PK') {
            throw new \RuntimeException('O arquivo baixado não é um pacote válido.');
        }
    }

    /** @return list<string> */
    private function cabecalhos(string $aceita): array
    {
        return [
            'Accept: ' . $aceita,
            'Authorization: Bearer ' . $this->token(),
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: rankly-atualizador',
        ];
    }

    /**
     * Transporte padrão (curl), sem seguir redirecionamentos (o token não pode ir junto).
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private static function httpCurl(string $url, array $cabecalhos, ?string $salvarEm): array
    {
        if (!str_starts_with($url, 'https://')) {
            return [0, '', []];
        }
        $ch = curl_init($url);
        $resp = [];
        $arq = null;
        $opcoes = [
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linha) use (&$resp): int {
                if (str_contains($linha, ':')) {
                    [$k, $v] = explode(':', $linha, 2);
                    $resp[strtolower(trim($k))] = trim($v);
                }
                return strlen($linha);
            },
        ];
        if ($salvarEm !== null) {
            $arq = fopen($salvarEm, 'wb');
            $opcoes[CURLOPT_FILE] = $arq;
        } else {
            $opcoes[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opcoes);
        $corpo = curl_exec($ch);
        $status = $corpo === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($arq !== null) {
            fclose($arq);
        }
        return [$status, is_string($corpo) ? $corpo : '', $resp];
    }

    private function repo(): string
    {
        $r = trim((string) $this->app->config('atualizacao.github_repo', ''));
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r) ? $r : '';
    }

    private function token(): string
    {
        return trim((string) $this->app->config('atualizacao.github_token', ''));
    }

    private function dirWeb(): string
    {
        return rtrim(trim((string) $this->app->config('atualizacao.dir_web', '')), '/');
    }

    private function arquivoEstado(): string
    {
        return $this->app->dir('var') . '/atualizacao.json';
    }

    private function salvarEstado(array $estado): void
    {
        @file_put_contents($this->arquivoEstado(), json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
