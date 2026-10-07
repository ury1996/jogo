<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Resolução de textos, variáveis, retokenização e listas (contrato §2.1–§2.3).
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/textos.mjs.
 */
final class Textos
{
    /** Valores das variáveis {nome} {cidade} {segmento} (vazio → exemplo do nicho). */
    public static function contextoVariaveis(mixed $doc, mixed $lib): array
    {
        $dados = Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'dados'));
        $exemplo = Texto::comoMapa(Texto::pegar(Documento::nichoDe($doc, $lib), 'exemplo'));
        $nome = Texto::colapsarEspacos(Texto::pegar($dados, 'nome'));
        $cidade = Texto::colapsarEspacos(Texto::pegar($dados, 'cidade'));
        return [
            'nome' => $nome !== '' ? $nome : Texto::colapsarEspacos(Texto::pegar($exemplo, 'nome')),
            'cidade' => $cidade !== '' ? $cidade : Texto::colapsarEspacos(Texto::pegar($exemplo, 'cidade')),
            'segmento' => Texto::textoDe(Texto::pegar(Texto::comoMapa(Documento::especialidade($doc, $lib)), 'segmento')),
        ];
    }

    /** Troca {nome} {cidade} {segmento} numa única passada; outras chaves ficam como estão. */
    public static function substituirVariaveis(mixed $txt, mixed $contexto): string
    {
        $ctx = Texto::comoMapa($contexto);
        return preg_replace_callback(
            '/\{(nome|cidade|segmento)\}/',
            static fn (array $m): string => Texto::textoDe(Texto::pegar($ctx, $m[1])),
            Texto::textoDe($txt),
        ) ?? Texto::textoDe($txt);
    }

    /** Texto padrão bruto (sem variáveis trocadas): nicho → comum → novoItem; ou "". */
    public static function textoPadrao(mixed $doc, mixed $lib, string $chave): string
    {
        $nicho = Documento::nichoDe($doc, $lib);
        $comum = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'comum'));
        $doNicho = Texto::textoDaChave(Texto::comoMapa(Texto::pegar($nicho, 'textos')), $chave);
        if ($doNicho !== null) {
            return $doNicho;
        }
        $doComum = Texto::textoDaChave(Texto::comoMapa(Texto::pegar($comum, 'textos')), $chave);
        if ($doComum !== null) {
            return $doComum;
        }
        $partes = explode('.', $chave);
        if (count($partes) === 3) {
            $novoItem = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar($comum, 'novoItem')), $partes[0]));
            $novo = Texto::textoDaChave($novoItem, $partes[2]);
            if ($novo !== null) {
                return $novo;
            }
        }
        return '';
    }

    /** Texto efetivo (§2.3): editado ?? nicho ?? comum ?? novoItem ?? "", com variáveis trocadas. */
    public static function textoEfetivo(mixed $doc, mixed $lib, string $chave, ?array $contexto = null): string
    {
        $editado = Texto::textoDaChave(Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'textos')), $chave);
        $bruto = $editado ?? self::textoPadrao($doc, $lib, $chave);
        return self::substituirVariaveis($bruto, $contexto ?? self::contextoVariaveis($doc, $lib));
    }

    /** O texto da chave é o padrão (não foi editado)? */
    public static function ehPadrao(mixed $doc, string $chave): bool
    {
        return Texto::textoDaChave(Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'textos')), $chave) === null;
    }

    /**
     * [M4] Ocorrências exatas (sensível a maiúsculas) do nome e da cidade (≥ 3 caracteres)
     * viram {nome} / {cidade}, numa passada única casando a mais longa primeiro (strtr).
     * Só o editor precisa; existe aqui para os testes de paridade.
     */
    public static function retokenizar(mixed $txt, mixed $dados): string
    {
        $d = Texto::comoMapa($dados);
        $nome = Texto::textoDe(Texto::pegar($d, 'nome'));
        $cidade = Texto::textoDe(Texto::pegar($d, 'cidade'));
        $pares = [];
        if (mb_strlen($nome, 'UTF-8') >= 3) {
            $pares[$nome] = '{nome}';
        }
        if (mb_strlen($cidade, 'UTF-8') >= 3 && $cidade !== $nome) {
            $pares[$cidade] = '{cidade}';
        }
        $texto = Texto::textoDe($txt);
        return $pares === [] ? $texto : strtr($texto, $pares);
    }

    /**
     * Lista efetiva de ids: doc.listas ?? nicho.listas ?? comum.listas ?? []. Sem cortar no máximo.
     *
     * @return list<string>
     */
    public static function itensLista(mixed $doc, mixed $lib, string $lista): array
    {
        $fontes = [
            Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'listas')),
            Texto::comoMapa(Texto::pegar(Documento::nichoDe($doc, $lib), 'listas')),
            Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'comum')), 'listas')),
        ];
        foreach ($fontes as $fonte) {
            $ids = Texto::pegar($fonte, $lista);
            if (is_array($ids) && array_is_list($ids)) {
                return Documento::idsValidos($ids);
            }
        }
        return [];
    }

    /**
     * [mín, máx] de itens da lista pelo manifesto (sem manifesto: [0, PHP_INT_MAX]).
     *
     * @return array{0: int, 1: int}
     */
    public static function limitesLista(mixed $lib, string $lista): array
    {
        $repete = Texto::comoLista(Texto::pegar(Texto::comoMapa(Texto::pegar(Documento::registroListas($lib), $lista)), 'repete'));
        $a = Texto::inteiroDe($repete[0] ?? null);
        $b = Texto::inteiroDe($repete[1] ?? null);
        $min = $a !== null && $a >= 0 ? $a : 0;
        $max = $b !== null && $b >= $min ? $b : PHP_INT_MAX;
        return [$min, $max];
    }

    /** Id de item novo: "n" + 4 caracteres base36 aleatórios, diferente dos existentes. */
    public static function novoIdItem(array $existentes = []): string
    {
        $base36 = '0123456789abcdefghijklmnopqrstuvwxyz';
        do {
            $id = 'n';
            for ($i = 0; $i < 4; $i++) {
                $id .= $base36[random_int(0, 35)];
            }
        } while (in_array($id, $existentes, true));
        return $id;
    }

    /** Mapa chave → texto efetivo de todos os textos que ainda são padrão (para `versoes.resolvidos` [M5]). */
    public static function textosPadraoEfetivos(mixed $doc, mixed $lib): array
    {
        $contexto = self::contextoVariaveis($doc, $lib);
        $resultado = [];
        $ehTexto = static fn (array $def): bool => in_array(Texto::pegar($def, 'tipo'), ['texto', 'texto-longo'], true);
        foreach (Documento::registroCampos($lib) as $chave => $def) {
            $chave = (string) $chave;
            if (str_contains($chave, '*') || !$ehTexto($def) || !self::ehPadrao($doc, $chave)) {
                continue;
            }
            $resultado[$chave] = self::textoEfetivo($doc, $lib, $chave, $contexto);
        }
        foreach (Documento::registroListas($lib) as $lista => $ldef) {
            $campos = [];
            foreach (Texto::comoMapa(Texto::pegar($ldef, 'campos')) as $campo => $def) {
                if ($ehTexto(Texto::comoMapa($def))) {
                    $campos[] = (string) $campo;
                }
            }
            foreach (self::itensLista($doc, $lib, (string) $lista) as $id) {
                foreach ($campos as $campo) {
                    $chave = $lista . '.' . $id . '.' . $campo;
                    if (self::ehPadrao($doc, $chave)) {
                        $resultado[$chave] = self::textoEfetivo($doc, $lib, $chave, $contexto);
                    }
                }
            }
        }
        return $resultado;
    }

    // -----------------------------------------------------------------------
    // Edição (as mesmas do editor; devolvem um documento novo)

    /**
     * Aplica a edição de um texto: apagado por completo → volta ao padrão (restaurado);
     * senão retokeniza [M4] e, se ficar igual ao padrão (como token ou já com as variáveis
     * trocadas — ex.: campo só clicado), remove a chave (volta a propagar).
     *
     * @return array{doc: array, restaurado: bool}
     */
    public static function aplicarEdicaoTexto(array $doc, mixed $lib, string $chave, mixed $texto): array
    {
        $textos = Texto::comoMapa(Texto::pegar($doc, 'textos'));
        $limpo = Texto::aparar($texto);
        $restaurado = false;
        if (Texto::colapsarEspacos($limpo) === '') {
            unset($textos[$chave]);
            $restaurado = true;
        } else {
            $contexto = self::contextoVariaveis($doc, $lib);
            $tokenizado = self::retokenizar($limpo, $contexto);
            $padrao = self::textoPadrao($doc, $lib, $chave);
            // Igual ao padrão (como token ou como o usuário vê) → volta a propagar.
            if ($tokenizado === $padrao || self::substituirVariaveis($tokenizado, $contexto) === self::substituirVariaveis($padrao, $contexto)) {
                unset($textos[$chave]);
            } else {
                $textos[$chave] = $tokenizado;
            }
        }
        $doc['textos'] = $textos;
        return ['doc' => $doc, 'restaurado' => $restaurado];
    }

    /**
     * Adiciona um item novo após `$aposId` (ou no fim). Respeita o máximo.
     *
     * @return array{doc: array, id: ?string}
     */
    public static function adicionarItem(array $doc, mixed $lib, string $lista, ?string $aposId = null, ?string $id = null): array
    {
        $ids = self::itensLista($doc, $lib, $lista);
        [, $max] = self::limitesLista($lib, $lista);
        if (count($ids) >= $max) {
            return ['doc' => $doc, 'id' => null];
        }
        $novoId = $id !== null && preg_match(Documento::RE_ID_ITEM, $id) && !in_array($id, $ids, true) ? $id : self::novoIdItem($ids);
        $pos = $aposId !== null && in_array($aposId, $ids, true) ? (int) array_search($aposId, $ids, true) + 1 : count($ids);
        array_splice($ids, $pos, 0, [$novoId]);
        $listas = Texto::comoMapa(Texto::pegar($doc, 'listas'));
        $listas[$lista] = $ids;
        $doc['listas'] = $listas;
        return ['doc' => $doc, 'id' => $novoId];
    }

    /**
     * Remove o item (respeita o mínimo). A ordem passa a ser explícita em doc.listas, então
     * nenhum outro item "ressuscita" [M2]; textos, imagens e ícone do item saem do documento.
     */
    public static function removerItem(array $doc, mixed $lib, string $lista, string $id): array
    {
        $ids = self::itensLista($doc, $lib, $lista);
        [$min] = self::limitesLista($lib, $lista);
        if (!in_array($id, $ids, true) || count($ids) <= $min) {
            return $doc;
        }
        $listas = Texto::comoMapa(Texto::pegar($doc, 'listas'));
        $listas[$lista] = array_values(array_filter($ids, static fn (string $i): bool => $i !== $id));
        $doc['listas'] = $listas;
        $prefixo = $lista . '.' . $id . '.';
        foreach (['textos', 'imagens'] as $mapa) {
            $m = Texto::comoMapa(Texto::pegar($doc, $mapa));
            foreach (array_keys($m) as $k) {
                if (str_starts_with((string) $k, $prefixo)) {
                    unset($m[$k]);
                }
            }
            $doc[$mapa] = $m;
        }
        $icones = Texto::comoMapa(Texto::pegar($doc, 'icones'));
        unset($icones[$lista . '.' . $id]);
        $doc['icones'] = $icones;
        return $doc;
    }

    /** Move o item `$delta` posições (−1 sobe, +1 desce). */
    public static function moverItem(array $doc, mixed $lib, string $lista, string $id, int $delta): array
    {
        $ids = self::itensLista($doc, $lib, $lista);
        $de = array_search($id, $ids, true);
        if ($de === false) {
            return $doc;
        }
        $para = $de + $delta;
        if ($para < 0 || $para >= count($ids) || $para === $de) {
            return $doc;
        }
        array_splice($ids, $de, 1);
        array_splice($ids, $para, 0, [$id]);
        $listas = Texto::comoMapa(Texto::pegar($doc, 'listas'));
        $listas[$lista] = $ids;
        $doc['listas'] = $listas;
        return $doc;
    }
}
