// Revisão adversarial do editor contra o backend real: texto do usuário nunca vira HTML,
// caixa alta do CSS não vai para o documento, colagem cortada no limite, lista variável sem
// "ressuscitar" item [M2], desfazer depois de salvar, conflito 409 com outra janela, janelas e
// avisos que não sobrevivem à troca de rota, e foco do teclado ao remover uma seção.
import { test, expect } from '@playwright/test';
import { Api, estado, completarDocumento, dadosDoNegocio } from './apoio/ambiente.mjs';

/** Cria um site pela API e abre o editor dele (sessão no mesmo contexto do navegador). */
async function abrirEditor(page, playwright, { nicho = 'clinicas', modelo = 'moderno', nome = 'Clínica Revisão' } = {}) {
  const e = estado();
  const api = await Api.entrar(playwright.request);
  const { site } = await api.criarSite({ nicho, modelo, fotosExemplo: false, dados: { nome, cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' } });
  const login = await page.request.post(`${e.api}/api/auth/login`, { data: { email: e.usuario.email, senha: e.usuario.senha } });
  expect(login.ok()).toBeTruthy();
  await page.goto(`${e.api}/editor/#/sites`);
  await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();
  await page.goto(`${e.api}/editor/#/site/${site.id}`);
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();
  return { api, site };
}

const salvo = (page) => expect(page.locator('.ed-barra__status')).toHaveText('Salvo', { timeout: 15_000 });

async function docDoServidor(api, id) {
  return (await api.obterSite(id)).site;
}

test('texto do usuário nunca vira HTML (tela, barra e depois de recarregar)', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright);
  await page.addInitScript(() => { window.__xss = 0; });
  await page.evaluate(() => { window.__xss = 0; });
  const ataque = '<img src=x onerror="window.__xss=1">Oi';
  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type(ataque);
  await page.keyboard.press('Enter');
  await expect(titulo).toHaveText(ataque);
  await salvo(page);

  // Nome do negócio (aba Dados) com marcação: vai para a barra, títulos e textos com {nome}.
  await page.getByRole('tab', { name: 'Dados' }).click();
  const nome = page.getByRole('textbox', { name: /Nome do negócio/ });
  await nome.fill('<b id="xss-nome">Negrito</b>');
  await nome.blur();
  await salvo(page);
  await expect(page.locator('.ed-barra__nome')).toHaveText('<b id="xss-nome">Negrito</b>');

  await page.reload();
  await expect(titulo).toHaveText(ataque);
  expect(await page.locator('#ed-site img[src="x"]').count()).toBe(0);
  expect(await page.locator('#xss-nome').count()).toBe(0);
  expect(await page.evaluate(() => window.__xss)).toBe(0);
  const s = await docDoServidor(api, site.id);
  expect(s.documento.textos['hero.titulo']).toBe(ataque);
  await api.encerrar();
});

test('caixa alta do CSS não vai para o documento; colagem é cortada no limite com aviso', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { modelo: 'classico', nome: 'Clínica Caixa Alta' });
  // No acabamento clássico o rótulo acima do título (.rk-eyebrow) é text-transform: uppercase.
  const rotulo = page.locator('#ed-site .rk-eyebrow[data-k], #ed-site .rk-eyebrow [data-k]').first();
  await expect(rotulo).toBeVisible();
  expect(await rotulo.evaluate((n) => getComputedStyle(n.closest('.rk-eyebrow')).textTransform)).toBe('uppercase');
  const chave = await rotulo.getAttribute('data-k');
  await rotulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Atendimento Humano');
  await page.keyboard.press('Enter');
  await salvo(page);
  let s = await docDoServidor(api, site.id);
  expect(s.documento.textos[chave]).toBe('Atendimento Humano');

  // Colar 300 caracteres no título (máx. 90): entra só até o limite, numa linha, e avisa.
  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Delete');
  await page.evaluate(() => {
    const alvo = document.activeElement;
    const dt = new DataTransfer();
    dt.setData('text/plain', `${'a'.repeat(150)}\n${'b'.repeat(150)}`);
    dt.setData('text/html', '<b>html</b>');
    alvo.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  });
  await expect(page.getByText(/O texto colado foi cortado/)).toBeVisible();
  await expect(titulo).toHaveText('a'.repeat(90));
  await page.keyboard.press('Enter');
  await salvo(page);
  s = await docDoServidor(api, site.id);
  expect(s.documento.textos['hero.titulo']).toBe('a'.repeat(90));
  await api.encerrar();
});

test('[M2] remover item de lista não ressuscita outro, nem depois de recarregar; desfazer depois de salvar volta no servidor', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Listas' });
  const servicos = page.locator('#ed-site .rk-sec--servicos [data-li="serv"] [data-it]');
  const antes = await servicos.evaluateAll((ns) => ns.map((n) => n.dataset.it));
  expect(antes.length).toBeGreaterThan(3);
  await servicos.first().click({ position: { x: 5, y: 5 } });
  const remover = page.getByRole('button', { name: /^Remover serviço 2/ });
  await expect(remover).toBeVisible();
  await remover.click();
  await expect(servicos).toHaveCount(antes.length - 1);
  await salvo(page);
  let s = await docDoServidor(api, site.id);
  expect(s.documento.listas.serv).toEqual(antes.filter((id) => id !== antes[1]));

  await page.reload();
  await expect(servicos).toHaveCount(antes.length - 1);
  expect(await servicos.evaluateAll((ns) => ns.map((n) => n.dataset.it))).not.toContain(antes[1]);

  // Desfazer depois de salvar e recarregar não existe (histórico é da sessão); desfazer
  // depois de salvar na mesma sessão volta no servidor.
  await servicos.first().click({ position: { x: 5, y: 5 } });
  await page.getByRole('button', { name: /^Remover serviço 1/ }).click();
  await salvo(page);
  await page.getByRole('button', { name: /^Desfazer/ }).first().click();
  await salvo(page);
  s = await docDoServidor(api, site.id);
  expect(s.documento.listas.serv).toEqual(antes.filter((id) => id !== antes[1]));
  await api.encerrar();
});

test('conflito 409: alteração de outra janela → escolher "Carregar a versão mais nova" (desfazível)', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Conflito' });
  // Outra janela salva uma versão nova.
  const atual = await docDoServidor(api, site.id);
  const outro = structuredClone(atual.documento);
  outro.textos['hero.titulo'] = 'Da outra janela';
  await api.salvarDocumento(site.id, atual.revisao, outro);

  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Da minha janela');
  await page.keyboard.press('Enter');
  const janela = page.getByRole('dialog', { name: 'Este site foi alterado em outra janela' });
  await expect(janela).toBeVisible({ timeout: 15_000 });
  await janela.getByRole('button', { name: 'Carregar a versão mais nova' }).click();
  await expect(titulo).toHaveText('Da outra janela');
  await expect(page.locator('.ed-barra__status')).toHaveText('Salvo');
  await page.getByRole('button', { name: /^Desfazer: Carregar a versão mais nova/ }).click();
  await expect(titulo).toHaveText('Da minha janela');
  await salvo(page);
  const s = await docDoServidor(api, site.id);
  expect(s.documento.textos['hero.titulo']).toBe('Da minha janela');
  await api.encerrar();
});

test('trocar de rota fecha as janelas e os avisos com "Desfazer" do editor', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Rotas' });
  // Remove uma seção (aviso com "Desfazer") e abre a galeria "Adicionar seção".
  const lista = page.getByRole('list', { name: 'Seções do site, na ordem da página' });
  await lista.locator('li').nth(1).hover();
  await lista.locator('li').nth(1).getByRole('button', { name: /^Remover / }).click();
  const desfazerAviso = page.locator('.aviso').getByRole('button', { name: 'Desfazer' });
  await expect(desfazerAviso).toBeVisible();
  await page.getByRole('button', { name: 'Adicionar seção' }).click();
  await expect(page.getByRole('dialog', { name: 'Adicionar seção' })).toBeVisible();
  await salvo(page);
  const secoesAntes = (await docDoServidor(api, site.id)).documento.secoes.length;

  // Voltar do navegador (ou trocar o hash) com a janela aberta.
  await page.evaluate(() => { location.hash = '#/sites'; });
  await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(desfazerAviso).toHaveCount(0);
  // A tela nova não ficou inerte: dá para usar.
  expect(await page.evaluate(() => [...document.body.children].filter((n) => n.inert).map((n) => n.id || n.className))).toEqual([]);
  await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Novo site' }).click();
  await expect(page.getByRole('heading', { name: 'Qual é o tipo do seu negócio?' })).toBeVisible();
  const s = await docDoServidor(api, site.id);
  expect(s.documento.secoes.length).toBe(secoesAntes);
  await api.encerrar();
});

test('teclado: remover uma seção pelo painel mantém o foco na lista de seções', async ({ page, playwright }) => {
  const { api } = await abrirEditor(page, playwright, { nome: 'Clínica Teclado' });
  const lista = page.getByRole('list', { name: 'Seções do site, na ordem da página' });
  // Tab a partir do nome da seção revela as ações (↑ ↓ lixeira) e chega à lixeira.
  const segunda = lista.locator('li').nth(2);
  await segunda.locator('[data-acao="ir"]').focus();
  const nomeSecao = (await segunda.getAttribute('data-tipo'));
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(() => document.activeElement?.dataset?.acao === 'remover')) break;
  }
  expect(await page.evaluate(() => document.activeElement?.dataset?.acao)).toBe('remover');
  await page.keyboard.press('Enter');
  await expect(lista.locator(`li[data-tipo="${nomeSecao}"]`)).toHaveCount(0);
  await expect(page.locator('.aviso').getByRole('button', { name: 'Desfazer' })).toBeVisible();
  const foco = await page.evaluate(() => {
    const a = document.activeElement;
    return { tag: a?.tagName, naLista: Boolean(a?.closest('.ed-secoes')), rotulo: a?.getAttribute('aria-label') ?? a?.textContent };
  });
  expect(foco.naLista, `foco foi para ${foco.tag} ${foco.rotulo}`).toBe(true);
  // Enter de novo continua funcionando no item focado (remove a próxima seção).
  await api.encerrar();
});

test('teclado: remover item de lista pelo botão da tela atualiza os botões e mantém o foco neles', async ({ page, playwright }) => {
  const { api } = await abrirEditor(page, playwright, { nome: 'Clínica Itens Teclado' });
  const servicos = page.locator('#ed-site .rk-sec--servicos [data-li="serv"] [data-it]');
  const total = await servicos.count();
  await servicos.first().click({ position: { x: 5, y: 5 } });
  const botoes = page.locator('.ed-camada .ed-item-remover');
  await expect(botoes).toHaveCount(total);
  await botoes.nth(1).focus();
  await page.keyboard.press('Enter');
  await expect(servicos).toHaveCount(total - 1);
  // Os botões acompanham a lista nova (um por item) e o foco continua num deles.
  await expect(botoes).toHaveCount(total - 1);
  expect(await page.evaluate(() => Boolean(document.activeElement?.closest('.ed-camada')))).toBe(true);
  const ids = await servicos.evaluateAll((ns) => ns.map((n) => n.dataset.it));
  await page.keyboard.press('Enter'); // remove o item que ficou no lugar
  await expect(servicos).toHaveCount(total - 2);
  expect(await servicos.evaluateAll((ns) => ns.map((n) => n.dataset.it))).toEqual(ids.filter((id) => id !== ids[1]));
  await api.encerrar();
});

test('foto: sair do editor durante o envio cancela o envio e não grava nada depois', async ({ page, playwright }) => {
  const e = estado();
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Envio' });
  let liberar;
  const segurando = new Promise((r) => { liberar = r; });
  await page.route('**/api/media', async (rota) => {
    await segurando;
    await rota.continue().catch(() => {});
  });
  const puts = [];
  page.on('request', (r) => { if (r.method() === 'PUT' && r.url().endsWith(`/api/sites/${site.id}`)) puts.push(r.url()); });
  await salvo(page);
  const revisaoAntes = (await docDoServidor(api, site.id)).revisao;

  const foto = page.locator('#ed-site [data-img="hero.img"]').first();
  const [seletor] = await Promise.all([page.waitForEvent('filechooser'), foto.click()]);
  await seletor.setFiles(e.fotos[0]);
  await expect(page.getByRole('progressbar', { name: 'Enviando foto' })).toBeVisible();
  await page.evaluate(() => { location.hash = '#/sites'; });
  await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();
  liberar();
  await page.waitForTimeout(2500); // mais que a espera do salvamento automático
  expect(puts).toEqual([]);
  await expect(page.getByText(/Envio cancelado|Foto enviada/)).toHaveCount(0);
  const s = await docDoServidor(api, site.id);
  expect(s.revisao).toBe(revisaoAntes);
  expect(s.documento.imagens['hero.img'] ?? null).toBeNull();
  await api.encerrar();
});

test('foto: troca rápida de duas fotos em campos diferentes liga cada uma ao seu campo', async ({ page, playwright }) => {
  const e = estado();
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Duas Fotos' });
  const chaves = await page.locator('#ed-site [data-img]:not([data-fundo])').evaluateAll((ns) => [...new Set(ns.map((n) => n.dataset.img))].slice(0, 2));
  expect(chaves.length).toBe(2);
  for (const [i, chave] of chaves.entries()) {
    const [seletor] = await Promise.all([page.waitForEvent('filechooser'), page.locator(`#ed-site [data-img="${chave}"]`).first().click()]);
    await seletor.setFiles(e.fotos[i % e.fotos.length]);
  }
  await expect(page.getByRole('progressbar')).toHaveCount(0, { timeout: 30_000 });
  await salvo(page);
  const s = await docDoServidor(api, site.id);
  for (const chave of chaves) expect(s.documento.imagens[chave]).toMatch(/^m_/);
  expect(s.documento.imagens[chaves[0]]).not.toBe(s.documento.imagens[chaves[1]]);
  // Desfazer tira só a última.
  await page.getByRole('button', { name: /^Desfazer: Trocar foto/ }).click();
  await salvo(page);
  const s2 = await docDoServidor(api, site.id);
  expect(s2.documento.imagens[chaves[0]]).toBe(s.documento.imagens[chaves[0]]);
  expect(s2.documento.imagens[chaves[1]] ?? null).toBeNull();
  await api.encerrar();
});

test('CEP: resposta atrasada de um CEP que a pessoa já mudou não sobrescreve o endereço', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica CEP' });
  await page.route('https://viacep.com.br/**', async (rota) => {
    await new Promise((r) => setTimeout(r, 1500));
    await rota.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ cep: '13201-005', logradouro: 'Rua Barão de Jundiaí', bairro: 'Centro', localidade: 'Campinas', uf: 'SP' }),
    }).catch(() => {});
  });
  await page.getByRole('tab', { name: 'Dados' }).click();
  const cep = page.getByRole('textbox', { name: /CEP/ });
  await cep.fill('13201005');
  await expect(page.getByText('Buscando o endereço…')).toBeVisible();
  await cep.press('Backspace'); // muda de ideia antes da resposta
  await page.waitForTimeout(2200);
  await salvo(page);
  const s = await docDoServidor(api, site.id);
  expect(s.documento.dados.cidade).toBe('Jundiaí');
  expect(s.documento.dados.endereco?.logradouro ?? '').toBe('');
  expect(s.documento.dados.endereco?.cep ?? '').not.toBe('13201-005');
  await expect(page.getByText(/Endereço encontrado/)).toHaveCount(0);
  await api.encerrar();
});

test('publicar: Enter duas vezes seguidas (teclado) abre um checklist só', async ({ page, playwright }) => {
  const { api } = await abrirEditor(page, playwright, { nome: 'Clínica Dois Cliques' });
  await page.route('**/api/sites/*/validar', async (rota) => {
    await new Promise((r) => setTimeout(r, 800));
    await rota.continue().catch(() => {});
  });
  // O CSS bloqueia o segundo clique do mouse (aria-busy), mas não o teclado.
  const publicar = page.getByRole('button', { name: 'Publicar', exact: true });
  await publicar.focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog', { name: 'Publicar o site' })).toBeVisible();
  await page.waitForTimeout(1500);
  // (uma janela de cima deixa a de baixo inerte, fora da árvore de acessibilidade: conta no DOM)
  await expect(page.locator('.modal-fundo [role="dialog"]')).toHaveCount(1);
  await api.encerrar();
});

test('sem conexão: guarda no navegador, sobrevive a sair do editor e é enviado ao voltar', async ({ page, context, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Offline' });
  await salvo(page);
  await context.setOffline(true);
  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Escrito sem internet');
  await page.keyboard.press('Enter');
  await expect(page.locator('.ed-barra__status')).toHaveText('Sem conexão, tentando de novo', { timeout: 15_000 });
  // Sai do editor ainda sem conexão (a cópia fica no IndexedDB).
  await page.evaluate(() => { location.hash = '#/sites'; });
  await page.waitForTimeout(500);
  expect((await docDoServidor(api, site.id)).documento.textos['hero.titulo'] ?? null).toBeNull();
  await context.setOffline(false);
  await page.evaluate((id) => { location.hash = `#/site/${id}`; }, site.id);
  await expect(titulo).toHaveText('Escrito sem internet');
  await expect(page.getByText('Recuperamos alterações que não tinham chegado ao servidor.')).toBeVisible();
  await salvo(page);
  expect((await docDoServidor(api, site.id)).documento.textos['hero.titulo']).toBe('Escrito sem internet');
  // Desfazer a recuperação volta ao que o servidor tinha.
  await page.getByRole('button', { name: /^Desfazer: Recuperar alterações não salvas/ }).click();
  await salvo(page);
  expect((await docDoServidor(api, site.id)).documento.textos['hero.titulo'] ?? null).toBeNull();
  await api.encerrar();
});

test('[M4] retokeniza o nome ao confirmar; texto apagado volta ao padrão e Desfazer deixa vazio', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Token' });
  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  const padrao = await titulo.textContent();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Bem-vindo à Clínica Token');
  await page.keyboard.press('Enter');
  await salvo(page);
  expect((await docDoServidor(api, site.id)).documento.textos['hero.titulo']).toBe('Bem-vindo à {nome}');
  await page.getByRole('tab', { name: 'Dados' }).click();
  const nome = page.getByRole('textbox', { name: /Nome do negócio/ });
  await nome.fill('Clínica Nova');
  await nome.blur();
  await expect(titulo).toHaveText('Bem-vindo à Clínica Nova');

  // Apagar tudo e confirmar: volta ao padrão, com aviso; "Desfazer" deixa vazio.
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Delete');
  await page.keyboard.press('Enter');
  await expect(page.getByText('Texto restaurado ao padrão.', { exact: false })).toBeVisible();
  await expect(titulo).toHaveText(padrao.replace('Clínica Token', 'Clínica Nova'));
  await page.locator('.aviso').filter({ hasText: 'Texto restaurado' }).getByRole('button', { name: 'Desfazer' }).click();
  await expect(titulo).toHaveText('');
  await salvo(page);
  expect((await docDoServidor(api, site.id)).documento.textos['hero.titulo']).toBe('');
  await api.encerrar();
});

test('trocar de rota várias vezes não deixa ouvintes do editor na página', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Ouvintes' });
  const cdp = await page.context().newCDPSession(page);
  async function contar() {
    const r = {};
    for (const expr of ['document', 'window']) {
      const { result } = await cdp.send('Runtime.evaluate', { expression: expr });
      const { listeners } = await cdp.send('DOMDebugger.getEventListeners', { objectId: result.objectId });
      for (const l of listeners) r[`${expr}:${l.type}`] = (r[`${expr}:${l.type}`] ?? 0) + 1;
    }
    return r;
  }
  await page.evaluate(() => { location.hash = '#/sites'; });
  await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();
  const antes = await contar();
  for (let i = 0; i < 3; i++) {
    await page.evaluate((id) => { location.hash = `#/site/${id}`; }, site.id);
    await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();
    await page.getByRole('button', { name: 'Adicionar seção' }).click(); // janela com miniaturas
    await page.evaluate(() => { location.hash = '#/sites'; });
    await expect(page.getByRole('heading', { name: 'Meus sites', level: 1 })).toBeVisible();
  }
  expect(await contar()).toEqual(antes);
  await api.encerrar();
});

test('publicar: alegações exigem confirmação, que vai para o documento; depoimento de exemplo nunca é confirmável', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Alegações' });
  const lib = await api.biblioteca();
  const atual = await docDoServidor(api, site.id);
  const doc = completarDocumento({ ...atual.documento, dados: dadosDoNegocio('clinicas', 'Alegações') }, lib);
  doc.confirmados = [];
  await api.salvarDocumento(site.id, atual.revisao, doc);
  await page.reload();
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();

  await page.getByRole('button', { name: 'Publicar', exact: true }).click();
  const checklist = page.getByRole('dialog', { name: 'Publicar o site' });
  await expect(checklist.getByText('Confirme que é verdade')).toBeVisible();
  const publicar = checklist.getByRole('button', { name: 'Publicar agora' });
  await expect(publicar).toBeDisabled();
  const caixas = checklist.getByRole('checkbox');
  const n = await caixas.count();
  expect(n).toBeGreaterThan(0);
  for (let i = 0; i < n; i++) await caixas.nth(i).check();
  await expect(publicar).toBeEnabled();
  await publicar.click();
  await expect(page.getByRole('dialog', { name: 'Site publicado!' })).toBeVisible({ timeout: 30_000 });
  const s = await docDoServidor(api, site.id);
  expect(s.documento.confirmados.length).toBe(n);
  expect(s.documento.confirmados).not.toContain('dep');
  expect(s.status).toBe('publicado');

  // Volta o depoimento ao texto de exemplo: bloqueia, sem caixa de confirmação para ele.
  await page.getByRole('dialog', { name: 'Site publicado!' }).getByRole('button', { name: 'Continuar editando' }).click();
  const r = await docDoServidor(api, site.id);
  const semDep = structuredClone(r.documento);
  for (const k of Object.keys(semDep.textos)) if (k.startsWith('dep.')) delete semDep.textos[k];
  await api.salvarDocumento(site.id, r.revisao, semDep);
  await page.reload();
  await expect(page.locator('#ed-site [data-k="hero.titulo"]').first()).toBeVisible();
  await page.getByRole('button', { name: 'Publicar', exact: true }).click();
  const c2 = page.getByRole('dialog', { name: 'Publicar o site' });
  await expect(c2.getByText(/Resolva antes de publicar/)).toBeVisible();
  await expect(c2.getByRole('checkbox')).toHaveCount(0);
  await expect(c2.getByRole('button', { name: 'Publicar agora' })).toBeDisabled();
  await api.encerrar();
});

test('trocar modelo no editor e desfazer: textos editados ficam e o servidor volta ao modelo anterior', async ({ page, playwright }) => {
  const { api, site } = await abrirEditor(page, playwright, { nome: 'Clínica Modelo' });
  const titulo = page.locator('#ed-site [data-k="hero.titulo"]').first();
  await titulo.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Título que fica');
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Trocar modelo' }).click();
  await page.getByRole('dialog', { name: 'Trocar modelo' }).getByRole('button', { name: /^Modelo Clássico/ }).click();
  await expect(titulo).toHaveText('Título que fica');
  await salvo(page);
  let s = await docDoServidor(api, site.id);
  expect(s.documento.modelo).toBe('classico');
  expect(s.documento.textos['hero.titulo']).toBe('Título que fica');
  await page.getByRole('button', { name: /^Desfazer: Trocar para o modelo/ }).click();
  await salvo(page);
  s = await docDoServidor(api, site.id);
  expect(s.documento.modelo).toBe('moderno');
  expect(s.documento.textos['hero.titulo']).toBe('Título que fica');
  await page.getByRole('button', { name: /^Refazer: Trocar para o modelo/ }).click();
  await salvo(page);
  expect((await docDoServidor(api, site.id)).documento.modelo).toBe('classico');
  await api.encerrar();
});
