<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

use Rankly\Preparo\Texto;

/**
 * Revisor da copy escrita pela IA: aponta, campo a campo, o que um redator experiente mandaria
 * reescrever. O GeradorConteudo devolve só esses campos para a IA corrigir (uma rodada), e as
 * travas de sempre (limite, termos proibidos) continuam valendo depois.
 *
 * Pontos de atenção:
 *  - passou do limite de caracteres (seria cortado) ou texto longo terminando no meio da frase;
 *  - termo proibido pelo conselho da profissão;
 *  - clichê ou alegação vaga que a descrição não sustenta ("excelência", "de ponta", "referência"…);
 *  - a mesma expressão repetida em quatro campos ou mais ("atendimento humanizado" em cinco lugares);
 *  - a cidade enfiada em campos demais (SEO forçado);
 *  - título principal genérico ("Dentista em Jundiaí") ou Com Todas As Iniciais Maiúsculas;
 *  - listas com itens de menos ou todos começando igual; título e descrição para o Google fora do tamanho.
 */
final class RevisorCopy
{
    /** Expressões vazias ou alegações que só podem aparecer se a própria descrição disser. */
    public const CLICHES = [
        'excelência', 'de excelência', 'de ponta', 'tecnologia de ponta', 'referência', 'líder', 'lider de mercado',
        'alto padrão', 'diferenciado', 'atendimento diferenciado', 'soluções completas', 'solução completa',
        'soluções personalizadas', 'qualidade e compromisso', 'compromisso com a qualidade', 'comprometidos',
        'altamente qualificados', 'altamente qualificada', 'renomados', 'renomada', 'inovador', 'inovadora',
        'mais modernos', 'mais moderna', 'equipamentos modernos', 'estrutura completa', 'experiência única',
        'tudo o que você precisa', 'venha conhecer', 'não perca tempo', 'clique aqui', 'somos uma empresa',
        'nossa missão', 'paixão', 'o melhor', 'os melhores', 'a melhor', 'as melhores', 'incomparável', 'imbatível',
    ];

    /** Campos em que a cidade ajuda o SEO; nos outros ela é opcional (e sobra se aparecer demais). */
    private const CIDADE_BEM_VINDA = ['hero.titulo', 'hero.texto', 'serv.titulo', 'serv.texto', 'sobre.titulo', 'sobre.texto',
        'rodape.sobre', 'contato.titulo', 'contato.texto', 'cta.texto'];

    /** A mesma expressão pode aparecer em até tantos campos (repetir um termo-chave é natural; cinco vezes, não). */
    private const REPETICOES_OK = 3;

    /** Mais que isso de campos fora da lista acima citando a cidade → SEO forçado. */
    private const CIDADE_EXTRAS = 2;

    /** Palavras que não contam na repetição (já normalizadas: sem acento, minúsculas). */
    private const PALAVRAS_VAZIAS = ['a', 'o', 'as', 'os', 'e', 'ou', 'de', 'da', 'do', 'das', 'dos', 'em', 'na', 'no', 'nas', 'nos',
        'um', 'uma', 'uns', 'umas', 'para', 'pra', 'por', 'com', 'sem', 'que', 'se', 'sua', 'seu', 'suas', 'seus', 'nossa', 'nosso',
        'nossas', 'nossos', 'voce', 'mais', 'muito', 'como', 'ao', 'aos', 'cada', 'sobre', 'entre', 'ate', 'ja', 'tambem', 'quem',
        'isso', 'esta', 'este', 'essa', 'esse', 'todo', 'toda', 'todos', 'todas', 'ser', 'ter', 'tem', 'sao', 'aqui', 'sempre'];

    /**
     * @param array $resposta  rascunho da IA (textos, listas, seo)
     * @param array $escalares chave => definição (max, rotulo, tipo)
     * @param array $listas    nome => {campos, min, max, rotulo}
     * @param array $ctx       nome, cidade, proibidos, evitar
     * @return array<string, list<string>> campo ("hero.titulo", "lista:serv", "seo") => problemas
     */
    public static function avaliar(array $resposta, array $escalares, array $listas, array $ctx, string $descricao, bool $comSeo): array
    {
        $problemas = [];
        $anota = static function (string $campo, string $msg) use (&$problemas): void {
            if (!in_array($msg, $problemas[$campo] ?? [], true)) {
                $problemas[$campo][] = $msg;
            }
        };
        $descNorm = ' ' . self::normalizar($descricao) . ' ';
        $vagos = array_values(array_unique(array_merge(self::CLICHES, array_map('strval', (array) ($ctx['evitar'] ?? [])))));

        $textos = Texto::comoMapa($resposta['textos'] ?? []);
        $todos = []; // campo => texto, na ordem, para repetição e cidade
        foreach ($escalares as $chave => $def) {
            $t = self::texto($textos[$chave] ?? null);
            $max = (int) ($def['max'] ?? 200);
            if ($t === '') {
                if ($chave !== 'hero.selo') {
                    $anota($chave, 'ficou vazio');
                }
                continue;
            }
            $todos[$chave] = $t;
            self::conferirTexto($chave, $t, $max, $ctx, $vagos, $descNorm, $anota);
            $campo = (string) ($def['campo'] ?? '');
            if ($max >= 150 && mb_strlen($t, 'UTF-8') > 60 && !preg_match('/[.!?…]$/u', $t)) {
                $anota($chave, 'termine com uma frase completa e ponto final');
            }
            if (in_array($campo, ['titulo', 'eyebrow'], true) && self::iniciaisMaiusculas($t)) {
                $anota($chave, 'escreva com só a primeira letra maiúscula (exceto nomes próprios)');
            }
        }
        if (isset($todos['hero.titulo']) && count(self::palavras($todos['hero.titulo'])) <= 4) {
            $anota('hero.titulo', 'título genérico demais: diga o resultado que o cliente busca, além do serviço e da cidade');
        }

        $respListas = Texto::comoMapa($resposta['listas'] ?? []);
        $nomesServicos = [];
        foreach ($listas as $nome => $l) {
            $campo = 'lista:' . $nome;
            $validos = 0;
            $inicios = [];
            foreach (Texto::comoLista($respListas[$nome] ?? []) as $i => $item) {
                $item = Texto::comoMapa($item);
                $completo = true;
                foreach ($l['campos'] as $c => $def) {
                    $t = self::texto($item[$c] ?? null);
                    if ($t === '') {
                        $completo = false;
                        continue;
                    }
                    $todos[$nome . '.' . ($i + 1) . '.' . $c] = $t;
                    self::conferirTexto($campo, $t, (int) ($def['max'] ?? 160), $ctx, $vagos, $descNorm, $anota);
                    if ($nome === 'serv' && $c === 't') {
                        $nomesServicos[] = $t;
                    }
                    if ($c === array_key_first($l['campos'])) {
                        $inicios[] = self::palavras($t)[0] ?? '';
                    }
                }
                $validos += $completo ? 1 : 0;
            }
            if ($validos < $l['min']) {
                $anota($campo, 'precisa de ' . ($l['min'] === $l['max'] ? $l['min'] : 'no mínimo ' . $l['min']) . ' itens completos (todos os campos preenchidos)');
            }
            $contagem = array_count_values(array_filter($inicios));
            if (count($inicios) >= 3 && $contagem !== [] && max($contagem) >= 3) {
                $anota($campo, 'varie o começo dos itens (vários começam com "' . array_search(max($contagem), $contagem, true) . '")');
            }
        }

        // A mesma expressão (duas palavras de conteúdo seguidas) em mais de REPETICOES_OK campos.
        $ignorar = self::palavras($ctx['nome'] . ' ' . $ctx['cidade'] . ' ' . implode(' ', $nomesServicos));
        $onde = [];
        foreach ($todos as $chave => $t) {
            $p = array_values(array_filter(self::palavras($t), static fn (string $w): bool => !in_array($w, self::PALAVRAS_VAZIAS, true)));
            for ($i = 0; $i + 1 < count($p); $i++) {
                if (in_array($p[$i], $ignorar, true) && in_array($p[$i + 1], $ignorar, true)) {
                    continue;
                }
                $onde[$p[$i] . ' ' . $p[$i + 1]][$chave] = true;
            }
        }
        foreach ($onde as $expressao => $chaves) {
            $chaves = array_keys($chaves);
            if (count($chaves) <= self::REPETICOES_OK) {
                continue;
            }
            foreach (array_slice($chaves, self::REPETICOES_OK) as $chave) {
                $anota(self::grupoDe($chave, $listas), 'repete "' . $expressao . '" (já está em ' . implode(', ', array_slice($chaves, 0, self::REPETICOES_OK))
                    . '): reescreva a frase com outro benefício ou fato, ou corte a ideia; não troque só por um sinônimo');
            }
        }

        // Cidade em campos demais.
        $cidade = self::normalizar($ctx['cidade']);
        if ($cidade !== '') {
            $extras = [];
            foreach ($todos as $chave => $t) {
                $n = ' ' . self::normalizar($t) . ' ';
                if ((str_contains($n, ' ' . $cidade . ' ') || str_contains($t, '{cidade}')) && !in_array($chave, self::CIDADE_BEM_VINDA, true)) {
                    $extras[] = $chave;
                }
            }
            foreach (array_slice($extras, self::CIDADE_EXTRAS) as $chave) {
                $anota(self::grupoDe($chave, $listas), 'tire a cidade daqui: ela já aparece onde ajuda o Google e, repetida, soa forçada');
            }
        }

        if ($comSeo) {
            $seo = Texto::comoMapa($resposta['seo'] ?? []);
            $titulo = self::texto($seo['titulo'] ?? null);
            $desc = self::texto($seo['descricao'] ?? null);
            if ($titulo === '' || mb_strlen($titulo, 'UTF-8') > 60) {
                $anota('seo', 'o título para o Google deve ter até 60 caracteres');
            }
            $n = mb_strlen($desc, 'UTF-8');
            if ($n < 120 || $n > 158) {
                $anota('seo', 'a descrição para o Google deve ter de 140 a 155 caracteres (tem ' . $n . ')');
            }
            foreach ([$titulo, $desc] as $t) {
                if ($t !== '' && ($termo = GeradorConteudo::termoProibido($t, $ctx['proibidos'])) !== null) {
                    $anota('seo', 'usa o termo proibido "' . $termo . '"');
                }
            }
        }
        return $problemas;
    }

    /**
     * Só os problemas graves (o filtro descartaria ou cortaria o campo): usados para não trocar um
     * rascunho aproveitável por uma correção pior.
     *
     * @param array<string, list<string>> $problemas
     * @return array<string, list<string>>
     */
    public static function graves(array $problemas): array
    {
        $graves = [];
        foreach ($problemas as $campo => $lista) {
            $g = array_values(array_filter($lista, static fn (string $m): bool => (bool) preg_match('/^(usa o termo proibido|precisa de|ficou vazio|tem \d+ caracteres)/u', $m)));
            if ($g !== []) {
                $graves[$campo] = $g;
            }
        }
        return $graves;
    }

    private static function conferirTexto(string $campo, string $t, int $max, array $ctx, array $vagos, string $descNorm, callable $anota): void
    {
        $n = mb_strlen($t, 'UTF-8');
        if ($n > $max) {
            $anota($campo, "tem {$n} caracteres; o máximo é {$max}: reescreva mais curto, com frase completa");
        }
        if (($termo = GeradorConteudo::termoProibido($t, $ctx['proibidos'])) !== null) {
            $anota($campo, 'usa o termo proibido "' . $termo . '"');
        }
        $vago = GeradorConteudo::termoProibido($t, $vagos);
        if ($vago !== null && !str_contains($descNorm, ' ' . self::normalizar($vago) . ' ')) {
            $anota($campo, 'evite "' . $vago . '": é vago ou não está na descrição; troque por um fato concreto');
        }
    }

    /** "serv.3.d" → "lista:serv" (listas voltam inteiras); escalares ficam como estão. */
    private static function grupoDe(string $chave, array $listas): string
    {
        $primeiro = explode('.', $chave)[0];
        return isset($listas[$primeiro]) && substr_count($chave, '.') === 2 ? 'lista:' . $primeiro : $chave;
    }

    private static function iniciaisMaiusculas(string $t): bool
    {
        $longas = array_filter(preg_split('/\s+/u', $t) ?: [], static fn (string $w): bool => mb_strlen($w, 'UTF-8') > 3);
        if (count($longas) < 3) {
            return false;
        }
        foreach ($longas as $w) {
            $primeira = mb_substr($w, 0, 1, 'UTF-8');
            if ($primeira === mb_strtolower($primeira, 'UTF-8')) {
                return false;
            }
        }
        return true;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? Texto::colapsarEspacos(trim($v)) : '';
    }

    private static function normalizar(string $t): string
    {
        return trim((string) preg_replace('/[^a-z0-9%]+/', ' ', Texto::normalizar($t)));
    }

    /** @return list<string> palavras normalizadas (sem acento, minúsculas). */
    private static function palavras(string $t): array
    {
        $n = self::normalizar($t);
        return $n === '' ? [] : explode(' ', $n);
    }
}
