// Botão da IA vira barra de porcentagem enquanto os textos são escritos: a IA do servidor é
// simulada pela rota (resposta atrasada de propósito), o resto é o editor real.
import { test, expect } from '@playwright/test';
import { Api, estado } from './apoio/ambiente.mjs';

const ATRASO_MS = 3500;

test('"Escrever com IA": o botão enche como barra com porcentagem e volta ao normal no fim', async ({ page, playwright }) => {
  const e = estado();
  const api = await Api.entrar(playwright.request);
  const { site } = await api.criarSite({ nicho: 'clinicas', modelo: 'moderno', fotosExemplo: false, dados: { nome: 'Clínica Barra', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' } });
  await page.route('**/api/ia', (rota) => rota.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ disponivel: true }) }));
  await page.route(`**/api/sites/${site.id}/ia`, async (rota) => {
    await new Promise((r) => setTimeout(r, ATRASO_MS));
    await rota.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ textos: { 'hero.titulo': 'Título escrito pela IA de teste' }, remover: [], listas: {}, iconesRemover: [], avisos: [] }),
    });
  });
  const login = await page.request.post(`${e.api}/api/auth/login`, { data: { email: e.usuario.email, senha: e.usuario.senha } });
  expect(login.ok()).toBeTruthy();
  await page.goto(`${e.api}/editor/#/site/${site.id}`);
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();

  // Estimativa curta guardada no navegador (como se as últimas gerações tivessem levado 4 s).
  await page.evaluate(() => localStorage.setItem('rk-duracao-ia-site', '4000'));
  await page.locator('.ed-barra__ia').click();
  const janela = page.getByRole('dialog', { name: 'Escrever o site com IA' });
  await janela.getByRole('textbox').fill('Clínica odontológica em Jundiaí com implantes, lentes de contato dental e atendimento humanizado.');
  const botao = janela.locator('.modal__acoes .btn--primario');
  await botao.click();

  // Durante a espera: o botão inteiro é a barra, com texto e porcentagem que só sobem.
  await expect(botao).toHaveClass(/btn--barra/);
  await expect(botao).toBeDisabled();
  await expect(botao).toHaveText(/(Escrevendo os textos|Revisando os textos|Finalizando)… \d{1,2}%/);
  const lerPorcentagem = async () => Number((await botao.locator('.btn-barra__texto').first().textContent()).match(/(\d+)%/)?.[1]);
  const p1 = await lerPorcentagem();
  await page.waitForTimeout(1500);
  const p2 = await lerPorcentagem();
  expect(p2).toBeGreaterThan(p1);
  expect(p2).toBeLessThan(96);
  const largura = await botao.evaluate((b) => getComputedStyle(b).getPropertyValue('--barra'));
  expect(largura.trim()).toBe(`${p2}%`);
  await botao.screenshot({ path: test.info().outputPath('botao-barra.png') });

  // No fim: 100%, janela fecha e o texto da IA entra no site.
  await expect(janela).toBeHidden({ timeout: ATRASO_MS + 5000 });
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toHaveText('Título escrito pela IA de teste');
  // A duração real entra na estimativa da próxima vez.
  const nova = Number(await page.evaluate(() => localStorage.getItem('rk-duracao-ia-site')));
  expect(nova).toBeGreaterThan(4000);
});

test('erro da IA: a barra some e o botão volta a funcionar', async ({ page, playwright }) => {
  const e = estado();
  const api = await Api.entrar(playwright.request);
  const { site } = await api.criarSite({ nicho: 'clinicas', modelo: 'moderno', fotosExemplo: false, dados: { nome: 'Clínica Barra Erro', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' } });
  await page.route('**/api/ia', (rota) => rota.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ disponivel: true }) }));
  await page.route(`**/api/sites/${site.id}/ia`, async (rota) => {
    await new Promise((r) => setTimeout(r, 800));
    await rota.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ erro: { codigo: 'ia_falhou', mensagem: 'A IA não respondeu agora. Tente de novo.' } }) });
  });
  const login = await page.request.post(`${e.api}/api/auth/login`, { data: { email: e.usuario.email, senha: e.usuario.senha } });
  expect(login.ok()).toBeTruthy();
  await page.goto(`${e.api}/editor/#/site/${site.id}`);
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();

  await page.locator('.ed-barra__ia').click();
  const janela = page.getByRole('dialog', { name: 'Escrever o site com IA' });
  await janela.getByRole('textbox').fill('Clínica odontológica em Jundiaí com implantes e atendimento humanizado.');
  const botao = janela.locator('.modal__acoes .btn--primario');
  await botao.click();
  await expect(botao).toHaveClass(/btn--barra/);
  await expect(janela.getByRole('alert')).toHaveText('A IA não respondeu agora. Tente de novo.');
  await expect(botao).not.toHaveClass(/btn--barra/);
  await expect(botao).toBeEnabled();
  await expect(botao).toHaveText('Escrever com IA');
});
