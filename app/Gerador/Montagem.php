<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Aplicacao;
use Rankly\Lib\Midia;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Icones;
use Rankly\Preparo\Preparo;
use Rankly\Preparo\Texto;
use Rankly\Preparo\Textos;

/**
 * Site preparado para publicar (feito uma vez e usado pelo Validador e pelo Gerador):
 * documento migrado, biblioteca, mídias, resultado de Preparo::prepararSite no modo
 * "publicar" e dados derivados.
 */
final class Montagem
{
    /** URLs das fotos no site publicado: absolutas, para funcionar também em /privacidade/ e no 404. */
    public const URL_MIDIA = '/img/{id}-{w}.{ext}';

    /** @var array<string, list<int>>|null chave de texto → índices das seções que a exibem */
    private ?array $chaves = null;

    private function __construct(
        public readonly array $site,
        public readonly array $doc,
        public readonly array $lib,
        public readonly array $midia,
        public readonly array $opcoes,
        public readonly array $preparado,
        public readonly array $vars,
        public readonly array $d,
        public readonly array $nicho,
        public readonly ?array $especialidade,
    ) {
    }

    public static function criar(Aplicacao $app, array $site): self
    {
        $lib = $app->biblioteca();
        $doc = Documento::migrar(is_array($site['documento'] ?? null) ? $site['documento'] : [], $lib);
        $midia = isset($site['id']) ? Midia::mapaDoSite($app, (int) $site['id']) : [];
        $ano = (int) $app->agora()->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y');
        $opcoes = ['modo' => 'publicar', 'urlMidia' => self::URL_MIDIA, 'midia' => $midia, 'ano' => $ano];
        $preparado = Preparo::prepararSite($doc, $lib, $opcoes);
        $vars = Textos::contextoVariaveis($doc, $lib);
        $u = [];
        $utilitarios = Texto::comoMapa(Texto::pegar(Texto::comoMapa($lib['icones'] ?? []), 'utilitarios'));
        foreach ($utilitarios as $nome => $def) {
            $u[(string) $nome] = Icones::svgDaDefinicao($def, $doc['estilo']['acabamento']);
        }
        $d = Preparo::montarDados($doc, $lib, $opcoes, $vars, $u);
        $d['u'] = $u;
        return new self(
            $site, $doc, $lib, $midia, $opcoes, $preparado, $vars, $d,
            Documento::nichoDe($doc, $lib), Documento::especialidade($doc, $lib),
        );
    }

    public function siteId(): int
    {
        return (int) ($this->site['id'] ?? 0);
    }

    public function slug(): string
    {
        return (string) ($this->site['slug'] ?? '');
    }

    /** Seções renderizadas (com o invólucro), na ordem da página. */
    public function secoes(): array
    {
        return $this->preparado['secoes'];
    }

    /** A seção do tipo está na página? */
    public function temSecao(string $tipo): bool
    {
        foreach ($this->secoes() as $s) {
            if ($s['tipo'] === $tipo) {
                return true;
            }
        }
        return false;
    }

    /**
     * Chaves de texto exibidas (data-k) → índices das seções onde aparecem.
     *
     * @return array<string, list<int>>
     */
    public function chavesExibidas(): array
    {
        if ($this->chaves === null) {
            $this->chaves = [];
            foreach ($this->secoes() as $s) {
                foreach (self::chavesDoHtml($s['html']) as $chave) {
                    $this->chaves[$chave][] = (int) $s['indice'];
                }
            }
        }
        return $this->chaves;
    }

    /** @return list<string> valores de data-k de um trecho de HTML (sem repetição) */
    public static function chavesDoHtml(string $html): array
    {
        preg_match_all('/\sdata-k="([a-z][a-z0-9.]*)"/', $html, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Espaços de foto vazios por seção: [indice => [chave, …]].
     *
     * @return array<int, list<string>>
     */
    public function fotosVazias(): array
    {
        $r = [];
        foreach ($this->secoes() as $s) {
            if (preg_match_all('/\sdata-img="([a-z][a-z0-9.]*)"[^>]*>\s*<span class="rk-foto__vazio"/', $s['html'], $m)) {
                $r[(int) $s['indice']] = array_values(array_unique($m[1]));
            }
        }
        return $r;
    }

    /** Textos padrão (não editados) exibidos na página: chave → texto efetivo [M5]. */
    public function textosPadraoExibidos(): array
    {
        $r = [];
        foreach (array_keys($this->chavesExibidas()) as $chave) {
            if (Textos::ehPadrao($this->doc, $chave)) {
                $r[$chave] = Textos::textoEfetivo($this->doc, $this->lib, $chave, $this->vars);
            }
        }
        ksort($r, SORT_STRING);
        return $r;
    }

    /** Nome da seção (do manifesto) para mensagens: "Serviços". */
    public function nomeSecao(string $tipo): string
    {
        $nome = $this->lib['secoes'][$tipo]['manifest']['nome'] ?? null;
        return is_string($nome) && $nome !== '' ? $nome : $tipo;
    }

    /**
     * HTML sem os atributos que só servem ao editor (§7 passo 2):
     * data-k, data-img, data-ic, data-li, data-it, data-sec, data-fundo.
     * Mexe só dentro das tags; data-ev, data-pos, data-form e data-mapa ficam.
     */
    public static function semAtributosDeEdicao(string $html): string
    {
        return preg_replace_callback(
            '/<[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?>/',
            static fn (array $m): string => preg_replace('/\s+data-(?:k|img|ic|li|it|sec|fundo)(?:="[^"]*")?(?=[\s>\/])/', '', $m[0]) ?? $m[0],
            $html,
        ) ?? $html;
    }
}
