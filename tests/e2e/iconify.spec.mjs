// Ícones do Iconify no seletor de ícones: busca (resposta da API simulada pela rota, no mesmo
// formato que o servidor devolve), escolha, salvamento pelo servidor real (que confere o desenho),
// desfazer e o ícone na prévia. O site publicado usa o desenho guardado no documento.
import { test, expect } from '@playwright/test';
import { Api, estado } from './apoio/ambiente.mjs';

const SVG = (d) => `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path fill="currentColor" d="${d}"/></svg>`;
const RESPOSTA = {
  termo: 'dente',
  icones: [
    { id: 'mdi:tooth', nome: 'tooth', colecao: 'mdi', svg: { fino: SVG('M7 2C4 2 2 5 2 8c0 2 1 5 2 6s2 8 4 8c4.5 0 2-7 4-7s-.5 7 4 7c2 0 3-7 4-8s2-4 2-6c0-3-2-6-5-6s-3 1-5 1s-2-1-5-1') } },
    { id: 'ph:tooth', nome: 'tooth', colecao: 'ph', svg: { fino: SVG('M1 1h22v22H1z'), duotone: SVG('M2 2h20v20H2z'), preenchido: SVG('M3 3h18v18H3z') } },
  ],
  colecoes: { mdi: 'Material Design', ph: 'Phosphor' },
};

test('buscar no Iconify, escolher, salvar no servidor e desfazer', async ({ page, playwright }) => {
  const e = estado();
  const api = await Api.entrar(playwright.request);
  const { site } = await api.criarSite({ nicho: 'clinicas', modelo: 'moderno', fotosExemplo: false, dados: { nome: 'Clínica Iconify', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' } });
  const pedidos = [];
  await page.route('**/api/icones/buscar?**', async (rota) => {
    pedidos.push(new URL(rota.request().url()).searchParams);
    await rota.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(RESPOSTA) });
  });
  const login = await page.request.post(`${e.api}/api/auth/login`, { data: { email: e.usuario.email, senha: e.usuario.senha } });
  expect(login.ok()).toBeTruthy();
  await page.goto(`${e.api}/editor/#/site/${site.id}`);
  const icone = page.locator('#ed-site .serv [data-ic], #ed-site [data-ic^="serv."]').first();
  await expect(icone).toBeVisible();
  const chave = await icone.getAttribute('data-ic');
  await icone.click();

  const janela = page.getByRole('dialog', { name: 'Escolher ícone' });
  await expect(janela.getByText('Digite acima para buscar também')).toBeVisible();
  await janela.getByRole('searchbox', { name: 'Buscar ícone' }).fill('dente');
  const grade = janela.getByRole('group', { name: 'Ícones do Iconify' });
  await expect(grade.getByRole('button')).toHaveCount(2);
  await expect(janela.getByRole('status').filter({ hasText: '2 ícones encontrados' })).toBeVisible();
  // O editor manda dicas em inglês tiradas da biblioteca (ícone "tooth" casa com "dente").
  expect(pedidos.at(-1).get('q')).toBe('dente');
  expect(pedidos.at(-1).get('dicas') ?? '').toContain('tooth');

  await grade.getByRole('button', { name: /tooth.*Phosphor/ }).click();
  await expect(janela).toBeHidden();
  // Prévia: o desenho do peso do acabamento do modelo (moderno → duotone).
  await expect(page.locator(`#ed-site [data-ic="${chave}"] path`).first()).toHaveAttribute('d', 'M2 2h20v20H2z');
  await expect(page.locator('.ed-barra__status')).toHaveText('Salvo', { timeout: 15_000 });

  // O servidor aceitou o desenho e guardou só o ícone usado.
  const salvo = (await api.obterSite(site.id)).site.documento;
  expect(salvo.icones[chave]).toBe('ph:tooth');
  expect(Object.keys(salvo.iconesExtras)).toEqual(['ph:tooth']);
  expect(salvo.iconesExtras['ph:tooth'].svg.duotone).toBe(RESPOSTA.icones[1].svg.duotone);

  // Reabrir mostra o escolhido logo depois do "Automático"; desfazer volta ao ícone da biblioteca.
  await page.locator(`#ed-site [data-ic="${chave}"]`).first().click();
  await expect(janela.locator('.ed-icones__grade').first().locator('[aria-pressed="true"]')).toContainText('Iconify');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Desfazer' }).click();
  await expect(page.locator(`#ed-site [data-ic="${chave}"] path`).first()).not.toHaveAttribute('d', 'M2 2h20v20H2z');
  await expect(page.locator('.ed-barra__status')).toHaveText('Salvo', { timeout: 15_000 });
  const depois = (await api.obterSite(site.id)).site.documento;
  expect(depois.icones[chave]).toBeUndefined();
  expect(depois.iconesExtras).toEqual({});
});

test('desenho adulterado é recusado pelo servidor ao salvar', async ({ playwright }) => {
  const api = await Api.entrar(playwright.request);
  const { site } = await api.criarSite({ nicho: 'clinicas', modelo: 'moderno', fotosExemplo: false, dados: { nome: 'Clínica Iconify Ruim', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' } });
  const atual = (await api.obterSite(site.id)).site;
  const documento = {
    ...atual.documento,
    icones: { 'serv.1': 'mdi:tooth' },
    iconesExtras: { 'mdi:tooth': { nome: 'x', svg: { fino: SVG('M1 1').replace('<path', '<path onload="alert(1)"') } } },
  };
  const r = await api.chamar('PUT', `/api/sites/${site.id}`, { data: { revisao: atual.revisao, documento } });
  expect(r.status).toBe(422);
  expect(r.corpo.erro.mensagem).toContain('não é aceito');
});
