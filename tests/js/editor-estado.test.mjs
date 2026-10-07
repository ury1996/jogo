// Testes do estado do editor (public_html/editor/js/estado.mjs): histórico, agrupamento,
// desfazer/refazer, cópias rasas por camada e a fila de salvamento com fetch simulado
// (debounce, envio durante alteração, conflito 409, sem conexão + IndexedDB, recuperação).

import { test, mock, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  EstadoEditor, calcularNovoDocumento, armazenamentoMemoria, PASSOS_HISTORICO, ESPERA_SALVAR_MS,
  INTERVALO_NOVA_TENTATIVA_MS, STATUS,
} from '../../public_html/editor/js/estado.mjs';
import { api } from '../../public_html/editor/js/api.mjs';

const docBase = () => ({
  versaoEsquema: 2,
  nicho: 'clinicas',
  modelo: 'moderno',
  estilo: { cor: '#c23b6e', fonte: 'editorial', acabamento: 'moderno', whatsappFlutuante: true },
  dados: { nome: 'Clínica A', cidade: 'Jundiaí', endereco: { cep: '' } },
  secoes: [{ tipo: 'header', opcao: 'simples' }, { tipo: 'hero', opcao: 'cards-flutuantes' }],
  textos: {},
  listas: {},
  imagens: {},
  icones: {},
  confirmados: [],
  seo: { titulo: null, descricao: null },
});

/** Resposta mínima no formato de fetch (sem streams, para o teste controlar os timers). */
function resposta(status, corpo) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json; charset=utf-8' : null) },
    json: async () => JSON.parse(JSON.stringify(corpo)),
    text: async () => JSON.stringify(corpo),
  };
}

/** Servidor falso: PUT /api/sites/7 com controle de revisão; modo "offline" faz o fetch falhar. */
function criarServidor({ revisao = 1, documento = docBase() } = {}) {
  const s = {
    revisao,
    documento,
    offline: false,
    falha500: false,
    chamadas: [],
  };
  s.fetch = async (url, init = {}) => {
    const corpo = init.body ? JSON.parse(init.body) : null;
    s.chamadas.push({ url, metodo: init.method, corpo, cabecalhos: init.headers, keepalive: init.keepalive });
    if (s.offline) throw new TypeError('fetch failed');
    if (s.falha500) return resposta(500, { erro: { codigo: 'erro_interno', mensagem: 'Ocorreu um erro inesperado.' } });
    if (init.method === 'PUT' && /\/api\/sites\/7$/.test(url)) {
      if (corpo.revisao !== s.revisao) {
        return resposta(409, {
          erro: { codigo: 'conflito', mensagem: 'Este site foi alterado em outra janela.' },
          revisaoAtual: s.revisao,
          documento: s.documento,
        });
      }
      s.revisao += 1;
      s.documento = corpo.documento;
      return resposta(200, { revisao: s.revisao });
    }
    if (init.method === 'GET' && /\/api\/sites\/7$/.test(url)) {
      return resposta(200, { site: { id: 7, revisao: s.revisao, documento: s.documento }, midia: {} });
    }
    return resposta(404, { erro: { codigo: 'nao_encontrado', mensagem: 'Não encontrado.' } });
  };
  s.puts = () => s.chamadas.filter((c) => c.metodo === 'PUT');
  return s;
}

/** Deixa as promessas pendentes (fetch falso, IndexedDB falso) andarem. */
async function esvaziar(vezes = 6) {
  for (let i = 0; i < vezes; i++) await new Promise((r) => setImmediate(r));
}

let fetchOriginal;
let servidor;
beforeEach(() => {
  fetchOriginal = globalThis.fetch;
  servidor = criarServidor();
  globalThis.fetch = servidor.fetch;
  mock.timers.enable({ apis: ['setTimeout'] });
});
afterEach(() => {
  mock.timers.reset();
  globalThis.fetch = fetchOriginal;
});

function novoEstado(extra = {}) {
  return new EstadoEditor({
    siteId: 7,
    doc: docBase(),
    revisao: 1,
    midia: {},
    janela: null,
    documento: null,
    armazenamento: armazenamentoMemoria(),
    conflitoPadrao: false,
    ...extra,
  });
}

/* ------------------------------------------------------------------ cópias rasas por camada */

test('calcularNovoDocumento: só a camada alterada é nova; as outras são as mesmas do passo anterior', () => {
  const doc = docBase();
  const novo = calcularNovoDocumento(doc, (d) => {
    d.textos['hero.titulo'] = 'Novo título';
    return d;
  });
  assert.notEqual(novo, doc);
  assert.notEqual(novo.textos, doc.textos);
  assert.deepEqual(doc.textos, {}, 'o documento anterior não pode mudar');
  assert.equal(novo.textos['hero.titulo'], 'Novo título');
  for (const camada of ['estilo', 'dados', 'secoes', 'listas', 'imagens', 'icones', 'confirmados', 'seo']) {
    assert.equal(novo[camada], doc[camada], `camada ${camada} deveria ser compartilhada`);
  }
});

test('calcularNovoDocumento: structuredClone só do que é lido; leitura sem mudança volta a compartilhar', () => {
  const doc = docBase();
  const espiao = mock.method(globalThis, 'structuredClone');
  const novo = calcularNovoDocumento(doc, (d) => {
    d.dados.endereco.cep = '13201-005';
    void d.estilo.cor; // lida, mas não alterada
  });
  assert.equal(espiao.mock.callCount(), 2, 'só dados e estilo foram clonados');
  espiao.mock.restore();
  assert.equal(novo.dados.endereco.cep, '13201-005');
  assert.equal(doc.dados.endereco.cep, '');
  assert.equal(novo.estilo, doc.estilo, 'camada lida e igual volta a ser a mesma');
  assert.equal(calcularNovoDocumento(doc, (d) => { void d.textos; return d; }), null, 'nada mudou → null');
});

test('calcularNovoDocumento: aceita um documento novo (ex.: aplicarModelo) e reaproveita camadas iguais', () => {
  const doc = docBase();
  const novo = calcularNovoDocumento(doc, (d) => {
    const c = JSON.parse(JSON.stringify(d));
    c.modelo = 'classico';
    c.secoes = [{ tipo: 'header', opcao: 'barra' }];
    return c;
  });
  assert.equal(novo.modelo, 'classico');
  assert.equal(novo.textos, doc.textos);
  assert.equal(novo.dados, doc.dados);
  assert.notEqual(novo.secoes, doc.secoes);
  const semSeo = calcularNovoDocumento(doc, (d) => { delete d.seo; });
  assert.equal('seo' in semSeo, false, 'remover camada também é mudança');
});

/* ------------------------------------------------------------------ histórico */

test('histórico guarda no máximo 80 passos e desfaz/refaz na ordem', () => {
  const e = novoEstado();
  for (let i = 1; i <= 100; i++) {
    e.aplicar((d) => { d.textos['hero.titulo'] = `v${i}`; }, { rotulo: `passo ${i}` });
  }
  assert.equal(PASSOS_HISTORICO, 80);
  assert.equal(e.passosDesfazer, 80);
  assert.equal(e.doc.textos['hero.titulo'], 'v100');
  assert.deepEqual(e.desfazer(), { rotulo: 'passo 100' });
  assert.equal(e.doc.textos['hero.titulo'], 'v99');
  for (let i = 0; i < 79; i++) e.desfazer();
  assert.equal(e.doc.textos['hero.titulo'], 'v20', 'os 20 primeiros passos saíram do histórico');
  assert.equal(e.podeDesfazer, false);
  assert.equal(e.desfazer(), null);
  assert.equal(e.passosRefazer, 80);
  assert.deepEqual(e.refazer(), { rotulo: 'passo 21' });
  assert.equal(e.doc.textos['hero.titulo'], 'v21');
  e.aplicar((d) => { d.textos['hero.titulo'] = 'outro caminho'; }, { rotulo: 'novo' });
  assert.equal(e.podeRefazer, false, 'alteração nova descarta o refazer');
  e.destruir();
});

test('passos do histórico compartilham as camadas que não mudaram', () => {
  const e = novoEstado();
  const dadosAntes = e.doc.dados;
  e.aplicar((d) => { d.textos['hero.titulo'] = 'a'; });
  e.aplicar((d) => { d.estilo.cor = '#2a7f86'; });
  assert.equal(e.doc.dados, dadosAntes);
  e.desfazer();
  assert.equal(e.doc.estilo.cor, '#c23b6e');
  assert.equal(e.doc.textos['hero.titulo'], 'a');
  assert.equal(e.doc.dados, dadosAntes);
  e.destruir();
});

test('agrupamento: alterações seguidas da mesma chave viram um passo', () => {
  const e = novoEstado();
  for (const nome of ['C', 'Cl', 'Cli', 'Clín']) {
    e.aplicar((d) => { d.dados.nome = nome; }, { rotulo: 'Nome', agrupar: 'dados.nome' });
  }
  assert.equal(e.passosDesfazer, 1);
  e.aplicar((d) => { d.dados.cidade = 'Campinas'; }, { rotulo: 'Cidade', agrupar: 'dados.cidade' });
  assert.equal(e.passosDesfazer, 2, 'outra chave abre outro passo');
  e.aplicar((d) => { d.dados.nome = 'Clínica B'; }, { rotulo: 'Nome', agrupar: 'dados.nome' });
  assert.equal(e.passosDesfazer, 3, 'voltar à chave anterior depois de outra abre passo novo');
  e.encerrarGrupo();
  e.aplicar((d) => { d.dados.nome = 'Clínica BC'; }, { rotulo: 'Nome', agrupar: 'dados.nome' });
  assert.equal(e.passosDesfazer, 4, 'encerrarGrupo fecha o grupo');
  e.aplicar((d) => { d.estilo.cor = '#111111'; }, { rotulo: 'Cor', agrupar: true });
  e.aplicar((d) => { d.estilo.cor = '#222222'; }, { rotulo: 'Cor', agrupar: true });
  assert.equal(e.passosDesfazer, 5, 'agrupar: true usa o rótulo como chave');
  e.desfazer();
  assert.equal(e.doc.estilo.cor, '#c23b6e', 'desfaz o grupo inteiro de uma vez');
  e.desfazer();
  assert.equal(e.doc.dados.nome, 'Clínica B');
  e.desfazer();
  e.desfazer();
  assert.equal(e.doc.dados.cidade, 'Jundiaí');
  e.desfazer();
  assert.equal(e.doc.dados.nome, 'Clínica A');
  e.destruir();
});

test('historico: false altera sem criar passo; aplicar sem mudança não conta', () => {
  const e = novoEstado();
  assert.equal(e.aplicar((d) => d), false);
  assert.equal(e.pendente, false);
  assert.equal(e.aplicar((d) => { d.textos.x = '1'; }, { historico: false }), true);
  assert.equal(e.passosDesfazer, 0);
  assert.equal(e.pendente, true);
  e.destruir();
});

test('assinar recebe doc e status; o cancelamento para os avisos', () => {
  const e = novoEstado();
  const eventos = [];
  const cancelar = e.assinar((ev) => eventos.push(ev.tipo === 'status' ? `status:${ev.status}` : `${ev.tipo}:${ev.origem ?? ''}`));
  e.aplicar((d) => { d.textos.a = '1'; });
  e.desfazer();
  cancelar();
  e.refazer();
  assert.deepEqual(eventos, ['status:salvando', 'doc:aplicar', 'doc:desfazer']);
  e.destruir();
});

/* ------------------------------------------------------------------ salvamento */

test('salva 1,5 s depois da última alteração, com a revisão, e passa para "salvo"', async () => {
  const e = novoEstado();
  e.aplicar((d) => { d.textos.a = '1'; });
  mock.timers.tick(1000);
  e.aplicar((d) => { d.textos.a = '12'; });
  assert.equal(e.status, STATUS.SALVANDO);
  mock.timers.tick(ESPERA_SALVAR_MS - 1);
  await esvaziar();
  assert.equal(servidor.puts().length, 0, 'ainda dentro da espera');
  mock.timers.tick(1);
  await esvaziar();
  assert.equal(servidor.puts().length, 1);
  assert.equal(servidor.puts()[0].corpo.revisao, 1);
  assert.equal(servidor.puts()[0].corpo.documento.textos.a, '12');
  assert.equal(e.revisao, 2);
  assert.equal(e.status, STATUS.SALVO);
  assert.equal(e.pendente, false);
  e.destruir();
});

test('alteração durante o envio gera um segundo PUT com a revisão nova', async () => {
  const e = novoEstado();
  e.aplicar((d) => { d.textos.a = '1'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  e.aplicar((d) => { d.textos.a = '2'; }); // o primeiro PUT ainda não respondeu
  await esvaziar();
  assert.equal(servidor.puts().length, 1);
  assert.equal(e.pendente, true);
  assert.equal(e.status, STATUS.SALVANDO);
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(servidor.puts().length, 2);
  assert.equal(servidor.puts()[1].corpo.revisao, 2);
  assert.equal(servidor.documento.textos.a, '2');
  assert.equal(e.status, STATUS.SALVO);
  e.destruir();
});

test('conflito 409 → aoConflito; "Manter a minha" reenvia com a revisão atual', async () => {
  servidor.revisao = 5;
  servidor.documento = { ...docBase(), textos: { 'hero.titulo': 'da outra janela' } };
  const e = novoEstado();
  const recebidos = [];
  e.aoConflito((info) => recebidos.push(info));
  e.aplicar((d) => { d.textos['hero.titulo'] = 'meu'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(e.status, STATUS.CONFLITO);
  assert.equal(recebidos.length, 1);
  assert.equal(recebidos[0].revisaoAtual, 5);
  assert.equal(recebidos[0].documento.textos['hero.titulo'], 'da outra janela');
  // Enquanto em conflito, novas alterações não disparam PUT.
  e.aplicar((d) => { d.textos['hero.texto'] = 'mais'; });
  mock.timers.tick(ESPERA_SALVAR_MS * 2);
  await esvaziar();
  assert.equal(servidor.puts().length, 1);
  await recebidos[0].manterMinha();
  await esvaziar();
  assert.equal(servidor.puts().length, 2);
  assert.equal(servidor.puts()[1].corpo.revisao, 5);
  assert.equal(servidor.documento.textos['hero.titulo'], 'meu');
  assert.equal(servidor.documento.textos['hero.texto'], 'mais');
  assert.equal(e.revisao, 6);
  assert.equal(e.status, STATUS.SALVO);
  e.destruir();
});

test('conflito 409 → "Carregar a versão mais nova" adota o documento do servidor (desfazível)', async () => {
  servidor.revisao = 3;
  servidor.documento = { ...docBase(), textos: { 'hero.titulo': 'da outra janela' } };
  const armazenamento = armazenamentoMemoria();
  const e = novoEstado({ armazenamento });
  let info = null;
  e.aoConflito((i) => { info = i; });
  e.aplicar((d) => { d.textos['hero.titulo'] = 'meu'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.ok(info);
  assert.equal(armazenamento.mapa.size, 1, 'a minha versão fica guardada enquanto decido');
  await info.carregarNova();
  assert.equal(e.doc.textos['hero.titulo'], 'da outra janela');
  assert.equal(e.revisao, 3);
  assert.equal(e.status, STATUS.SALVO);
  assert.equal(e.pendente, false);
  assert.equal(armazenamento.mapa.size, 0);
  assert.equal(e.rotuloDesfazer, 'Carregar a versão mais nova');
  e.desfazer();
  assert.equal(e.doc.textos['hero.titulo'], 'meu', 'dá para voltar à minha versão com Desfazer');
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(servidor.puts().at(-1).corpo.revisao, 3);
  assert.equal(servidor.documento.textos['hero.titulo'], 'meu');
  e.destruir();
});

test('sem conexão: guarda cópia local, tenta a cada 10 s e envia quando volta', async () => {
  const armazenamento = armazenamentoMemoria();
  const janela = new EventTarget();
  const e = novoEstado({ armazenamento, janela });
  servidor.offline = true;
  e.aplicar((d) => { d.textos.a = 'offline'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(e.status, STATUS.OFFLINE);
  assert.equal(e.pendente, true);
  const copia = await armazenamento.ler(7);
  assert.equal(copia.doc.textos.a, 'offline');
  assert.equal(copia.revisao, 1);

  // Alteração offline não tenta a cada 1,5 s: fica com a nova tentativa de 10 s.
  e.aplicar((d) => { d.textos.b = 'ainda offline'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(servidor.puts().length, 1);
  mock.timers.tick(INTERVALO_NOVA_TENTATIVA_MS - ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(servidor.puts().length, 2, 'nova tentativa depois de 10 s');
  assert.equal(e.status, STATUS.OFFLINE);
  assert.equal((await armazenamento.ler(7)).doc.textos.b, 'ainda offline');

  // A conexão volta: o evento "online" envia na hora.
  servidor.offline = false;
  janela.dispatchEvent(new Event('online'));
  await esvaziar();
  assert.equal(servidor.puts().length, 3);
  assert.equal(servidor.documento.textos.b, 'ainda offline');
  assert.equal(e.status, STATUS.SALVO);
  assert.equal(await armazenamento.ler(7), null, 'cópia local apagada depois de salvar');
  e.destruir();
});

test('erro 500: status "erro" e nova tentativa em 10 s', async () => {
  const e = novoEstado();
  servidor.falha500 = true;
  e.aplicar((d) => { d.textos.a = 'x'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(e.status, STATUS.ERRO);
  assert.equal(e.erro.status, 500);
  servidor.falha500 = false;
  mock.timers.tick(INTERVALO_NOVA_TENTATIVA_MS);
  await esvaziar();
  assert.equal(e.status, STATUS.SALVO);
  e.destruir();
});

test('cópia local da mesma revisão é recuperada na abertura (desfazível) e enviada', async () => {
  const armazenamento = armazenamentoMemoria();
  await armazenamento.guardar(7, { doc: { ...docBase(), textos: { a: 'não enviado' } }, revisao: 1, salvoEm: '2026-10-06T10:00:00Z' });
  const e = novoEstado({ armazenamento });
  await e.pronto;
  assert.equal(e.doc.textos.a, 'não enviado');
  assert.equal(e.pendente, true);
  assert.equal(e.rotuloDesfazer, 'Recuperar alterações não salvas');
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(servidor.documento.textos.a, 'não enviado');
  assert.equal(e.status, STATUS.SALVO);
  e.destruir();
});

test('cópia local de revisão antiga vira conflito com origem "copia-local"', async () => {
  servidor.revisao = 4;
  const armazenamento = armazenamentoMemoria();
  await armazenamento.guardar(7, { doc: { ...docBase(), textos: { a: 'antiga' } }, revisao: 2, salvoEm: '2026-10-06T10:00:00Z' });
  const recebidos = [];
  const e = new EstadoEditor({ siteId: 7, doc: docBase(), revisao: 4 }, {
    armazenamento, janela: null, documento: null, conflitoPadrao: false,
  });
  e.aoConflito((i) => recebidos.push(i));
  await e.pronto;
  assert.equal(e.status, STATUS.CONFLITO);
  assert.equal(recebidos.length, 1);
  assert.equal(recebidos[0].origem, 'copia-local');
  await recebidos[0].manterMinha();
  await esvaziar();
  assert.equal(servidor.documento.textos.a, 'antiga');
  e.destruir();
});

test('beforeunload pede confirmação só com alteração pendente; esconder a aba salva na hora', async () => {
  const janela = new EventTarget();
  const documento = new EventTarget();
  documento.visibilityState = 'visible';
  const e = novoEstado({ janela, documento });
  const antes = new Event('beforeunload', { cancelable: true });
  janela.dispatchEvent(antes);
  assert.equal(antes.defaultPrevented, false);
  e.aplicar((d) => { d.textos.a = 'pendente'; });
  const depois = new Event('beforeunload', { cancelable: true });
  janela.dispatchEvent(depois);
  assert.equal(depois.defaultPrevented, true);
  documento.visibilityState = 'hidden';
  documento.dispatchEvent(new Event('visibilitychange'));
  await esvaziar();
  assert.equal(servidor.puts().length, 1, 'salvou sem esperar o 1,5 s');
  assert.equal(servidor.puts()[0].keepalive, true);
  assert.equal(e.status, STATUS.SALVO);
  e.destruir();
});

test('aceita a resposta do GET /api/sites/{id} e semeia a troca de modelo no histórico', () => {
  const antes = docBase();
  EstadoEditor.semearHistorico(7, { antes, revisao: 9, rotulo: 'Trocar modelo' });
  const r = { site: { id: 7, revisao: 9, documento: { ...docBase(), modelo: 'classico' }, slug: 'a' }, midia: { m_1: { largura: 10 } } };
  const e = new EstadoEditor(r, { janela: null, documento: null, armazenamento: armazenamentoMemoria(), conflitoPadrao: false });
  assert.equal(e.siteId, 7);
  assert.equal(e.revisao, 9);
  assert.equal(e.midia.m_1.largura, 10);
  assert.equal(e.site.slug, 'a');
  assert.equal(e.rotuloDesfazer, 'Trocar modelo');
  e.desfazer();
  assert.equal(e.doc.modelo, 'moderno');
  assert.equal(EstadoEditor.ativo(7), e);
  e.destruir();
  assert.equal(EstadoEditor.ativo(7), null);
});

test('carregar() busca o site e destruir() envia o que estava pendente', async () => {
  servidor.revisao = 2;
  const e = await EstadoEditor.carregar(7, { janela: null, documento: null, armazenamento: armazenamentoMemoria(), conflitoPadrao: false });
  assert.equal(e.revisao, 2);
  e.aplicar((d) => { d.textos.z = 'ao sair'; });
  e.destruir();
  await esvaziar();
  assert.equal(servidor.puts().length, 1);
  assert.equal(servidor.documento.textos.z, 'ao sair');
});

test('definirMidia mescla e avisa; não entra no histórico', () => {
  const e = novoEstado();
  let avisos = 0;
  e.assinar((ev) => { if (ev.tipo === 'midia') avisos += 1; });
  e.definirMidia('m_abc', { local: 'blob:x', largura: 100 });
  e.definirMidia('m_abc', { largura: 200 });
  assert.deepEqual(e.midia.m_abc, { local: 'blob:x', largura: 200 });
  e.definirMidia('m_abc', null);
  assert.equal(e.midia.m_abc, undefined);
  assert.equal(avisos, 3);
  assert.equal(e.passosDesfazer, 0);
  e.destruir();
});

/* ------------------------------------------------------------------ api.mjs */

test('api: guarda o CSRF de /auth/eu e envia nas alterações; renova uma vez em 403 csrf', async () => {
  const chamadas = [];
  let tokenServidor = 'tok-2';
  globalThis.fetch = async (url, init = {}) => {
    chamadas.push({ url, metodo: init.method, csrf: init.headers?.['X-CSRF-Token'] });
    if (url === '/api/auth/eu') return resposta(200, { usuario: { id: 1 }, csrf: tokenServidor });
    if (init.headers?.['X-CSRF-Token'] !== tokenServidor) {
      return resposta(403, { erro: { codigo: 'csrf', mensagem: 'A página ficou desatualizada.' } });
    }
    return resposta(200, { ok: true });
  };
  api.definirCsrf('tok-velho');
  const r = await api.post('/sites/7/duplicar');
  assert.deepEqual(r, { ok: true });
  assert.deepEqual(chamadas.map((c) => `${c.metodo} ${c.url} ${c.csrf ?? '-'}`), [
    'POST /api/sites/7/duplicar tok-velho',
    'GET /api/auth/eu -',
    'POST /api/sites/7/duplicar tok-2',
  ]);
  assert.equal(api.obterCsrf(), 'tok-2');
});

test('api: ErroApi com status, código, mensagem e dados; sem conexão = status 0', async () => {
  globalThis.fetch = async () => resposta(422, { erro: { codigo: 'invalido', mensagem: 'Escolha um modelo válido.' }, campo: 'modelo' });
  await assert.rejects(api.post('/api/sites', {}), (e) => {
    assert.equal(e.name, 'ErroApi');
    assert.equal(e.status, 422);
    assert.equal(e.codigo, 'invalido');
    assert.equal(e.mensagem, 'Escolha um modelo válido.');
    assert.equal(e.dados.campo, 'modelo');
    return true;
  });
  globalThis.fetch = async () => { throw new TypeError('fetch failed'); };
  await assert.rejects(api.get('/sites'), (e) => e.status === 0 && e.semConexao && e.codigo === 'sem_conexao');
});

test('api: 401 fora das rotas de autenticação avisa "sessão expirou"', async () => {
  let avisos = 0;
  const cancelar = api.aoNaoAutenticado(() => { avisos += 1; });
  globalThis.fetch = async () => resposta(401, { erro: { codigo: 'nao_autenticado', mensagem: 'Sua sessão expirou.' } });
  await assert.rejects(api.get('/auth/eu'));
  assert.equal(avisos, 0, '/auth/eu responder 401 é normal');
  await assert.rejects(api.get('/sites'));
  assert.equal(avisos, 1);
  cancelar();
  assert.equal(api.url('/sites/1/leads.csv'), '/api/sites/1/leads.csv');
  assert.equal(api.url('/api/biblioteca'), '/api/biblioteca');
});
