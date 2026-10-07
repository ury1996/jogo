<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Funções de texto e utilitários de tipo compartilhados.
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/texto.mjs (contrato §5.1).
 */
final class Texto
{
    /** Mapa explícito de acentos (não depende de Normalizer/intl). */
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y', 'Ÿ' => 'Y',
    ];

    /** Classe explícita de espaços (= WhiteSpace + LineTerminator do JS; \s varia entre motores). */
    public const CLASSE_ESPACOS = '\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const ESCAPES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;'];

    /** Valor como texto: strings como estão; inteiros viram texto; o resto vira "". */
    public static function textoDe(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        $inteiro = self::inteiroDe($v);
        return $inteiro !== null ? (string) $inteiro : '';
    }

    /** Inteiro (ou float inteiro, como o JSON do JS) → int; senão null. */
    public static function inteiroDe(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 9007199254740992.0) {
            return (int) $v;
        }
        return null;
    }

    /** É um mapa (array associativo; [] também conta, pois {} vira [] no PHP)? */
    public static function ehMapa(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    /** Mapa ou []. */
    public static function comoMapa(mixed $v): array
    {
        return self::ehMapa($v) ? $v : [];
    }

    /** Lista (array com chaves 0..n-1) ou []. */
    public static function comoLista(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    /** Chave própria de um mapa (listas não contam). */
    public static function temChave(mixed $mapa, string $chave): bool
    {
        return self::ehMapa($mapa) && array_key_exists($chave, $mapa);
    }

    /** Valor de uma chave do mapa, ou null. */
    public static function pegar(mixed $mapa, string $chave): mixed
    {
        return self::temChave($mapa, $chave) ? $mapa[$chave] : null;
    }

    /** Valor de texto de uma chave, ou null se ausente/não-texto. */
    public static function textoDaChave(mixed $mapa, string $chave): ?string
    {
        $v = self::pegar($mapa, $chave);
        return is_string($v) ? $v : null;
    }

    public static function semAcentos(mixed $s): string
    {
        $t = strtr(self::textoDe($s), self::ACENTOS);
        return preg_replace('/[\x{0300}-\x{036F}]/u', '', $t) ?? $t;
    }

    /** Colapsa sequências de espaços em um e apara as pontas. */
    public static function colapsarEspacos(mixed $s): string
    {
        $t = self::textoDe($s);
        $t = preg_replace('/[' . self::CLASSE_ESPACOS . ']+/u', ' ', $t) ?? $t;
        return trim($t, ' ');
    }

    /** Apara espaços (classe explícita) só nas pontas, sem colapsar o meio. */
    public static function aparar(mixed $s): string
    {
        $t = self::textoDe($s);
        $c = self::CLASSE_ESPACOS;
        return preg_replace('/^[' . $c . ']+|[' . $c . ']+$/uD', '', $t) ?? $t;
    }

    /** minúsculas + sem acento + espaços colapsados. */
    public static function normalizar(mixed $s): string
    {
        return self::colapsarEspacos(mb_strtolower(self::semAcentos($s), 'UTF-8'));
    }

    /**
     * Escape HTML: só & < > " ' (nada mais). Para ficar igual ao mustache.js, booleanos
     * viram "true"/"false", listas viram itens separados por vírgula e mapas "[object Object]".
     */
    public static function escapeHtml(mixed $v): string
    {
        return strtr(self::comoTextoJs($v), self::ESCAPES);
    }

    /** String(v) do JavaScript (para valores vindos de JSON). */
    public static function comoTextoJs(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
                return (string) (int) $v;
            }
            return (string) json_encode($v);
        }
        if (is_array($v)) {
            if (!array_is_list($v) && $v !== []) {
                return '[object Object]';
            }
            return implode(',', array_map(static fn ($x) => self::comoTextoJs($x), $v));
        }
        return '';
    }

    /** Arredondamento da especificação: floor(x + 0.5) (não usar round()). */
    public static function arred(float|int $x): int
    {
        return (int) floor($x + 0.5);
    }

    /** Comportamento de encodeURIComponent. */
    public static function codificarUri(mixed $s): string
    {
        return strtr(rawurlencode(self::textoDe($s)), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
