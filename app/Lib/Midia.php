<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Mídia dos sites [M15][M26]: arquivos em media/{site_id}/{id}/ (orig.jpg | orig.png |
 * orig.svg | {w}.webp) e linhas na tabela midia. id = "m_" + 8 hex aleatórios.
 */
final class Midia
{
    public const RE_ID = '/^m_[0-9a-f]{8}$/D';

    /** Caminho de um arquivo da mídia (não verifica existência). */
    public static function caminho(Aplicacao $app, int $siteId, string $id, string $arquivo): string
    {
        if (!preg_match(self::RE_ID, $id) || !preg_match('/^(orig\.(jpg|png|svg)|[0-9]{1,5}\.webp)$/D', $arquivo)) {
            throw new \InvalidArgumentException('Arquivo de mídia inválido.');
        }
        return self::pasta($app, $siteId, $id) . '/' . $arquivo;
    }

    public static function pasta(Aplicacao $app, int $siteId, string $id): string
    {
        return $app->dir('media') . '/' . $siteId . '/' . $id;
    }

    /** id => {largura, altura, variantes, alt, tipo, formato} de todas as mídias do site. */
    public static function mapaDoSite(Aplicacao $app, int $siteId): array
    {
        $mapa = [];
        foreach ($app->db()->todos('SELECT * FROM midia WHERE site_id = ? ORDER BY criado_em, id', [$siteId]) as $linha) {
            $mapa[(string) $linha['id']] = self::publico($linha, false);
        }
        return $mapa;
    }

    public static function porId(Aplicacao $app, string $id): ?array
    {
        if (!preg_match(self::RE_ID, $id)) {
            return null;
        }
        return $app->db()->um('SELECT * FROM midia WHERE id = ?', [$id]);
    }

    /** Formato da API: {id?, largura, altura, variantes, alt, tipo, formato}. */
    public static function publico(array $linha, bool $comId = true): array
    {
        $variantes = json_decode((string) $linha['variantes'], true);
        $r = [
            'largura' => (int) $linha['largura'],
            'altura' => (int) $linha['altura'],
            'variantes' => is_array($variantes) ? array_map('intval', $variantes) : [],
            'alt' => (string) ($linha['texto_alt'] ?? ''),
            'tipo' => (string) $linha['tipo'],
            'formato' => (string) ($linha['formato'] ?? 'webp'),
        ];
        return $comId ? ['id' => (string) $linha['id']] + $r : $r;
    }

    /** Novo id "m_" + 8 hex que ainda não existe. */
    public static function novoId(Aplicacao $app): string
    {
        do {
            $id = 'm_' . bin2hex(random_bytes(4));
        } while ($app->db()->valor('SELECT 1 FROM midia WHERE id = ?', [$id]) !== null);
        return $id;
    }

    /**
     * Recebe um arquivo enviado: valida, processa (raster ou SVG) e registra.
     * Mesmo arquivo (sha256) já enviado ao site com o mesmo tipo → devolve o existente.
     *
     * @return array{midia: array, existente: bool}
     * @throws \InvalidArgumentException mensagem para o usuário
     */
    public static function receberArquivo(Aplicacao $app, int $siteId, string $tipo, string $arquivo): array
    {
        if (!in_array($tipo, ['foto', 'logo'], true)) {
            throw new \InvalidArgumentException('Tipo de imagem inválido (use foto ou logo).');
        }
        $bytes = (int) @filesize($arquivo);
        $limite = (int) $app->config('limite_upload_mb', 15) * 1024 * 1024;
        if ($bytes <= 0) {
            throw new \InvalidArgumentException('O arquivo enviado está vazio.');
        }
        if ($bytes > $limite) {
            throw new \InvalidArgumentException('O arquivo passa do limite de ' . (int) $app->config('limite_upload_mb', 15) . ' MB.');
        }
        $hash = (string) hash_file('sha256', $arquivo);
        $existente = $app->db()->um('SELECT * FROM midia WHERE site_id = ? AND hash = ? AND tipo = ?', [$siteId, $hash, $tipo]);
        if ($existente !== null && is_dir(self::pasta($app, $siteId, (string) $existente['id']))) {
            return ['midia' => self::publico($existente), 'existente' => true];
        }

        $id = self::novoId($app);
        $pastaFinal = self::pasta($app, $siteId, $id);
        $pastaTmp = $app->dir('media') . '/' . $siteId . '/.tmp-' . $id . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($pastaTmp, 0775, true) && !is_dir($pastaTmp)) {
            throw new \RuntimeException('Não foi possível criar a pasta de mídia.');
        }
        try {
            $mime = Imagem::detectarMime($arquivo);
            $conteudoSvg = null;
            if ($tipo === 'logo' && ($mime === 'image/svg+xml' || (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true)
                && Svg::pareceSvg((string) file_get_contents($arquivo, false, null, 0, 8192))))) {
                $conteudoSvg = (string) file_get_contents($arquivo);
            } elseif ($mime === 'image/svg+xml') {
                throw new \InvalidArgumentException('SVG só é aceito para o logo. Envie a foto em JPG, PNG ou WebP.');
            }
            if ($conteudoSvg !== null) {
                $limpo = Svg::sanitizar($conteudoSvg);
                file_put_contents($pastaTmp . '/orig.svg', $limpo['svg']);
                $info = [
                    'mime' => 'image/svg+xml', 'largura' => $limpo['largura'], 'altura' => $limpo['altura'],
                    'variantes' => [], 'formato' => 'svg',
                ];
            } else {
                $info = Imagem::processar($arquivo, $tipo, $pastaTmp, (int) $app->config('max_megapixels', 40));
            }
            if (!@rename($pastaTmp, $pastaFinal)) {
                throw new \RuntimeException('Não foi possível mover a mídia para a pasta final.');
            }
        } catch (\Throwable $e) {
            self::apagarPasta($pastaTmp);
            throw $e;
        }

        $linha = [
            'id' => $id,
            'site_id' => $siteId,
            'tipo' => $tipo,
            'mime' => $info['mime'],
            'largura' => $info['largura'],
            'altura' => $info['altura'],
            'bytes' => $bytes,
            'variantes' => json_encode($info['variantes']),
            'hash' => $hash,
            'texto_alt' => null,
            'formato' => $info['formato'],
            'criado_em' => $app->agoraSql(),
        ];
        try {
            $app->db()->inserir('midia', $linha);
        } catch (\Throwable $e) {
            self::apagarPasta($pastaFinal);
            throw $e;
        }
        return ['midia' => self::publico($linha), 'existente' => false];
    }

    /**
     * Copia uma mídia para outro site com id novo (duplicar site). Devolve o id novo.
     */
    public static function copiar(Aplicacao $app, array $linha, int $siteDestino): string
    {
        $novoId = self::novoId($app);
        $origem = self::pasta($app, (int) $linha['site_id'], (string) $linha['id']);
        $destino = self::pasta($app, $siteDestino, $novoId);
        if (is_dir($origem)) {
            if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
                throw new \RuntimeException('Não foi possível criar a pasta de mídia.');
            }
            foreach (scandir($origem) ?: [] as $arq) {
                if ($arq !== '.' && $arq !== '..' && is_file($origem . '/' . $arq)) {
                    copy($origem . '/' . $arq, $destino . '/' . $arq);
                }
            }
        }
        $nova = $linha;
        $nova['id'] = $novoId;
        $nova['site_id'] = $siteDestino;
        $nova['criado_em'] = $app->agoraSql();
        $app->db()->inserir('midia', $nova);
        return $novoId;
    }

    /** Ids de mídia referenciados por um documento (dados.logo + imagens). */
    public static function idsDoDocumento(mixed $doc): array
    {
        $ids = [];
        if (is_array($doc)) {
            $logo = $doc['dados']['logo'] ?? null;
            if (is_string($logo) && preg_match(self::RE_ID, $logo)) {
                $ids[$logo] = true;
            }
            foreach ((array) ($doc['imagens'] ?? []) as $v) {
                if (is_string($v) && preg_match(self::RE_ID, $v)) {
                    $ids[$v] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** Apaga linha e arquivos de uma mídia. */
    public static function excluir(Aplicacao $app, array $linha): void
    {
        $app->db()->executar('DELETE FROM midia WHERE id = ?', [(string) $linha['id']]);
        self::apagarPasta(self::pasta($app, (int) $linha['site_id'], (string) $linha['id']));
    }

    /**
     * Remove mídias com mais de $dias dias que nenhum documento nem versão do site usa.
     * Devolve quantas removeu.
     */
    public static function limparOrfas(Aplicacao $app, int $dias = 30): int
    {
        $db = $app->db();
        $limite = $app->agoraSql('-' . $dias . ' days');
        $candidatas = $db->todos('SELECT * FROM midia WHERE criado_em < ? ORDER BY site_id', [$limite]);
        $usadasPorSite = [];
        $n = 0;
        foreach ($candidatas as $linha) {
            $siteId = (int) $linha['site_id'];
            if (!isset($usadasPorSite[$siteId])) {
                $usadas = [];
                $docs = array_merge(
                    $db->todos('SELECT documento FROM sites WHERE id = ?', [$siteId]),
                    $db->todos('SELECT documento FROM versoes WHERE site_id = ?', [$siteId]),
                );
                foreach ($docs as $d) {
                    $doc = json_decode((string) $d['documento'], true);
                    foreach (self::idsDoDocumento($doc) as $id) {
                        $usadas[$id] = true;
                    }
                }
                $usadasPorSite[$siteId] = $usadas;
            }
            if (!isset($usadasPorSite[$siteId][(string) $linha['id']])) {
                self::excluir($app, $linha);
                $n++;
            }
        }
        // Pastas temporárias esquecidas por uploads interrompidos.
        foreach (glob($app->dir('media') . '/*/.tmp-*', GLOB_ONLYDIR) ?: [] as $tmp) {
            if (@filemtime($tmp) < $app->agora()->getTimestamp() - 3600) {
                self::apagarPasta($tmp);
            }
        }
        return $n;
    }

    /** Remove uma pasta e seus arquivos (só um nível: a mídia não tem subpastas). */
    public static function apagarPasta(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        foreach (scandir($pasta) ?: [] as $arq) {
            if ($arq !== '.' && $arq !== '..') {
                @unlink($pasta . '/' . $arq);
            }
        }
        @rmdir($pasta);
    }

    /** Tipo MIME e nome do arquivo de uma variante pedida pela rota ('orig' ou largura). */
    public static function arquivoDaVariante(array $linha, string $w): ?array
    {
        $formato = (string) ($linha['formato'] ?? 'webp');
        if ($formato === 'svg') {
            return $w === 'orig' ? ['arquivo' => 'orig.svg', 'tipo' => 'image/svg+xml'] : null;
        }
        if ($w === 'orig') {
            return (string) $linha['mime'] === 'image/png'
                ? ['arquivo' => 'orig.png', 'tipo' => 'image/png']
                : ['arquivo' => 'orig.jpg', 'tipo' => 'image/jpeg'];
        }
        if (!ctype_digit($w)) {
            return null;
        }
        $variantes = json_decode((string) $linha['variantes'], true);
        if (!is_array($variantes) || !in_array((int) $w, array_map('intval', $variantes), true)) {
            return null;
        }
        return ['arquivo' => ((int) $w) . '.webp', 'tipo' => 'image/webp'];
    }
}
