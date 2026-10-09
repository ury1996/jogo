<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Carrega a biblioteca (biblioteca/) no formato do bundle (§6.1), usado pela API, pelo
 * gerador e pelos testes. tests/paridade/carregar-biblioteca.mjs faz o mesmo em Node,
 * com a mesma estrutura e a mesma versão.
 */
final class Biblioteca
{
    /**
     * Arquivos da biblioteca (caminhos relativos com "/"), sem ocultos, em ordem de bytes.
     *
     * @return list<string>
     */
    public static function listarArquivos(string $dir): array
    {
        $arquivos = [];
        $visitar = static function (string $relativo) use (&$visitar, &$arquivos, $dir): void {
            $absoluto = $relativo === '' ? $dir : $dir . '/' . $relativo;
            $nomes = scandir($absoluto);
            if ($nomes === false) {
                return;
            }
            foreach ($nomes as $nome) {
                if ($nome === '' || $nome[0] === '.') {
                    continue;
                }
                $rel = $relativo === '' ? $nome : $relativo . '/' . $nome;
                $caminho = $dir . '/' . $rel;
                if (is_dir($caminho)) {
                    $visitar($rel);
                } elseif (is_file($caminho)) {
                    $arquivos[] = $rel;
                }
            }
        };
        $visitar('');
        usort($arquivos, static fn (string $a, string $b): int => strcmp($a, $b));
        return $arquivos;
    }

    /** sha1 de "caminho\0conteudo\0" de todos os arquivos, em ordem de caminho. */
    public static function versao(string $dir): string
    {
        $dir = rtrim($dir, '/');
        $hash = hash_init('sha1');
        foreach (self::listarArquivos($dir) as $rel) {
            hash_update($hash, $rel . "\0");
            hash_update_file($hash, $dir . '/' . $rel);
            hash_update($hash, "\0");
        }
        return hash_final($hash);
    }

    private static function lerJson(string $arquivo): mixed
    {
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) {
            throw new \RuntimeException("Não foi possível ler {$arquivo}.");
        }
        try {
            return json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("JSON inválido em {$arquivo}: {$e->getMessage()}", 0, $e);
        }
    }

    private static function lerTexto(string $arquivo): string
    {
        return is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
    }

    private static function lerJsonOpcional(string $arquivo): mixed
    {
        return is_file($arquivo) ? self::lerJson($arquivo) : [];
    }

    /** @return list<string> */
    private static function entradas(string $dir, callable $filtro): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $nomes = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $n): bool => $n !== '' && $n[0] !== '.' && $filtro($n),
        ));
        usort($nomes, static fn (string $a, string $b): int => strcmp($a, $b));
        return $nomes;
    }

    private static function idDoJson(mixed $json, string $nomeArquivo): string
    {
        $id = Texto::pegar($json, 'id');
        return is_string($id) && $id !== '' ? $id : (string) preg_replace('/\.json$/D', '', $nomeArquivo);
    }

    /**
     * Bundle: [versao, secoes, parciais, baseCss, modelos, nichos, comum, icones, fontes, fotos].
     *
     * @throws \RuntimeException pasta ausente ou JSON inválido
     */
    public static function carregar(string $dir): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new \RuntimeException("Biblioteca não encontrada: {$dir}");
        }
        $secoes = [];
        $dirSecoes = $dir . '/secoes';
        foreach (self::entradas($dirSecoes, static fn (string $n): bool => is_dir($dirSecoes . '/' . $n)) as $tipo) {
            $base = $dirSecoes . '/' . $tipo;
            if (!is_file($base . '/manifest.json')) {
                continue;
            }
            $manifest = self::lerJson($base . '/manifest.json');
            $templates = [];
            foreach (Texto::comoLista(Texto::pegar($manifest, 'opcoes')) as $opcao) {
                $id = Texto::pegar($opcao, 'id');
                if (!is_string($id) || !preg_match('/^[a-z0-9-]+$/D', $id)) {
                    continue;
                }
                if (is_file($base . '/' . $id . '.mustache')) {
                    $templates[$id] = (string) file_get_contents($base . '/' . $id . '.mustache');
                }
            }
            $secoes[$tipo] = ['manifest' => $manifest, 'templates' => $templates, 'css' => self::lerTexto($base . '/estilo.css')];
        }
        $parciais = [];
        $dirParciais = $dir . '/parciais';
        foreach (self::entradas($dirParciais, static fn (string $n): bool => str_ends_with($n, '.mustache')) as $nome) {
            $parciais[substr($nome, 0, -strlen('.mustache'))] = (string) file_get_contents($dirParciais . '/' . $nome);
        }
        $modelos = [];
        $dirModelos = $dir . '/modelos';
        foreach (self::entradas($dirModelos, static fn (string $n): bool => str_ends_with($n, '.json')) as $nome) {
            $json = self::lerJson($dirModelos . '/' . $nome);
            $modelos[self::idDoJson($json, $nome)] = $json;
        }
        $nichos = [];
        $dirNichos = $dir . '/nichos';
        foreach (self::entradas($dirNichos, static fn (string $n): bool => str_ends_with($n, '.json') && $n !== 'comum.json') as $nome) {
            $json = self::lerJson($dirNichos . '/' . $nome);
            $nichos[self::idDoJson($json, $nome)] = $json;
        }
        return [
            'versao' => self::versao($dir),
            'secoes' => $secoes,
            'parciais' => $parciais,
            'baseCss' => self::lerTexto($dir . '/base.css'),
            'modelos' => $modelos,
            'nichos' => $nichos,
            'comum' => self::lerJsonOpcional($dirNichos . '/comum.json'),
            'icones' => self::lerJsonOpcional($dir . '/icones/icones.json'),
            'fontes' => self::lerJsonOpcional($dir . '/fontes/fontes.json'),
            'fotos' => self::lerJsonOpcional($dir . '/fotos/fotos.json'),
        ];
    }

    /** JSON do bundle para a API (UTF-8 sem escapes desnecessários). */
    public static function json(array $bundle): string
    {
        return json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
