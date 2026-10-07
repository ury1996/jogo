<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Ícones automáticos (PDF §8.2) e SVG por acabamento (contrato §3.7, §5.3).
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/icones.mjs.
 */
final class Icones
{
    public const PESOS = ['classico' => 'fino', 'moderno' => 'duotone', 'direto' => 'preenchido'];
    public const ICONE_RESERVA = 'circulo';

    private static function listaDeIcones(mixed $icones): array
    {
        if (is_array($icones) && array_is_list($icones)) {
            return $icones;
        }
        return Texto::comoLista(Texto::pegar(Texto::comoMapa($icones), 'icones'));
    }

    private static function ehAlnum(string $c): bool
    {
        return $c !== '' && preg_match('/^[a-z0-9]$/D', $c) === 1;
    }

    /** `$palavra` aparece em `$texto` com limites [^a-z0-9] (ou início/fim) dos dois lados? */
    private static function contemPalavraInteira(string $texto, string $palavra): bool
    {
        $i = strpos($texto, $palavra);
        while ($i !== false) {
            $antes = $i === 0 ? '' : $texto[$i - 1];
            $fim = $i + strlen($palavra);
            $depois = $fim < strlen($texto) ? $texto[$fim] : '';
            if (!self::ehAlnum($antes) && !self::ehAlnum($depois)) {
                return true;
            }
            $i = strpos($texto, $palavra, $i + 1);
        }
        return false;
    }

    /**
     * Algoritmo do PDF §8.2: palavras com até 3 letras casam só inteiras; as demais por
     * "contém"; a mais longa vence; empate = ordem do arquivo. Sem casamento → null.
     * `$icones` = o icones.json inteiro ou só a lista.
     */
    public static function escolherIcone(mixed $titulo, mixed $icones): ?string
    {
        $texto = Texto::normalizar($titulo);
        if ($texto === '') {
            return null;
        }
        $melhor = null;
        $pontos = 0;
        foreach (self::listaDeIcones($icones) as $icone) {
            $id = Texto::pegar($icone, 'id');
            if (!is_string($id)) {
                continue;
            }
            foreach (Texto::comoLista(Texto::pegar($icone, 'palavras')) as $p) {
                // Atalho: palavra já normalizada (o normal no icones.json) dispensa normalizar().
                $palavra = is_string($p) && preg_match('/^[a-z0-9]+( [a-z0-9]+)*$/D', $p) ? $p : Texto::normalizar($p);
                $tamanho = mb_strlen($palavra, 'UTF-8');
                if ($tamanho === 0 || $tamanho <= $pontos) {
                    continue;
                }
                $casou = $tamanho <= 3 ? self::contemPalavraInteira($texto, $palavra) : str_contains($texto, $palavra);
                if ($casou) {
                    $melhor = $id;
                    $pontos = $tamanho;
                }
            }
        }
        return $melhor;
    }

    /** Definição do ícone (lista principal ou utilitários) ou null. */
    public static function acharIcone(mixed $lib, mixed $id): ?array
    {
        if (!is_string($id) || $id === '') {
            return null;
        }
        $icones = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib), 'icones'));
        foreach (Texto::comoLista(Texto::pegar($icones, 'icones')) as $icone) {
            if (Texto::ehMapa($icone) && Texto::pegar($icone, 'id') === $id) {
                return $icone;
            }
        }
        $util = Texto::pegar(Texto::comoMapa(Texto::pegar($icones, 'utilitarios')), $id);
        return Texto::ehMapa($util) ? $util : null;
    }

    /** Campo de texto de onde a lista tira o ícone automático (manifesto: "automatico"; padrão "t"). */
    public static function campoAutomatico(mixed $lib, string $lista): string
    {
        $campos = Texto::comoMapa(Texto::pegar(Texto::comoMapa(Texto::pegar(Documento::registroListas($lib), $lista)), 'campos'));
        foreach ($campos as $def) {
            $d = Texto::comoMapa($def);
            $auto = Texto::pegar($d, 'automatico');
            if (Texto::pegar($d, 'tipo') === 'icone' && is_string($auto) && $auto !== '') {
                return $auto;
            }
        }
        return 't';
    }

    /**
     * Ícone do item: manual (doc.icones["lista.id"], se existir na biblioteca) →
     * automático pelo texto → nicho.iconesPadrao[lista][posição % n] → "circulo".
     * `$posicao` é a posição do item na lista efetiva, começando em 0.
     */
    public static function iconeDoItem(mixed $doc, mixed $lib, string $lista, string $id, int $posicao, ?array $contexto = null): string
    {
        $manual = Texto::pegar(Texto::comoMapa(Texto::pegar(Texto::comoMapa($doc), 'icones')), $lista . '.' . $id);
        if (is_string($manual) && self::acharIcone($lib, $manual) !== null) {
            return $manual;
        }
        $titulo = Textos::textoEfetivo($doc, $lib, $lista . '.' . $id . '.' . self::campoAutomatico($lib, $lista), $contexto);
        $automatico = self::escolherIcone($titulo, Texto::pegar(Texto::comoMapa($lib), 'icones'));
        if ($automatico !== null) {
            return $automatico;
        }
        $padroes = Texto::comoLista(Texto::pegar(Texto::comoMapa(Texto::pegar(Documento::nichoDe($doc, $lib), 'iconesPadrao')), $lista));
        if ($padroes !== []) {
            $n = count($padroes);
            $escolhido = $padroes[(($posicao % $n) + $n) % $n];
            if (is_string($escolhido) && self::acharIcone($lib, $escolhido) !== null) {
                return $escolhido;
            }
        }
        return self::ICONE_RESERVA;
    }

    /** SVG do ícone no peso do acabamento (classico→fino, moderno→duotone, direto→preenchido). */
    public static function svg(mixed $lib, mixed $id, mixed $acabamento): string
    {
        $def = self::acharIcone($lib, $id);
        return $def === null ? '' : self::svgDaDefinicao($def, $acabamento);
    }

    /** SVG de uma definição de ícone no peso do acabamento (peso ausente → o que existir). */
    public static function svgDaDefinicao(mixed $def, mixed $acabamento): string
    {
        $svgs = Texto::comoMapa(Texto::pegar(Texto::comoMapa($def), 'svg'));
        $peso = is_string($acabamento) && array_key_exists($acabamento, self::PESOS) ? self::PESOS[$acabamento] : 'fino';
        foreach ([$peso, 'fino', 'duotone', 'preenchido'] as $p) {
            $s = Texto::pegar($svgs, $p);
            if (is_string($s) && $s !== '') {
                return $s;
            }
        }
        return '';
    }
}
