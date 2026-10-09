<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Preparo\Fotos;
use Rankly\Preparo\Texto;

/**
 * Fotos de exemplo da biblioteca (biblioteca/fotos/) viram mídias do site: o site já nasce com
 * fotos em todos os espaços, e o dono troca pelas dele (ou por fotos do banco de imagens).
 * As variantes WebP já vêm prontas da biblioteca e são ligadas (hard link) ou copiadas para
 * media/{site}/{id}/ — nenhum processamento de imagem na criação do site.
 */
final class FotosExemplo
{
    /** Dados da foto na biblioteca ou null. */
    public static function foto(array $lib, string $foto): ?array
    {
        $f = Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib['fotos'] ?? []), 'fotos')), $foto);
        return is_array($f) && preg_match('/^[a-z0-9-]{1,60}$/D', $foto) ? $f : null;
    }

    /** Caminho do arquivo de uma variante na biblioteca (ou null se não existe). */
    public static function arquivo(Aplicacao $app, string $foto, int $w): ?string
    {
        if (!preg_match('/^[a-z0-9-]{1,60}$/D', $foto)) {
            return null;
        }
        $caminho = $app->dir('biblioteca') . '/fotos/' . $foto . '-' . $w . '.webp';
        return is_file($caminho) ? $caminho : null;
    }

    /**
     * Mídia do site para a foto de exemplo (reaproveita se o site já tem). Devolve o id "m_…".
     */
    public static function importar(Aplicacao $app, int $siteId, array $lib, string $foto): ?string
    {
        $f = self::foto($lib, $foto);
        if ($f === null) {
            return null;
        }
        $hash = hash('sha256', 'exemplo:' . $foto);
        $existente = $app->db()->um('SELECT id FROM midia WHERE site_id = ? AND hash = ? AND tipo = ?', [$siteId, $hash, 'foto']);
        if ($existente !== null && is_dir(Midia::pasta($app, $siteId, (string) $existente['id']))) {
            return (string) $existente['id'];
        }
        $variantes = array_values(array_filter(
            array_map('intval', Texto::comoLista($f['variantes'] ?? [])),
            static fn (int $w): bool => self::arquivo($app, $foto, $w) !== null,
        ));
        if ($variantes === []) {
            return null;
        }
        $id = Midia::novoId($app);
        $pasta = Midia::pasta($app, $siteId, $id);
        if (!@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta de mídia.');
        }
        $bytes = 0;
        foreach ($variantes as $w) {
            $origem = (string) self::arquivo($app, $foto, $w);
            $destino = $pasta . '/' . $w . '.webp';
            if (!@link($origem, $destino) && !@copy($origem, $destino)) {
                Midia::apagarPasta($pasta);
                throw new \RuntimeException('Não foi possível copiar a foto de exemplo.');
            }
            $bytes = max($bytes, (int) @filesize($destino));
        }
        $app->db()->inserir('midia', [
            'id' => $id,
            'site_id' => $siteId,
            'tipo' => 'foto',
            'mime' => 'image/webp',
            'largura' => (int) ($f['largura'] ?? 0),
            'altura' => (int) ($f['altura'] ?? 0),
            'bytes' => $bytes,
            'variantes' => json_encode($variantes),
            'hash' => $hash,
            'texto_alt' => mb_substr(Texto::textoDe($f['alt'] ?? ''), 0, 255) ?: null,
            'formato' => 'webp',
            'origem' => 'exemplo:' . $foto,
            'credito' => null,
            'criado_em' => $app->agoraSql(),
        ]);
        return $id;
    }

    /**
     * Preenche os espaços de imagem vazios do documento com fotos de exemplo (importadas como
     * mídias do site). Devolve o documento novo e quantos espaços foram preenchidos.
     *
     * @return array{doc: array, preenchidos: int}
     */
    public static function preencher(Aplicacao $app, int $siteId, array $doc, array $lib): array
    {
        $ids = [];
        $n = 0;
        foreach (Fotos::imagensDeExemplo($doc, $lib) as $chave => $foto) {
            $ids[$foto] ??= self::importar($app, $siteId, $lib, $foto);
            if ($ids[$foto] !== null) {
                $doc['imagens'][$chave] = $ids[$foto];
                $n++;
            }
        }
        return ['doc' => $doc, 'preenchidos' => $n];
    }
}
