<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Fotos de exemplo da biblioteca (biblioteca/fotos/fotos.json): qual foto vai em cada espaço de
 * imagem de um documento, para o modelo já nascer completo. PARIDADE com compartilhado/fotos.mjs
 * (mesma ordem de espaços, mesmos grupos, mesmo resultado).
 */
final class Fotos
{
    public const PREFIXO_EXEMPLO = 'x_';

    /** "f" ou "m" pelo nome: Dra./Sra. → f; Dr./Sr. → m; senão, primeiro nome terminado em "a" → f. */
    public static function generoDoNome(mixed $nome): string
    {
        $n = Texto::normalizar($nome);
        $tem = preg_match('/^(dra|sra|dr|sr|eng)\.?\s+(.*)$/s', $n, $m) === 1;
        if ($tem && ($m[1] === 'dra' || $m[1] === 'sra')) {
            return 'f';
        }
        if ($tem && ($m[1] === 'dr' || $m[1] === 'sr')) {
            return 'm';
        }
        return preg_match('/^[a-z]+a(?![a-z])/', $tem ? $m[2] : $n) === 1 ? 'f' : 'm';
    }

    /** @return array<string, string> tipo → opção (a primeira de cada tipo) */
    private static function opcoesDoDoc(mixed $doc): array
    {
        $r = [];
        foreach (Texto::comoLista(Texto::pegar(Texto::comoMapa($doc), 'secoes')) as $s) {
            $tipo = Texto::textoDe(Texto::pegar(Texto::comoMapa($s), 'tipo'));
            if ($tipo !== '' && !array_key_exists($tipo, $r)) {
                $r[$tipo] = Texto::textoDe(Texto::pegar(Texto::comoMapa($s), 'opcao'));
            }
        }
        return $r;
    }

    /** @return list<array{chave: string, lista: ?string, id: ?string}> */
    private static function espacos(mixed $doc, mixed $lib): array
    {
        $registro = Documento::registroCampos($lib);
        $secoesLib = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'secoes'));
        $ordemTipos = [];
        foreach (Texto::comoLista(Texto::pegar(Texto::comoMapa($doc), 'secoes')) as $s) {
            $tipo = Texto::textoDe(Texto::pegar(Texto::comoMapa($s), 'tipo'));
            if ($tipo !== '' && !in_array($tipo, $ordemTipos, true)) {
                $ordemTipos[] = $tipo;
            }
        }
        foreach (array_keys($secoesLib) as $tipo) {
            if (!in_array((string) $tipo, $ordemTipos, true)) {
                $ordemTipos[] = (string) $tipo;
            }
        }
        $lista = [];
        $vistos = [];
        foreach ($ordemTipos as $tipo) {
            foreach ($registro as $chave => $def) {
                $chave = (string) $chave;
                if (Texto::pegar($def, 'dono') !== $tipo || Texto::pegar($def, 'tipo') !== 'imagem') {
                    continue;
                }
                $partes = explode('.', $chave);
                if (count($partes) === 2) {
                    if (!isset($vistos[$chave])) {
                        $vistos[$chave] = true;
                        $lista[] = ['chave' => $chave, 'lista' => null, 'id' => null];
                    }
                    continue;
                }
                foreach (Textos::itensLista($doc, $lib, $partes[0]) as $id) {
                    $k = $partes[0] . '.' . $id . '.' . $partes[2];
                    if (!isset($vistos[$k])) {
                        $vistos[$k] = true;
                        $lista[] = ['chave' => $k, 'lista' => $partes[0], 'id' => (string) $id];
                    }
                }
            }
        }
        return $lista;
    }

    /**
     * Mapa chave → id da foto (sem prefixo) para os espaços sem imagem do documento.
     *
     * @return array<string, string>
     */
    public static function imagensDeExemplo(mixed $doc, mixed $lib): array
    {
        $fotos = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'fotos'));
        $catalogo = Texto::comoMapa(Texto::pegar($fotos, 'fotos'));
        $pools = Texto::comoMapa(Texto::pegar(
            Texto::comoMapa(Texto::pegar($fotos, 'nichos')),
            Texto::textoDe(Texto::pegar(Texto::comoMapa($doc), 'nicho')),
        ));
        $grupo = static fn (string $nome): array => array_values(array_filter(
            Texto::comoLista(Texto::pegar($pools, $nome)),
            static fn (mixed $id): bool => is_string($id) && array_key_exists($id, $catalogo),
        ));
        $cena = $grupo('cena');
        if ($cena === []) {
            return [];
        }
        $gente = ['f' => $grupo('gente_f'), 'm' => $grupo('gente_m')];
        $imagens = Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'imagens'));
        $opcoes = self::opcoesDoDoc($doc);
        $ctx = Textos::contextoVariaveis($doc, $lib);
        $equipe = Textos::itensLista($doc, $lib, 'equipe');

        $usados = [];
        $contagem = ['cena' => 0, 'f' => 0, 'm' => 0];
        $escolher = static function (array $pool, string $nome) use (&$usados, &$contagem): ?string {
            if ($pool === []) {
                return null;
            }
            foreach ($pool as $id) {
                if (!isset($usados[$id])) {
                    $usados[$id] = true;
                    return $id;
                }
            }
            $id = $pool[$contagem[$nome] % count($pool)];
            $contagem[$nome]++;
            return $id;
        };

        $lista = self::espacos($doc, $lib);
        $daEquipe = [];
        foreach ($lista as $e) {
            if ($e['lista'] !== 'equipe') {
                continue;
            }
            $genero = self::generoDoNome(Textos::textoEfetivo($doc, $lib, 'equipe.' . $e['id'] . '.n', $ctx));
            $daEquipe[$e['chave']] = $gente[$genero] !== [] ? $escolher($gente[$genero], $genero) : $escolher($cena, 'cena');
        }
        $fotoEquipe = static fn (int $i): ?string => count($equipe) > $i ? ($daEquipe['equipe.' . $equipe[$i] . '.f'] ?? null) : null;

        $r = [];
        foreach ($lista as $e) {
            if ($e['lista'] === 'equipe') {
                $id = $daEquipe[$e['chave']];
            } elseif ($e['chave'] === 'hero.img' && ($opcoes['hero'] ?? null) === 'retrato' && $fotoEquipe(0) !== null) {
                $id = $fotoEquipe(0);
            } elseif ($e['chave'] === 'cta.img' && ($opcoes['cta'] ?? null) === 'pessoa' && ($fotoEquipe(1) ?? $fotoEquipe(0)) !== null) {
                $id = $fotoEquipe(1) ?? $fotoEquipe(0);
            } elseif ($e['chave'] === 'dep.img' && $gente['f'] !== []) {
                $id = $escolher($gente['f'], 'f');
            } else {
                $id = $escolher($cena, 'cena');
            }
            if ($id !== null && !is_string(Texto::pegar($imagens, $e['chave']))) {
                $r[$e['chave']] = $id;
            }
        }
        return $r;
    }
}
