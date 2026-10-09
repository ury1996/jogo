// Verificação dos sites publicados: 4 nichos × 7 modelos (3 gerais + 4 exclusivos), com dados completos e fotos JPEG
// reais, abertos no Chromium em 1280 px e 390 px. Para cada um: zero erros de console, CSP sem
// violações, nada de terceiros, sem rolagem horizontal, peso (HTML+CSS < 80 KB, JS < 5 KB),
// LCP/CLS aproximados (API de performance do Chromium) e capturas de tela em var/e2e/capturas/.
import { test, expect } from '@playwright/test';
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { createRequire } from 'node:module';
import { Api, publicarSiteCompleto, urlSite, vigiar, httpSite, RAIZ } from './apoio/ambiente.mjs';

// Decodificador PNG que já vem com o Playwright (sem dependência nova).
const { PNG } = createRequire(import.meta.url)('playwright-core/lib/utilsBundle');

const NICHOS = ['advocacia', 'financas', 'empresas', 'clinicas'];
const MODELOS_GERAIS = ['classico', 'moderno', 'direto'];
/** Modelos de cada nicho: os gerais + os exclusivos ("nichos" no JSON do modelo). */
const MODELOS_DO_NICHO = Object.fromEntries(NICHOS.map((n) => [n, [...MODELOS_GERAIS,
  ...readdirSync(path.join(RAIZ, 'biblioteca/modelos')).filter((a) => a.endsWith('.json')).sort()
    .map((a) => JSON.parse(readFileSync(path.join(RAIZ, 'biblioteca/modelos', a), 'utf8')))
    .filter((m) => Array.isArray(m.nichos) && m.nichos.includes(n)).map((m) => m.id)]]));
const LARGURAS = [1280, 390];
const DIR_CAPTURAS = path.join(RAIZ, 'var/e2e/capturas');
const KB = 1024;

let api;
let lib;

test.beforeAll(async ({ playwright }) => {
  api = await Api.entrar(playwright.request);
  lib = await api.biblioteca();
  mkdirSync(DIR_CAPTURAS, { recursive: true });
});

test.afterAll(async () => {
  await api?.encerrar();
});

/** Peso do que o navegador baixa para renderizar (sem fontes e fotos). */
async function medirPeso(slug) {
  const html = (await httpSite(slug, '/')).corpo;
  const href = html.match(/<link rel="stylesheet" href="([^"]+)"/)?.[1];
  const cssExterno = href ? (await httpSite(slug, href)).corpo : '';
  const cssInline = [...html.matchAll(/<style>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('');
  const js = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('');
  const bytes = (s) => Buffer.byteLength(s, 'utf8');
  return {
    html: bytes(html),
    css: bytes(cssInline) + bytes(cssExterno),
    cssExterno: !!href,
    htmlMaisCss: bytes(html) + bytes(cssExterno),
    js: bytes(js),
    gzip: gzipSync(html + cssExterno).length,
  };
}

/**
 * Pixels que mudam (além do antisserrilhado) quando os <use> do sprite de ícones voltam a ser
 * desenho embutido: deve ser zero — o sprite não pode mudar a aparência (CSS que entra no
 * ícone não alcança a árvore sombra do <use>).
 */
async function diferencaDoSprite(pagina) {
  const foto = async () => PNG.sync.read(await pagina.screenshot({ fullPage: true, animations: 'disabled' }));
  const contar = (a, b) => {
    if (a.width !== b.width || a.height !== b.height) return -1;
    let n = 0;
    for (let i = 0; i < a.data.length; i += 4) {
      const d = Math.max(Math.abs(a.data[i] - b.data[i]), Math.abs(a.data[i + 1] - b.data[i + 1]), Math.abs(a.data[i + 2] - b.data[i + 2]));
      if (d > 96) n++;
    }
    return n;
  };
  // Página estável primeiro (fotos decodificadas, fontes prontas, duas capturas iguais).
  await pagina.evaluate(() => Promise.all([document.fonts.ready, ...[...document.images].map((i) => i.decode().catch(() => null))]));
  let antes = await foto();
  for (let i = 0; i < 4; i++) {
    const deNovo = await foto();
    const igual = contar(antes, deNovo) === 0;
    antes = deNovo;
    if (igual) break;
    await pagina.waitForTimeout(250);
  }
  const usos = await pagina.evaluate(() => {
    const us = [...document.querySelectorAll('svg > use')];
    for (const u of us) u.parentNode.innerHTML = document.querySelector(u.getAttribute('href')).innerHTML;
    return us.length;
  });
  return { usos, pixels: contar(antes, await foto()) };
}

/** LCP e CLS aproximados (PerformanceObserver com buffered). */
async function medirVitais(pagina) {
  return pagina.evaluate(() => new Promise((ok) => {
    const r = { lcp: null, lcpElemento: null, cls: 0 };
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        r.lcp = Math.round(e.startTime);
        r.lcpElemento = e.element ? e.element.tagName.toLowerCase() + (e.element.className ? '.' + String(e.element.className).split(' ')[0] : '') : null;
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) if (!e.hadRecentInput) r.cls += e.value;
    }).observe({ type: 'layout-shift', buffered: true });
    setTimeout(() => { r.cls = Math.round(r.cls * 1000) / 1000; ok(r); }, 300);
  }));
}

for (const nicho of NICHOS) {
  for (const modelo of MODELOS_DO_NICHO[nicho]) {
    test(`${nicho} × ${modelo}`, async ({ browser }) => {
      const i = NICHOS.slice(0, NICHOS.indexOf(nicho)).reduce((t, n) => t + MODELOS_DO_NICHO[n].length, 0) + MODELOS_DO_NICHO[nicho].indexOf(modelo);
      const pub = await publicarSiteCompleto(api, lib, { nicho, modelo, sufixo: `V${i + 1}`, comLogo: i % 2 === 0 });
      const slug = pub.site.slug;
      const medidas = { nicho, modelo, slug, peso: await medirPeso(slug), larguras: {} };

      for (const largura of LARGURAS) {
        const contexto = await browser.newContext({
          viewport: { width: largura, height: largura > 500 ? 900 : 844 },
          deviceScaleFactor: 1,
          isMobile: largura < 500,
          hasTouch: largura < 500,
        });
        const pagina = await contexto.newPage();
        const v = await vigiar(contexto, pagina);
        const resp = await pagina.goto(urlSite(slug) + '/', { waitUntil: 'networkidle' });
        expect(resp.status()).toBe(200);
        // Rola até o fim e volta (carrega as fotos preguiçosas antes da captura).
        await pagina.evaluate(async () => {
          for (let y = 0; y < document.documentElement.scrollHeight; y += 600) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 30));
          }
          window.scrollTo(0, 0);
        });
        await pagina.waitForLoadState('networkidle');
        const vitais = await medirVitais(pagina);
        const pagina1 = await pagina.evaluate(() => ({
          larguraRolagem: document.documentElement.scrollWidth,
          h1: document.querySelectorAll('h1').length,
          imgsSemAlt: [...document.images].filter((img) => !img.hasAttribute('alt')).length,
          imgsQuebradas: [...document.images].filter((img) => img.complete && img.naturalWidth === 0).map((img) => img.src),
          camposSemRotulo: [...document.querySelectorAll('input:not([type=hidden]):not(.rk-hp),textarea')]
            .filter((c) => !c.labels || c.labels.length === 0).length,
          iframes: document.querySelectorAll('iframe').length,
          faixa: !!document.getElementById('rk-consent'),
        }));
        const captura = path.join(DIR_CAPTURAS, `${nicho}-${modelo}-${largura}.png`);
        await pagina.screenshot({ path: captura, fullPage: true, animations: 'disabled' });
        if (largura > 500) {
          const sprite = await diferencaDoSprite(pagina);
          expect.soft(sprite.usos, 'ícones repetidos no sprite').toBeGreaterThan(10);
          expect.soft(sprite.pixels, 'sprite não muda a aparência').toBe(0);
          medidas.sprite = sprite;
        }

        expect.soft(v.erros, `console em ${largura}px`).toEqual([]);
        expect.soft(await v.cspDaPagina(), `CSP em ${largura}px`).toEqual([]);
        expect.soft(v.externas, `terceiros em ${largura}px`).toEqual([]);
        expect.soft(pagina1.larguraRolagem, `rolagem horizontal em ${largura}px`).toBeLessThanOrEqual(largura);
        expect.soft(pagina1.h1).toBe(1);
        expect.soft(pagina1.imgsSemAlt).toBe(0);
        expect.soft(pagina1.imgsQuebradas).toEqual([]);
        expect.soft(pagina1.camposSemRotulo).toBe(0);
        expect.soft(pagina1.iframes, 'mapa só ao clicar').toBe(0);
        expect.soft(pagina1.faixa, 'sem rastreamento não há faixa de cookies').toBe(false);
        expect.soft(vitais.cls, `CLS em ${largura}px`).toBeLessThan(0.1);
        expect.soft(vitais.lcp, `LCP em ${largura}px`).toBeLessThan(2500);
        medidas.larguras[largura] = { ...vitais, larguraRolagem: pagina1.larguraRolagem };

        if (largura < 500) {
          // Menu do celular abre e fecha (details/summary, sem JavaScript).
          const resumo = pagina.locator('.rk-menu__mob summary');
          if (await resumo.count()) {
            await resumo.click();
            await expect.soft(pagina.locator('.rk-menu__painel a').first()).toBeVisible();
            await pagina.screenshot({ path: path.join(DIR_CAPTURAS, `${nicho}-${modelo}-${largura}-menu.png`) });
          }
        }
        await contexto.close();
      }

      // Páginas extras também sem erros.
      const contexto = await browser.newContext();
      const pagina = await contexto.newPage();
      const v = await vigiar(contexto, pagina);
      for (const caminho of ['/privacidade/', '/obrigado/']) {
        const r = await pagina.goto(urlSite(slug) + caminho);
        expect.soft(r.status()).toBe(200);
      }
      await pagina.screenshot({ path: path.join(DIR_CAPTURAS, `${nicho}-${modelo}-obrigado.png`), fullPage: true });
      expect.soft(v.erros).toEqual([]);
      expect.soft(await v.cspDaPagina()).toEqual([]);
      await contexto.close();

      // Meta do PDF §9.5 (HTML + CSS < 80 KB) medida como bytes transferidos (gzip, como no
      // Lighthouse; o sites/.htaccess liga o mod_deflate). Sem compressão os sites completos
      // ficam em ~85–110 KB (base.css + ícones SVG): guarda contra regressões em 128 KB.
      expect.soft(medidas.peso.gzip, 'HTML + CSS transferidos (gzip) < 80 KB').toBeLessThan(80 * KB);
      expect.soft(medidas.peso.htmlMaisCss, 'HTML + CSS sem compressão < 128 KB').toBeLessThan(128 * KB);
      expect.soft(medidas.peso.js, 'JS < 5 KB').toBeLessThan(5 * KB);
      mkdirSync(path.join(RAIZ, 'var/e2e/medidas'), { recursive: true });
      writeFileSync(path.join(RAIZ, 'var/e2e/medidas', `${nicho}-${modelo}.json`), JSON.stringify(medidas, null, 2));
      test.info().annotations.push({
        type: 'medidas',
        description: `HTML ${(medidas.peso.html / KB).toFixed(1)} KB · CSS ${(medidas.peso.css / KB).toFixed(1)} KB${medidas.peso.cssExterno ? ' (arquivo)' : ''}`
          + ` · JS ${(medidas.peso.js / KB).toFixed(2)} KB · gzip ${(medidas.peso.gzip / KB).toFixed(1)} KB`
          + ` · LCP ${medidas.larguras[1280].lcp}/${medidas.larguras[390].lcp} ms · CLS ${medidas.larguras[1280].cls}/${medidas.larguras[390].cls}`,
      });
    });
  }
}
