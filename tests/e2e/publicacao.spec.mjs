// Publicação ponta a ponta pela API: cria o site, envia fotos e logo, completa o documento,
// valida, publica, abre o site no Chromium e envia o formulário (com e sem JavaScript),
// conferindo o lead na API e no banco; páginas extras, mapa sob demanda e consentimento.
import { test, expect } from '@playwright/test';
import { Api, publicarSiteCompleto, urlSite, vigiar, preparar, httpSite } from './apoio/ambiente.mjs';

test.describe.configure({ mode: 'serial' });

let api;
let lib;
let principal; // clinicas × direto (formulário no destaque + mapa), com logo
let rastreado; // advocacia × moderno com GTM configurado

test.beforeAll(async ({ playwright }) => {
  api = await Api.entrar(playwright.request);
  lib = await api.biblioteca();
  principal = await publicarSiteCompleto(api, lib, { nicho: 'clinicas', modelo: 'direto', sufixo: 'Publicação', comLogo: true });
  rastreado = await publicarSiteCompleto(api, lib, {
    nicho: 'advocacia', modelo: 'moderno', sufixo: 'Rastreado', comFotos: false, rastreamento: { gtm: 'GTM-E2E1234' },
  });
});

test.afterAll(async () => {
  await api?.encerrar();
});

test('a API publica o site com a URL do subdomínio e registra a versão', async () => {
  expect(principal.versao).toBe(1);
  expect(principal.url).toBe(urlSite(principal.site.slug));
  expect(principal.site.status).toBe('publicado');
  expect(principal.validacao.erros).toEqual([]);
  expect(principal.validacao.avisos.map((a) => a.codigo)).not.toContain('foto_faltando');
});

test('o site abre sem erros nem violações de CSP e envia o formulário por fetch', async ({ page, context }) => {
  const v = await vigiar(context, page);
  await page.goto(urlSite(principal.site.slug) + '/?utm_source=google&utm_campaign=e2e&gclid=teste123');
  await expect(page).toHaveTitle(/Clínica Sorriso Vivo Publicação/);
  await expect(page.locator('h1')).toHaveCount(1);
  expect(await page.locator('[data-k],[data-img],[data-ic],[data-li],[data-it],[data-sec]').count()).toBe(0);

  // Sem rastreamento: nenhuma faixa de cookies e nada de terceiros.
  await expect(page.locator('#rk-consent')).toHaveCount(0);

  // Mapa: o iframe só existe depois do clique.
  expect(await page.locator('iframe').count()).toBe(0);
  const botaoMapa = page.getByRole('button', { name: /carrega o mapa do Google/ });
  await botaoMapa.click();
  await expect(page.locator('.rk-mapa iframe')).toHaveAttribute('src', /^https:\/\/www\.google\.com\/maps/);

  preparar('limites');
  await page.waitForTimeout(3100); // o servidor descarta envios feitos em menos de 3 s
  const form = page.locator('form[data-form]').first();
  await form.getByLabel(/^Nome/).fill('Fernanda Teste E2E');
  await form.getByLabel(/WhatsApp ou telefone/).fill('(11) 97777-1234');
  await form.getByLabel(/Mensagem/).fill('Gostaria de agendar uma avaliação.');
  await form.getByRole('button').click();
  await expect(form).toHaveAttribute('data-estado', 'ok');
  await expect(page.getByText('Mensagem enviada!').first()).toBeVisible();

  expect(v.erros).toEqual([]);
  expect(await v.cspDaPagina()).toEqual([]);
  expect(v.externas.filter((u) => !u.startsWith('https://www.google.com/maps'))).toEqual([]);

  const { leads, total } = await api.leads(principal.site.id);
  expect(total).toBe(1);
  expect(leads[0]).toMatchObject({ nome: 'Fernanda Teste E2E', telefone: '(11) 97777-1234', mensagem: 'Gostaria de agendar uma avaliação.' });
  const noBanco = preparar('lead', principal.site.id);
  const origem = JSON.parse(noBanco.origem);
  expect(origem).toMatchObject({ utm_source: 'google', utm_campaign: 'e2e', gclid: 'teste123' });
  expect(origem.pagina).toContain('utm_source=google');
});

test('sem JavaScript o formulário faz POST normal e cai em /obrigado/ (303)', async ({ browser }) => {
  const contexto = await browser.newContext({ javaScriptEnabled: false });
  const page = await contexto.newPage();
  const respostas = [];
  page.on('response', (r) => { if (r.url().endsWith('/_lead')) respostas.push(r.status()); });
  await page.goto(urlSite(principal.site.slug) + '/');
  preparar('limites');
  const form = page.locator('form[data-form]').first();
  await form.getByLabel(/^Nome/).fill('Rodrigo Sem Script');
  await form.getByLabel(/WhatsApp ou telefone/).fill('11966665555');
  await Promise.all([page.waitForURL('**/obrigado/'), form.getByRole('button').click()]);
  expect(respostas).toEqual([303]);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Mensagem enviada!');
  await expect(page).toHaveTitle(/^Mensagem enviada/);
  const { leads } = await api.leads(principal.site.id);
  expect(leads.map((l) => l.nome)).toContain('Rodrigo Sem Script');
  await contexto.close();
});

test('privacidade, obrigado e 404 têm o mesmo cabeçalho e rodapé', async ({ page, context }) => {
  const v = await vigiar(context, page);
  const base = urlSite(principal.site.slug);

  const priv = await page.goto(base + '/privacidade/');
  expect(priv.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Política de privacidade');
  await expect(page.locator('.rk-sec--header')).toHaveCount(1);
  await expect(page.locator('.rk-sec--rodape')).toHaveCount(1);
  await expect(page.getByText(/12 meses/)).toBeVisible();
  // Âncoras do menu levam de volta à página inicial.
  const ancoras = await page.locator('.rk-sec--header a[href*="#"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  expect(ancoras.filter((h) => h.startsWith('#') && h !== '#rk-conteudo')).toEqual([]);

  expect(v.erros).toEqual([]);
  const nao = await page.goto(base + '/nao-existe/de-jeito-nenhum');
  expect(nao.status()).toBe(404);
  // O próprio documento 404 gera "Failed to load resource" no console: é o único erro aceito.
  expect(v.erros).toEqual([expect.stringMatching(/status of 404/)]);
  v.erros.length = 0;
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Página não encontrada');
  await expect(page.locator('.rk-sec--header')).toHaveCount(1);
  await page.getByRole('link', { name: 'Ir para a página inicial' }).click();
  await expect(page).toHaveURL(base + '/');

  const robots = await httpSite(principal.site.slug, '/robots.txt');
  expect(robots.corpo).toContain(`Sitemap: ${base}/sitemap.xml`);
  const sitemap = await httpSite(principal.site.slug, '/sitemap.xml');
  expect(sitemap.corpo).toContain(`<loc>${base}/privacidade/</loc>`);
  expect(sitemap.corpo).not.toContain('/obrigado/');

  expect(v.erros).toEqual([]);
  expect(await v.cspDaPagina()).toEqual([]);
});

test('JSON-LD com o tipo da especialidade e sem AggregateRating', async ({ page }) => {
  await page.goto(urlSite(principal.site.slug) + '/');
  const ld = JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());
  const negocio = ld['@graph'].find((n) => n['@id'].endsWith('#negocio'));
  expect(negocio['@type']).toBe('Dentist');
  expect(negocio.address).toMatchObject({ '@type': 'PostalAddress', addressLocality: 'Jundiaí', postalCode: '13201-005' });
  expect(negocio.openingHoursSpecification.length).toBeGreaterThan(0);
  expect(JSON.stringify(ld)).not.toContain('AggregateRating');
  expect(ld['@graph'].some((n) => n['@type'] === 'FAQPage')).toBe(true);
  // Favicon PNG gerado a partir do logo.
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', '/favicon.png');
  expect((await httpSite(principal.site.slug, '/favicon.png')).cabecalhos['content-type']).toBe('image/png');
});

test('com GTM: faixa de consentimento, nada carrega antes do aceite e a escolha é lembrada', async ({ page, context }) => {
  const v = await vigiar(context, page);
  await page.goto(urlSite(rastreado.site.slug) + '/');
  const faixa = page.getByRole('region', { name: 'Aviso de cookies' });
  await expect(faixa).toBeVisible();
  expect(v.externas).toEqual([]);
  const negado = await page.evaluate(() => (window.dataLayer || []).some((x) => x && x[0] === 'consent' && x[1] === 'default'
    && x[2].ad_storage === 'denied' && x[2].analytics_storage === 'denied'));
  expect(negado).toBe(true);

  await faixa.getByRole('button', { name: 'Aceitar' }).click();
  await expect(faixa).toBeHidden();
  await expect.poll(() => v.externas.some((u) => u.includes('googletagmanager.com/gtm.js?id=GTM-E2E1234'))).toBe(true);

  // Clique no WhatsApp gera o evento de conversão com a posição.
  const wa = page.locator('a[data-ev="whatsapp"][data-pos="hero"]').first();
  await wa.evaluate((a) => a.addEventListener('click', (e) => e.preventDefault()));
  await wa.click();
  const eventos = await page.evaluate(() => (window.dataLayer || []).filter((x) => x && x.event === 'rankly_whatsapp_click').map((x) => x.posicao));
  expect(eventos).toContain('hero');

  v.externas.length = 0;
  await page.reload();
  await expect(page.locator('#rk-consent')).toHaveCount(0);
  await expect.poll(() => v.externas.some((u) => u.includes('gtm.js'))).toBe(true);
  expect(v.erros).toEqual([]);
  expect(await v.cspDaPagina()).toEqual([]);

  // Recusar numa visita nova: nada carrega.
  const outro = await page.context().browser().newContext();
  const p2 = await outro.newPage();
  const v2 = await vigiar(outro, p2);
  await p2.goto(urlSite(rastreado.site.slug) + '/');
  await p2.getByRole('button', { name: 'Recusar' }).click();
  await p2.reload();
  await expect(p2.locator('#rk-consent')).toHaveCount(0);
  expect(v2.externas).toEqual([]);
  // Na política de privacidade dá para mudar de ideia.
  await p2.goto(urlSite(rastreado.site.slug) + '/privacidade/');
  await p2.getByRole('button', { name: /Alterar a minha escolha/ }).click();
  await expect(p2.getByRole('region', { name: 'Aviso de cookies' })).toBeVisible();
  await outro.close();
});

test('nova publicação troca o site inteiro e "voltar à anterior" restaura a versão 1 [M10]', async ({ page }) => {
  const id = principal.site.id;
  const base = urlSite(principal.site.slug);
  await page.goto(base + '/');
  const tituloV1 = (await page.getByRole('heading', { level: 1 }).textContent()).trim();

  const atual = await api.obterSite(id);
  const doc = structuredClone(atual.site.documento);
  doc.textos['hero.titulo'] = 'Título da segunda publicação';
  await api.salvarDocumento(id, atual.site.revisao, doc);
  const v2 = await api.publicar(id);
  expect(v2.versao).toBe(2);
  await page.goto(base + '/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Título da segunda publicação');

  const { versoes } = await api.exigir('GET', `/api/sites/${id}/versoes`);
  expect(versoes.map((v) => v.numero)).toEqual(expect.arrayContaining([1, 2]));

  const volta = await api.exigir('POST', `/api/sites/${id}/reverter`, { data: {} });
  expect(volta).toMatchObject({ versao: 1, url: base });
  await page.goto(base + '/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(tituloV1);
  expect((await api.obterSite(id)).site.publicadoVersao).toBe(1);
});
