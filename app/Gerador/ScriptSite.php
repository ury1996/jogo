<?php

declare(strict_types=1);

namespace Rankly\Gerador;

/**
 * JavaScript mínimo embutido no site publicado (contrato §7 passo 6), sem dependências, ES2017.
 * Montado só com as partes que o site usa:
 * - base: utilitários (sempre);
 * - formulario: envio por fetch('/_lead') com estados (enviando / ok / erro com a mensagem do
 *   servidor), `_t` e a origem da visita (utm_*, gclid, gbraid, wbraid, página de entrada,
 *   referência) guardada em sessionStorage e enviada em campos ocultos [M22]; sem JavaScript o
 *   formulário faz o POST normal e o servidor responde 303 para /obrigado/;
 * - rastreio (só com GTM/GA4/Pixel): faixa de consentimento LGPD com o modo de consentimento do
 *   Google em "denied" por padrão; scripts de terceiros só depois de "Aceitar" [M23]; eventos no
 *   dataLayer (rankly_whatsapp_click com posicao, rankly_form_submit, rankly_phone_click);
 * - mapa: fachada do mapa, o iframe do Google Maps só carrega ao clicar [M17].
 */
final class ScriptSite
{
    /**
     * Fontes legíveis por parte. Regras para o minificador: comentários só em linhas próprias,
     * sem expressões regulares literais e com ";" no fim de cada instrução.
     */
    private const PARTES = [
        'base' => <<<'JS'
            (function () {
            'use strict';
            var C = __CONFIG__;
            var w = window, d = document, inicio = Date.now(), aceito = false;
            function ler(tipo, k) { try { return w[tipo].getItem(k); } catch (e) { return null; } }
            function gravar(tipo, k, v) { try { w[tipo].setItem(k, v); } catch (e) { return; } }
            function perto(e, sel) { var t = e.target; return t && t.closest ? t.closest(sel) : null; }
            JS,
        'semRastreio' => <<<'JS'
            function evento() { return aceito; }
            JS,
        'rastreio' => <<<'JS'
            // Eventos de conversão: dataLayer (GTM); GA4 direto e Pixel só depois do aceite.
            function evento(nome, dados, depois) {
            var o = { event: nome };
            for (var k in dados) o[k] = dados[k];
            if (depois) { o.eventCallback = depois; o.eventTimeout = 1200; setTimeout(depois, 1500); }
            w.dataLayer.push(o);
            if (aceito && C.ga4 && !C.gtm) w.gtag('event', nome, dados);
            if (aceito && C.pixel && w.fbq) w.fbq('track', nome === 'rankly_form_submit' ? 'Lead' : 'Contact', dados);
            }
            function carregar(src) { var s = d.createElement('script'); s.async = true; s.src = src; d.head.appendChild(s); }
            function consentir(v) { w.gtag('consent', 'update', { ad_storage: v, ad_user_data: v, ad_personalization: v, analytics_storage: v }); }
            function aceitar(salvar) {
            if (salvar) gravar('localStorage', 'rk_consentimento', 'aceito');
            if (aceito) return;
            aceito = true;
            consentir('granted');
            if (C.gtm) { w.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' }); carregar('https://www.googletagmanager.com/gtm.js?id=' + C.gtm); }
            if (C.ga4) { carregar('https://www.googletagmanager.com/gtag/js?id=' + C.ga4); w.gtag('js', new Date()); w.gtag('config', C.ga4); }
            if (C.pixel) {
            var n = w.fbq = function () { if (n.callMethod) n.callMethod.apply(n, arguments); else n.queue.push(arguments); };
            if (!w._fbq) w._fbq = n;
            n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
            carregar('https://connect.facebook.net/en_US/fbevents.js');
            n('init', C.pixel); n('track', 'PageView');
            }
            }
            // Faixa de consentimento (LGPD): Aceitar / Recusar; a escolha fica no localStorage.
            function faixa() {
            var f = d.getElementById('rk-consent');
            if (!f) {
            f = d.createElement('div');
            f.id = 'rk-consent';
            f.className = 'rk-consent rk-ctx-escuro';
            f.setAttribute('role', 'region');
            f.setAttribute('aria-label', 'Aviso de cookies');
            f.innerHTML = '<p>Usamos cookies para medir as visitas e melhorar nossos anúncios. Você aceita? <a href="/privacidade/">Saiba mais</a>.</p><div class="rk-acoes"><button type="button" class="rk-btn" data-escolha="aceito">Aceitar</button><button type="button" class="rk-btn rk-btn--sec" data-escolha="recusado">Recusar</button></div>';
            f.addEventListener('click', function (e) {
            var b = perto(e, '[data-escolha]');
            if (!b) return;
            if (b.getAttribute('data-escolha') === 'aceito') aceitar(true);
            else { gravar('localStorage', 'rk_consentimento', 'recusado'); if (aceito) consentir('denied'); }
            f.hidden = true;
            });
            d.body.appendChild(f);
            }
            f.hidden = false;
            }
            w.dataLayer = w.dataLayer || [];
            w.gtag = w.gtag || function () { w.dataLayer.push(arguments); };
            w.gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 });
            var escolha = ler('localStorage', 'rk_consentimento');
            if (escolha === 'aceito') aceitar(false);
            else if (escolha !== 'recusado') faixa();
            d.addEventListener('click', function (e) {
            var a = perto(e, 'a[data-ev]');
            if (perto(e, '[data-consentimento]')) { e.preventDefault(); faixa(); }
            if (!a) return;
            var tipo = a.getAttribute('data-ev');
            var mesmaAba = a.target !== '_blank' && (a.protocol === 'https:' || a.protocol === 'http:');
            var ir = mesmaAba && aceito && C.gtm && !e.defaultPrevented && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.button ? function () { if (ir) { var h = a.href; ir = null; location.href = h; } } : null;
            if (ir) e.preventDefault();
            if (tipo === 'whatsapp') evento('rankly_whatsapp_click', { posicao: a.getAttribute('data-pos') || '' }, ir);
            else if (tipo === 'telefone') evento('rankly_phone_click', {}, ir);
            else if (ir) ir();
            });
            JS,
        'mapa' => <<<'JS'
            // Fachada do mapa: troca o espaço do mapa pelo iframe só no clique.
            d.addEventListener('click', function (e) {
            var b = perto(e, '[data-mapa]');
            if (!b) return;
            e.preventDefault();
            var url = b.getAttribute('data-mapa') || '';
            if (url.indexOf('https://www.google.com/maps') !== 0) return;
            var caixa = b.closest('.rk-mapa') || b, f = d.createElement('iframe');
            f.src = url;
            f.title = b.getAttribute('data-mapa-titulo') || 'Mapa com a localização';
            f.loading = 'lazy';
            f.referrerPolicy = 'no-referrer-when-downgrade';
            f.setAttribute('allowfullscreen', '');
            caixa.appendChild(f);
            caixa.removeAttribute('data-mapa');
            caixa.classList.add('rk-mapa--aberto');
            if (b !== caixa) b.hidden = true;
            });
            JS,
        'formulario' => <<<'JS'
            // Origem da visita: campanha da URL, página de entrada e referência externa.
            var CAMPANHA = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid'];
            var origem = {};
            try { origem = JSON.parse(ler('sessionStorage', 'rk_origem') || '{}') || {}; } catch (e) { origem = {}; }
            var busca = new URLSearchParams(location.search);
            if (CAMPANHA.some(function (k) { return busca.get(k); }) || !origem.pagina) {
            origem = {};
            CAMPANHA.forEach(function (k) { var v = busca.get(k); if (v) origem[k] = v.slice(0, 300); });
            origem.pagina = (location.origin + location.pathname + location.search).slice(0, 500);
            if (d.referrer && d.referrer.indexOf(location.origin + '/') !== 0) origem.referencia = d.referrer.slice(0, 500);
            gravar('sessionStorage', 'rk_origem', JSON.stringify(origem));
            }
            function oculto(f, nome, valor) {
            var el = f.querySelector('input[name="' + nome + '"]');
            if (!el) { el = d.createElement('input'); el.type = 'hidden'; el.name = nome; f.appendChild(el); }
            el.value = valor;
            }
            function junto(f, classe) {
            var el = f.querySelector('.' + classe);
            for (var s = f.nextElementSibling; !el && s; s = s.nextElementSibling) if (s.classList.contains(classe)) el = s;
            return el;
            }
            function aviso(st, msg, tipo) { if (st) { st.textContent = msg; st.setAttribute('data-tipo', tipo); } }
            // Envio: fetch com estados; sem fetch, segue o envio normal (303 para /obrigado/).
            d.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f.matches || !f.matches('form[data-form]')) return;
            oculto(f, '_t', String(Date.now() - inicio));
            for (var k in origem) oculto(f, k, origem[k]);
            if (!w.fetch) return;
            e.preventDefault();
            if (f.getAttribute('data-estado') === 'enviando') return;
            var st = junto(f, 'rk-form__status'), ok = junto(f, 'rk-form__ok'), corpo = [];
            for (var i = 0; i < f.elements.length; i++) {
            var el = f.elements[i];
            if (el.name && !el.disabled && ((el.type !== 'checkbox' && el.type !== 'radio') || el.checked)) corpo.push(encodeURIComponent(el.name) + '=' + encodeURIComponent(el.value));
            }
            var falha = function (msg) {
            f.setAttribute('data-estado', 'erro');
            aviso(st, msg || 'Não foi possível enviar. Verifique a conexão ou fale pelo WhatsApp.', 'erro');
            };
            f.setAttribute('data-estado', 'enviando');
            aviso(st, 'Enviando…', 'info');
            fetch(f.getAttribute('action') || '/_lead', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: corpo.join('&')
            }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
            if (!r.ok || !j || !j.ok) return falha(j && j.erro && j.erro.mensagem);
            f.setAttribute('data-estado', 'ok');
            aviso(st, '', 'ok');
            if (ok) { ok.hidden = false; if (!f.contains(ok)) f.hidden = true; ok.setAttribute('tabindex', '-1'); ok.focus(); }
            evento('rankly_form_submit', { form: f.getAttribute('data-form') || '' });
            });
            }, function () { falha(''); });
            });
            JS,
        'fim' => <<<'JS'
            })();
            JS,
    ];

    /** Pontuação ao redor da qual espaços podem sair sem mudar o significado (fora de strings). */
    private const PONTUACAO = '{}()[];,:=<>+*!&|?';

    /**
     * Código final do script, com a configuração embutida.
     *
     * @param array{gtm?: string, ga4?: string, metaPixel?: string} $rastreamento IDs (inválidos são ignorados)
     * @param array{formulario?: bool, mapa?: bool} $usa partes que o site usa
     */
    public static function gerar(array $rastreamento, array $usa = ['formulario' => true, 'mapa' => true]): string
    {
        $config = [
            'gtm' => self::id($rastreamento['gtm'] ?? '', Validador::RE_GTM),
            'ga4' => self::id($rastreamento['ga4'] ?? '', Validador::RE_GA4),
            'pixel' => self::id($rastreamento['metaPixel'] ?? '', Validador::RE_PIXEL),
        ];
        $rastreio = $config['gtm'] !== '' || $config['ga4'] !== '' || $config['pixel'] !== '';
        $partes = ['base', $rastreio ? 'rastreio' : 'semRastreio'];
        if ($usa['mapa'] ?? false) {
            $partes[] = 'mapa';
        }
        if ($usa['formulario'] ?? false) {
            $partes[] = 'formulario';
        }
        $partes[] = 'fim';
        $fonte = implode("\n", array_map(static fn (string $p): string => self::PARTES[$p], $partes));
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return str_replace('__CONFIG__', $json, self::minificar($fonte));
    }

    /** Hash para a CSP: 'sha256-…' do conteúdo exato da tag <script>. */
    public static function hashCsp(string $js): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $js, true)) . "'";
    }

    /**
     * Minificação segura para o código acima: tira comentários de linha inteira, recuos e
     * espaços junto da pontuação (fora de strings). Linhas só são emendadas depois de
     * ";", "{", "}" ou "," — nos demais casos a quebra fica (nada muda na inserção
     * automática de ponto e vírgula).
     */
    public static function minificar(string $js): string
    {
        $saida = '';
        foreach (self::linhasMinificadas($js) as $linha) {
            $emenda = $saida === '' || in_array(substr($saida, -1), [';', '{', '}', ','], true) ? '' : "\n";
            $saida .= $emenda . $linha;
        }
        return $saida;
    }

    /** @return list<string> */
    private static function linhasMinificadas(string $js): array
    {
        $linhas = [];
        foreach (explode("\n", $js) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '//')) {
                continue;
            }
            $partes = preg_split('/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $linha, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$linha];
            $p = preg_quote(self::PONTUACAO, '/');
            foreach ($partes as $i => $parte) {
                if ($i % 2 === 0) {
                    $parte = preg_replace('/\s+/', ' ', $parte) ?? $parte;
                    $partes[$i] = preg_replace('/\s*([' . $p . '])\s*/', '$1', $parte) ?? $parte;
                }
            }
            $linhas[] = implode('', $partes);
        }
        return $linhas;
    }

    private static function id(mixed $v, string $re): string
    {
        $v = strtoupper(trim(is_string($v) ? $v : ''));
        return preg_match($re, $v) ? $v : '';
    }
}
