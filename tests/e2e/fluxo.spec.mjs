// Fluxo completo pelo editor (contrato §9 e §10): entrar → assistente (tipo, modelo, dados) →
// editar um texto direto no site → checklist de publicação (resolver o registro da OAB pelo
// "Ir até lá") → publicar → abrir o site → enviar o formulário → ver o contato no painel.
// Seletores por papel e texto (o que a pessoa vê), sem depender de classes do editor.
import { test, expect } from '@playwright/test';
import { estado, vigiar, preparar } from './apoio/ambiente.mjs';

const NOME = 'Fluxo Editor Advocacia';
const TITULO = 'Advocacia trabalhista com atendimento direto';

test('entrar, criar pelo assistente, editar, publicar e receber o contato', async ({ page, context }) => {
  const e = estado();
  const errosDoEditor = [];
  page.on('pageerror', (err) => errosDoEditor.push(err.message));

  // Entrar.
  await page.goto(e.api + '/editor/');
  await expect(page.getByRole('heading', { name: 'Entrar', level: 1 })).toBeVisible();
  await page.getByLabel('E-mail').fill(e.usuario.email);
  await page.getByLabel('Senha', { exact: true }).fill(e.usuario.senha);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();

  // Assistente: tipo de negócio → modelo → dados.
  await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Novo site' }).click();
  await expect(page.getByRole('heading', { name: 'Qual é o tipo do seu negócio?' })).toBeVisible();
  await page.getByRole('button', { name: /^Advocacia/ }).click();
  await expect(page.getByRole('heading', { name: /Escolha um modelo/ })).toBeVisible();
  await page.getByRole('listitem', { name: 'Direto' }).getByRole('button', { name: 'Usar este modelo' }).click();
  const dados = page.getByRole('form', { name: 'Conte sobre o seu negócio' });
  await expect(dados).toBeVisible();
  await dados.getByLabel('Nome da empresa').fill(NOME);
  await dados.getByLabel('Cidade').fill('Campinas');
  await dados.getByLabel('Estado').selectOption('SP');
  await dados.getByLabel('WhatsApp', { exact: true }).fill('(19) 99876-5432');
  await dados.getByRole('button', { name: 'Gerar meu site' }).click();

  // Editor: troca o título principal direto no site.
  await expect(page).toHaveURL(/#\/site\/\d+$/);
  const titulo = page.getByRole('textbox', { name: 'Editar texto: Título principal' });
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type(TITULO);
  await page.keyboard.press('Enter');
  await expect(titulo).toHaveText(TITULO);
  await expect(page.getByRole('button', { name: /^Desfazer/ })).toBeEnabled();
  await expect(page.getByRole('status').filter({ hasText: /^Salvo$/ }).first()).toBeVisible({ timeout: 15_000 });

  // Publicar: o checklist bloqueia sem o número da OAB; "Ir até lá" leva ao campo.
  await page.getByRole('button', { name: 'Publicar', exact: true }).click();
  let checklist = page.getByRole('dialog', { name: 'Publicar o site' });
  await expect(checklist.getByText(/OAB/).first()).toBeVisible();
  await expect(checklist.getByRole('button', { name: 'Publicar agora' })).toBeDisabled();
  await checklist.getByRole('button', { name: 'Ir até lá' }).click();
  const registro = page.getByRole('region', { name: /Registro/ });
  await expect(registro.getByRole('textbox', { name: /Número/ })).toBeFocused();
  await registro.getByRole('textbox', { name: /Número/ }).fill('123456');
  await registro.getByRole('combobox', { name: 'UF' }).selectOption('SP');
  await registro.getByRole('textbox', { name: 'Responsável técnico' }).fill('Dra. Helena Moraes');
  await expect(page.getByRole('status').filter({ hasText: /^Salvo$/ }).first()).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Publicar', exact: true }).click();
  checklist = page.getByRole('dialog', { name: 'Publicar o site' });
  await expect(checklist.getByRole('button', { name: 'Publicar agora' })).toBeEnabled();
  await checklist.getByRole('button', { name: 'Publicar agora' }).click();
  const publicado = page.getByRole('dialog', { name: 'Site publicado!' });
  await expect(publicado).toBeVisible({ timeout: 30_000 });
  const url = await publicado.getByRole('link', { name: 'Abrir site' }).getAttribute('href');
  expect(url).toMatch(new RegExp(`^http://fluxoeditoradvocacia\\.localhost:${e.portaSites}/?$`));

  // O site publicado: título editado, OAB no rodapé, sem erros nem violações de CSP.
  const site = await context.newPage();
  const v = await vigiar(context, site);
  await site.goto(url);
  await expect(site.getByRole('heading', { level: 1 })).toHaveText(TITULO);
  await expect(site.getByText(/OAB.?SP 123456/).first()).toBeVisible();
  preparar('limites');
  await site.waitForTimeout(3100); // envios em menos de 3 s são descartados como robô
  const form = site.locator('form[data-form]').first();
  await form.getByLabel(/^Nome/).fill('Lead do Fluxo');
  await form.getByLabel(/WhatsApp ou telefone/).fill('(19) 98888-1111');
  await form.getByRole('button').click();
  await expect(form).toHaveAttribute('data-estado', 'ok');
  expect(v.erros).toEqual([]);
  expect(await v.cspDaPagina()).toEqual([]);
  await site.close();

  // De volta ao editor → painel → contatos do site.
  await publicado.getByRole('button', { name: 'Continuar editando' }).click();
  await page.getByRole('link', { name: 'Voltar ao painel de sites' }).click();
  const cartao = page.getByRole('listitem', { name: new RegExp(NOME) });
  await expect(cartao.getByText('Publicado', { exact: true })).toBeVisible();
  await cartao.getByRole('link', { name: /^Contatos de/ }).click();
  await expect(page).toHaveURL(/#\/site\/\d+\/leads$/);
  await expect(page.getByText('Lead do Fluxo').first()).toBeVisible();
  await expect(page.getByText('(19) 98888-1111').first()).toBeVisible();

  expect(errosDoEditor).toEqual([]);
});
