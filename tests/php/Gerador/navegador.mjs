// Verificação no Chromium (Playwright) de um site publicado, usada pelo SitesNoServidorTest.
// Uso: node tests/php/Gerador/navegador.mjs <url-base-do-site> <formulario|consentimento>
// Imprime um JSON com o que foi observado (o teste PHP faz as asserções).
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const require = createRequire(path.join(raiz, 'package.json'));
const { chromium } = require('@playwright/test');

const [base, modo] = process.argv.slice(2);
const r = { erros: [], externas: [], csp: [] };

const navegador = await chromium.launch({ args: ['--host-resolver-rules=MAP *.localhost 127.0.0.1'] });
try {
  const contexto = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
  // Nada sai para a internet: requisições externas são anotadas e respondidas localmente.
  await contexto.route(/^https?:\/\/(?!([a-z0-9-]+\.)?localhost[:/])/, (rota) => {
    r.externas.push(rota.request().url());
    const js = /\.js(\?|$)|gtm\.js|gtag\/js|fbevents/.test(rota.request().url());
    rota.fulfill({ status: 200, contentType: js ? 'text/javascript' : 'text/html', body: js ? '' : '<!doctype html><title>mapa</title>' });
  });
  await contexto.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (e) => {
      (window.__csp = window.__csp || []).push(e.violatedDirective + ' ' + e.blockedURI);
    });
  });
  const pagina = await contexto.newPage();
  pagina.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') r.erros.push(`${m.type()}: ${m.text()}`);
  });
  pagina.on('pageerror', (e) => r.erros.push('pageerror: ' + e.message));

  if (modo === 'formulario') {
    await pagina.goto(base + '/?utm_source=google&utm_campaign=teste&gclid=abc123', { waitUntil: 'networkidle' });
    r.externasAntes = r.externas.length;
    r.origem = await pagina.evaluate(() => sessionStorage.getItem('rk_origem'));
    // Navega para outra página e volta: a origem da sessão continua a mesma.
    await pagina.goto(base + '/privacidade/', { waitUntil: 'networkidle' });
    await pagina.goto(base + '/', { waitUntil: 'networkidle' });
    r.origemDepois = await pagina.evaluate(() => sessionStorage.getItem('rk_origem'));
    await pagina.waitForTimeout(3200); // _t mínimo do servidor é 3 s
    const form = pagina.locator('form[data-form]').first();
    r.formularios = await pagina.locator('form[data-form]').count();
    await form.locator('input[name="nome"]').fill('Carla Navegador');
    await form.locator('input[name="telefone"]').fill('(11) 98888-7777');
    await form.locator('textarea[name="mensagem"]').fill('Quero agendar uma avaliação.');
    await form.locator('[type="submit"]').click();
    await pagina.waitForFunction((f) => f.getAttribute('data-estado') !== 'enviando', await form.elementHandle(), { timeout: 10000 });
    r.estado = await form.getAttribute('data-estado');
    r.okVisivel = await pagina.locator('.rk-form__ok').first().isVisible();
    r.ocultos = await form.evaluate((f) => Object.fromEntries([...f.querySelectorAll('input[type="hidden"]')].map((i) => [i.name, i.value])));
    // Fachada do mapa: o iframe só existe depois do clique.
    const botaoMapa = pagina.locator('[data-mapa]').first();
    r.temMapa = (await botaoMapa.count()) > 0;
    r.iframesAntes = await pagina.locator('iframe').count();
    if (r.temMapa) {
      await botaoMapa.scrollIntoViewIfNeeded();
      await botaoMapa.click();
      await pagina.waitForSelector('.rk-mapa iframe');
      r.iframe = await pagina.locator('.rk-mapa iframe').evaluate((f) => ({ src: f.src, title: f.title, loading: f.loading }));
    }
    // Celular: nada de rolagem horizontal.
    await pagina.setViewportSize({ width: 390, height: 844 });
    await pagina.goto(base + '/', { waitUntil: 'networkidle' });
    r.larguraCelular = await pagina.evaluate(() => document.documentElement.scrollWidth);
    await pagina.goto(base + '/obrigado/', { waitUntil: 'networkidle' });
    r.tituloObrigado = await pagina.title();
  } else {
    await pagina.goto(base + '/', { waitUntil: 'networkidle' });
    r.faixaVisivel = await pagina.locator('#rk-consent').isVisible();
    r.externasAntesDoAceite = [...r.externas];
    r.negadoPorPadrao = await pagina.evaluate(() => (window.dataLayer || []).some((x) => x && x[0] === 'consent' && x[1] === 'default'
      && x[2].ad_storage === 'denied' && x[2].analytics_storage === 'denied'));
    await pagina.locator('#rk-consent [data-escolha="aceito"]').click();
    await pagina.waitForTimeout(400);
    r.faixaDepois = await pagina.locator('#rk-consent').isVisible();
    r.externasDepoisDoAceite = [...r.externas];
    r.escolha = await pagina.evaluate(() => localStorage.getItem('rk_consentimento'));
    // Clique no WhatsApp gera o evento de conversão no dataLayer.
    const wa = pagina.locator('a[data-ev="whatsapp"][data-pos="hero"]').first();
    await wa.evaluate((a) => a.addEventListener('click', (e) => e.preventDefault()));
    await wa.click();
    r.eventos = await pagina.evaluate(() => (window.dataLayer || []).filter((x) => x && x.event && x.event.startsWith('rankly_')).map((x) => ({ event: x.event, posicao: x.posicao })));
    // Nova visita: a escolha foi guardada (sem faixa, GTM carrega direto).
    r.externas = [];
    await pagina.reload({ waitUntil: 'networkidle' });
    r.faixaNaVolta = await pagina.locator('#rk-consent').count() > 0 && await pagina.locator('#rk-consent').isVisible();
    r.externasNaVolta = [...r.externas];
  }
  r.csp = await pagina.evaluate(() => window.__csp || []);
} finally {
  await navegador.close();
}
process.stdout.write(JSON.stringify(r));
