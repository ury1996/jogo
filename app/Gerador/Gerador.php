<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Aplicacao;
use Rankly\Lib\Eventos;
use Rankly\Lib\Midia;
use Rankly\Lib\Slug;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Paleta;

/**
 * Gerador estático (contrato §7, §11.2): valida, monta a pasta do site (HTML, CSS, fontes,
 * fotos, SEO, páginas extras), grava como release atômica e registra a versão.
 */
final class Gerador
{
    /** Classes que o script do site cria em tempo de execução (não aparecem no HTML gerado). */
    private const CLASSES_DO_SCRIPT = ['rk-consent', 'rk-ctx-escuro', 'rk-btn', 'rk-btn--sec', 'rk-acoes', 'rk-mapa--aberto'];

    public function __construct(private readonly Aplicacao $app)
    {
    }

    /** @return array{erros: list<array>, avisos: list<array>, textosPadraoAlterados: list<array>} */
    public function validar(array $site): array
    {
        return (new Validador($this->app))->validar(Montagem::criar($this->app, $site));
    }

    /**
     * Publica o documento salvo do site.
     *
     * @return array{url: string, versao: int}
     * @throws ErroValidacao se houver pendências
     */
    public function publicar(array $site, int $usuarioId): array
    {
        $slug = (string) ($site['slug'] ?? '');
        $siteId = (int) ($site['id'] ?? 0);
        if (!Slug::valido($slug) || $siteId <= 0) {
            throw new \InvalidArgumentException('Site inválido para publicação.');
        }
        return $this->comTrava($siteId, function () use ($site, $slug, $siteId, $usuarioId): array {
            $m = Montagem::criar($this->app, $site);
            $validacao = (new Validador($this->app))->validar($m);
            if ($validacao['erros'] !== []) {
                throw new ErroValidacao($validacao['erros']);
            }
            $db = $this->app->db();
            $versao = (int) $db->valor('SELECT COALESCE(MAX(numero), 0) FROM versoes WHERE site_id = ?', [$siteId]) + 1;
            $release = Publicador::nomeRelease($versao);
            $construcao = $this->construir($m);
            $publicador = $this->publicador();
            $publicador->gravar($slug, $release, $construcao['arquivos'], $construcao['copias']);

            $agora = $this->app->agoraSql();
            $autor = $this->usuarioExistente($usuarioId);
            $troca = null;
            try {
                $db->transacao(function () use ($db, $site, $siteId, $slug, $versao, $release, $agora, $autor, $m, $publicador, &$troca): void {
                    $db->inserir('versoes', [
                        'site_id' => $siteId,
                        'numero' => $versao,
                        'documento' => Documento::json(is_array($site['documento'] ?? null) ? $site['documento'] : []),
                        'resolvidos' => self::jsonObjeto($m->textosPadraoExibidos()),
                        'biblioteca_versao' => (string) ($m->lib['versao'] ?? ''),
                        'release' => $release,
                        'publicado_por' => $autor,
                        'publicado_em' => $agora,
                    ]);
                    $db->atualizar('sites', ['status' => 'publicado', 'publicado_versao' => $versao, 'publicado_em' => $agora], ['id' => $siteId]);
                    // Última etapa: a troca atômica do link. Se o banco falhar depois, ela é desfeita.
                    $troca = $publicador->apontar($slug, $release, (int) ($site['publicado_versao'] ?? 0));
                    if ($troca['legado'] !== null && ($site['publicado_versao'] ?? null) !== null) {
                        $db->executar('UPDATE versoes SET `release` = ? WHERE site_id = ? AND numero = ?', [$troca['legado'], $siteId, (int) $site['publicado_versao']]);
                    }
                });
            } catch (\Throwable $e) {
                if ($troca !== null) {
                    try {
                        $publicador->restaurar($slug, $troca['anterior'] ?? $troca['legado']);
                    } catch (\Throwable $e2) {
                        $this->app->log()->excecao($e2, ['etapa' => 'desfazer troca', 'site' => $siteId]);
                    }
                }
                Publicador::apagarArvore($publicador->dirRelease($slug, $release));
                throw $e;
            }
            Eventos::registrar($this->app, $siteId, $autor, 'site.release', [
                'acao' => 'publicar', 'versao' => $versao, 'release' => $release, 'anterior' => $troca['anterior'] ?? null,
            ]);
            $this->limparReleases($slug, $siteId);
            return ['url' => $this->urlBase($m), 'versao' => $versao];
        });
    }

    /**
     * Volta o site para a publicação anterior que ainda está guardada [M10].
     *
     * @return array{url: string, versao: int}
     */
    public function reverter(array $site, int $usuarioId): array
    {
        $slug = (string) ($site['slug'] ?? '');
        $siteId = (int) ($site['id'] ?? 0);
        if (!Slug::valido($slug) || $siteId <= 0) {
            throw new \InvalidArgumentException('Site inválido.');
        }
        return $this->comTrava($siteId, function () use ($site, $slug, $siteId, $usuarioId): array {
            $db = $this->app->db();
            $publicador = $this->publicador();
            $atualRelease = $publicador->releaseAtual($slug);
            $linha = $db->um('SELECT publicado_versao FROM sites WHERE id = ?', [$siteId]);
            $atual = $linha !== null && $linha['publicado_versao'] !== null ? (int) $linha['publicado_versao'] : null;
            if ($atual === null && $atualRelease !== null) {
                $atual = Publicador::versaoDaRelease($atualRelease);
            }
            if ($atual === null || $atualRelease === null) {
                throw new \DomainException('Este site ainda não tem uma publicação no ar para desfazer.');
            }
            $guardadas = array_flip($publicador->listar($slug));
            $alvo = null;
            foreach ($db->todos('SELECT numero, `release`, publicado_em FROM versoes WHERE site_id = ? AND numero < ? ORDER BY numero DESC', [$siteId, $atual]) as $v) {
                if (is_string($v['release']) && isset($guardadas[$v['release']]) && $v['release'] !== $atualRelease) {
                    $alvo = $v;
                    break;
                }
            }
            if ($alvo === null) {
                throw new \DomainException('Não há uma publicação anterior guardada para voltar.');
            }
            $autor = $this->usuarioExistente($usuarioId);
            $trocou = false;
            try {
                $db->transacao(function () use ($db, $siteId, $slug, $alvo, $publicador, &$trocou): void {
                    $db->atualizar('sites', [
                        'status' => 'publicado', 'publicado_versao' => (int) $alvo['numero'], 'publicado_em' => (string) $alvo['publicado_em'],
                    ], ['id' => $siteId]);
                    $publicador->apontar($slug, (string) $alvo['release']);
                    $trocou = true;
                });
            } catch (\Throwable $e) {
                if ($trocou) {
                    $publicador->restaurar($slug, $atualRelease);
                }
                throw $e;
            }
            Eventos::registrar($this->app, $siteId, $autor, 'site.release', [
                'acao' => 'reverter', 'versao' => (int) $alvo['numero'], 'release' => (string) $alvo['release'], 'anterior' => $atualRelease,
            ]);
            $url = $this->urlBase(null, $site);
            return ['url' => $url, 'versao' => (int) $alvo['numero']];
        });
    }

    /**
     * Monta todos os arquivos do site: páginas, CSS, sitemap, robots, favicon e as cópias
     * (fontes do par e variantes de foto usadas).
     *
     * @return array{arquivos: array<string, string>, copias: array<string, string>}
     */
    public function construir(Montagem $m): array
    {
        $base = $this->urlBase($m);
        $doc = $m->doc;
        $agora = $this->app->agora();
        $paleta = Paleta::gerarPaleta($doc['estilo']['cor']);
        $tokens = $paleta['tokens'];
        $rastreamento = self::rastreamentoValido($doc['rastreamento']);

        // Corpo das páginas: seções sem os atributos de edição (as do meio dentro de <main>);
        // as páginas extras reaproveitam o cabeçalho e o rodapé da inicial.
        [$cabecalho, $rodape] = PaginasExtras::cabecalhoERodape($m);
        $nomes = [];
        if ($rastreamento['gtm'] !== '') {
            $nomes[] = 'Google Tag Manager (Google)';
        }
        if ($rastreamento['ga4'] !== '') {
            $nomes[] = 'Google Analytics 4 (Google)';
        }
        if ($rastreamento['metaPixel'] !== '') {
            $nomes[] = 'Meta Pixel (Facebook/Instagram)';
        }
        $corpos = [
            'index.html' => $this->corpoInicial($m),
            'privacidade/index.html' => $cabecalho . "\n" . PaginasExtras::privacidade($m, $nomes, (int) $this->app->config('retencao_leads_meses', 12), $agora) . "\n" . $rodape,
            'obrigado/index.html' => $cabecalho . "\n" . PaginasExtras::obrigado($m) . "\n" . $rodape,
            '404.html' => $cabecalho . "\n" . PaginasExtras::naoEncontrada($m) . "\n" . $rodape,
        ];
        $todos = implode("\n", $corpos);
        $usa = ['formulario' => str_contains($todos, ' data-form="'), 'mapa' => str_contains($todos, ' data-mapa="')];
        $script = ScriptSite::gerar($rastreamento, $usa);

        // CSS só do que as páginas usam; embutido até 30 KB, senão arquivo com hash no nome.
        $classes = Css::classesDoHtml($todos);
        foreach (array_merge(explode(' ', $m->preparado['classesRaiz']), self::CLASSES_DO_SCRIPT) as $c) {
            $classes[$c] = true;
        }
        $tipos = array_map(static fn (array $s): string => $s['tipo'], $m->secoes());
        $css = Css::podar(Css::montar($m->lib, $tipos, $m->preparado['cssPaleta'], $doc['estilo']['fonte']), $classes);
        $arquivos = [];
        if (strlen($css) <= Css::LIMITE_EMBUTIDO) {
            $cssTag = ['inline' => $css];
        } else {
            $nomeCss = Css::nomeArquivo($css);
            $arquivos[$nomeCss] = $css;
            $cssTag = ['href' => '/' . $nomeCss];
        }
        $fontes = Css::fontes($m->lib, $doc['estilo']['fonte']);

        // Favicon: PNG a partir do logo raster; senão SVG com a inicial.
        $favicon = Favicon::gerar($this->arquivoLogoRaster($m), $m->d['inicial'], $tokens['--p'], $tokens['--on-p'], $doc['estilo']['acabamento']);
        $arquivos += $favicon['arquivos'];

        $titulo = Seo::titulo($m);
        $descricao = Seo::descricao($m);
        $imagemOg = Seo::imagemOg($m, $base);
        $comum = [
            'corTema' => $tokens['--p'],
            'favicon' => $favicon['links'],
            'preload' => $fontes['preload'] !== null ? '/fontes/' . $fontes['preload'] : null,
            'css' => $cssTag,
            'classesRaiz' => $m->preparado['classesRaiz'],
            'script' => $script,
            'rastreamento' => $rastreamento,
        ];
        $og = static fn (string $t, string $d, string $url): array => [
            'titulo' => $t, 'descricao' => $d, 'url' => $url, 'siteNome' => $m->vars['nome'], 'imagem' => $imagemOg,
        ];

        $arquivos['index.html'] = Seo::documento($comum + [
            'titulo' => $titulo,
            'descricao' => $descricao,
            'canonical' => $base . '/',
            'og' => $og($titulo, $descricao, $base . '/'),
            'jsonLd' => Seo::jsonLd($m, ['base' => $base, 'descricao' => $descricao, 'imagem' => $imagemOg['url'] ?? null, 'logo' => Seo::urlLogo($m, $base)]),
            'corpo' => $corpos['index.html'],
        ]);
        $tituloPrivacidade = 'Política de privacidade · ' . $m->vars['nome'];
        $descPrivacidade = 'Como ' . $m->vars['nome'] . ' trata os dados pessoais recebidos por este site (LGPD).';
        $arquivos['privacidade/index.html'] = Seo::documento($comum + [
            'titulo' => $tituloPrivacidade,
            'descricao' => $descPrivacidade,
            'canonical' => $base . '/privacidade/',
            'og' => $og($tituloPrivacidade, $descPrivacidade, $base . '/privacidade/'),
            'corpo' => $corpos['privacidade/index.html'],
        ]);
        $arquivos['obrigado/index.html'] = Seo::documento($comum + [
            'titulo' => 'Mensagem enviada · ' . $m->vars['nome'],
            'descricao' => 'Recebemos o seu contato. ' . $m->vars['nome'] . ' retorna em breve.',
            'noindex' => true,
            'corpo' => $corpos['obrigado/index.html'],
        ]);
        $arquivos['404.html'] = Seo::documento($comum + [
            'titulo' => 'Página não encontrada · ' . $m->vars['nome'],
            'descricao' => 'Esta página não existe. Volte para a página inicial de ' . $m->vars['nome'] . '.',
            'noindex' => true,
            'corpo' => $corpos['404.html'],
        ]);

        $data = $agora->format('Y-m-d');
        $arquivos['sitemap.xml'] = Seo::sitemap([['url' => $base . '/', 'data' => $data], ['url' => $base . '/privacidade/', 'data' => $data]]);
        $arquivos['robots.txt'] = Seo::robots($base);

        // Cópias: fontes do par e só as variantes de foto que as páginas usam [M12].
        $copias = [];
        $dirFontes = $this->app->dir('biblioteca') . '/fontes';
        foreach ($fontes['arquivos'] as $arq) {
            if (is_file($dirFontes . '/' . $arq)) {
                $copias['fontes/' . $arq] = $dirFontes . '/' . $arq;
            }
        }
        foreach ($arquivos as $nome => $conteudo) {
            if (!str_ends_with($nome, '.html')) {
                continue;
            }
            preg_match_all('#/img/(m_[0-9a-f]{8})-(\d{1,5}|orig)\.(webp|svg)\b#', $conteudo, $usos, PREG_SET_ORDER);
            foreach ($usos as [, $id, $w, $ext]) {
                if (!isset($m->midia[$id])) {
                    continue;
                }
                $origem = Midia::caminho($this->app, $m->siteId(), $id, $w === 'orig' ? 'orig.svg' : $w . '.webp');
                if (is_file($origem)) {
                    $copias['img/' . $id . '-' . $w . '.' . $ext] = $origem;
                } else {
                    $this->app->log()->aviso('Variante de foto ausente na publicação.', ['site' => $m->siteId(), 'midia' => $id, 'w' => $w]);
                }
            }
        }
        return ['arquivos' => $arquivos, 'copias' => $copias];
    }

    /** Corpo da página inicial: cabeçalho, <main> com as seções do meio, rodapé e WhatsApp flutuante. */
    private function corpoInicial(Montagem $m): string
    {
        $secoes = $m->secoes();
        $htmls = array_map(static fn (array $s): string => $s['html'], $secoes);
        $resto = (string) substr($m->preparado['html'], strlen(implode("\n", $htmls)));
        $inicio = [];
        $meio = [];
        $fim = [];
        foreach ($secoes as $i => $s) {
            if ($i === 0 && $s['tipo'] === 'header') {
                $inicio[] = $s['html'];
            } elseif ($i === count($secoes) - 1 && $s['tipo'] === 'rodape') {
                $fim[] = $s['html'];
            } else {
                $meio[] = $s['html'];
            }
        }
        $partes = $inicio;
        if ($meio !== []) {
            $partes[] = "<main>\n" . implode("\n", $meio) . "\n</main>";
        }
        $html = implode("\n", array_merge($partes, $fim)) . $resto;
        return Montagem::semAtributosDeEdicao($html);
    }

    /** IDs de rastreamento no formato exato (o resto vira vazio). */
    public static function rastreamentoValido(array $r): array
    {
        $limpo = static function (mixed $v, string $re): string {
            $v = is_string($v) ? strtoupper(trim($v)) : '';
            return preg_match($re, $v) ? $v : '';
        };
        return [
            'gtm' => $limpo($r['gtm'] ?? '', Validador::RE_GTM),
            'ga4' => $limpo($r['ga4'] ?? '', Validador::RE_GA4),
            'metaPixel' => $limpo($r['metaPixel'] ?? '', Validador::RE_PIXEL),
        ];
    }

    /** Arquivo raster do logo (PNG original ou a maior variante WebP), ou null. */
    private function arquivoLogoRaster(Montagem $m): ?string
    {
        $id = $m->doc['dados']['logo'];
        $midia = is_string($id) ? ($m->midia[$id] ?? null) : null;
        if (!is_array($midia) || ($midia['formato'] ?? 'webp') !== 'webp') {
            return null;
        }
        $png = Midia::caminho($this->app, $m->siteId(), $id, 'orig.png');
        if (is_file($png)) {
            return $png;
        }
        $variantes = array_map('intval', $midia['variantes'] ?? []);
        rsort($variantes);
        foreach ($variantes as $w) {
            $arq = Midia::caminho($this->app, $m->siteId(), $id, $w . '.webp');
            if (is_file($arq)) {
                return $arq;
            }
        }
        return null;
    }

    /**
     * Endereço público do site: domínio próprio ativo (fase 2) ou {slug}.{dominio_sites}.
     */
    private function urlBase(?Montagem $m, ?array $site = null): string
    {
        $site ??= $m?->site ?? [];
        $siteId = (int) ($site['id'] ?? 0);
        if ($siteId > 0) {
            try {
                $dominio = $this->app->db()->valor("SELECT dominio FROM dominios WHERE site_id = ? AND status = 'ativo' ORDER BY id LIMIT 1", [$siteId]);
                if (is_string($dominio) && preg_match('/^[a-z0-9.-]+$/D', $dominio)) {
                    return 'https://' . $dominio;
                }
            } catch (\Throwable) {
                // tabela de domínios indisponível: segue com o subdomínio
            }
        }
        return rtrim($this->app->urlSite((string) ($site['slug'] ?? '')), '/');
    }

    private function publicador(): Publicador
    {
        return new Publicador($this->app->dir('sites'));
    }

    private function limparReleases(string $slug, int $siteId): void
    {
        try {
            $this->publicador()->limpar($slug, (int) $this->app->config('releases_mantidas', 5));
        } catch (\Throwable $e) {
            $this->app->log()->excecao($e, ['etapa' => 'limpar releases', 'site' => $siteId]);
        }
    }

    private function usuarioExistente(int $usuarioId): ?int
    {
        if ($usuarioId <= 0) {
            return null;
        }
        return $this->app->db()->valor('SELECT id FROM usuarios WHERE id = ?', [$usuarioId]) !== null ? $usuarioId : null;
    }

    /** Mapa → JSON de objeto ({} quando vazio). */
    private static function jsonObjeto(array $mapa): string
    {
        return json_encode($mapa === [] ? new \stdClass() : $mapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Uma publicação/reversão por site por vez, também entre a API e o cron
     * (trava própria, diferente da trava da API, para não conflitar com ela).
     */
    private function comTrava(int $siteId, callable $fn): array
    {
        $h = @fopen($this->app->dirVar('locks') . '/gerador-' . $siteId . '.lock', 'c');
        if ($h === false) {
            return $fn();
        }
        try {
            flock($h, LOCK_EX);
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
