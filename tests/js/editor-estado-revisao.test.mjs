// Revisão adversarial do EstadoEditor (public_html/editor/js/estado.mjs): casos de corrida
// entre o salvamento automático, as novas tentativas sem conexão, o "salvar antes de publicar"
// e a troca de rota (destruir → carregar de novo o mesmo site). A API falsa deixa o teste
// decidir QUANDO cada PUT responde.

import { test, mock, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  EstadoEditor, armazenamentoMemoria, ESPERA_SALVAR_MS, INTERVALO_NOVA_TENTATIVA_MS, STATUS,
} from '../../public_html/editor/js/estado.mjs';

const docBase = () => ({
  versaoEsquema: 2,
  nicho: 'clinicas',
  modelo: 'moderno',
  estilo: { cor: '#c23b6e', fonte: 'editorial', acabamento: 'moderno', whatsappFlutuante: true },
  dados: { nome: 'Clínica A', cidade: 'Jundiaí' },
  secoes: [{ tipo: 'header', opcao: 'simples' }, { tipo: 'hero', opcao: 'cards-flutuantes' }],
  textos: {},
  listas: {},
  imagens: {},
  icones: {},
  confirmados: [],
  seo: { titulo: null, descricao: null },
});

function erroApi(status, dados = {}) {
  const e = new Error(status === 0 ? 'Sem conexão' : `HTTP ${status}`);
  e.name = 'ErroApi';
  e.status = status;
  e.dados = dados;
  return e;
}

/**
 * API falsa com controle de revisão. `segurar = true` deixa cada PUT pendente até
 * `liberar()` (responde o mais antigo). `offline = true` faz o PUT falhar (status 0).
 */
function criarApi({ revisao = 1, documento = docBase() } = {}) {
  const s = { revisao, documento, offline: false, segurar: false, fila: [], puts: [], gets: 0 };
  const responder = (corpo) => {
    if (s.offline) throw erroApi(0);
    if (corpo.revisao !== s.revisao) throw erroApi(409, { revisaoAtual: s.revisao, documento: s.documento });
    s.revisao += 1;
    s.documento = corpo.documento;
    return { revisao: s.revisao };
  };
  s.put = (url, corpo) => {
    s.puts.push(JSON.parse(JSON.stringify(corpo)));
    if (!s.segurar) return Promise.resolve().then(() => responder(corpo));
    return new Promise((resolver, rejeitar) => {
      s.fila.push(() => {
        try {
          resolver(responder(corpo));
        } catch (e) {
          rejeitar(e);
        }
      });
    });
  };
  s.get = async () => {
    s.gets += 1;
    return { site: { id: 7, revisao: s.revisao, documento: JSON.parse(JSON.stringify(s.documento)) }, midia: {} };
  };
  s.liberar = () => s.fila.shift()?.();
  return s;
}

async function esvaziar(vezes = 8) {
  for (let i = 0; i < vezes; i++) await new Promise((r) => setImmediate(r));
}

beforeEach(() => mock.timers.enable({ apis: ['setTimeout'] }));
afterEach(() => mock.timers.reset());

const opcoes = (api, extra = {}) => ({
  api, janela: null, documento: null, armazenamento: armazenamentoMemoria(), conflitoPadrao: false, ...extra,
});

test('sem conexão: alteração feita enquanto a nova tentativa está no ar também é enviada', async () => {
  const api = criarApi();
  const e = new EstadoEditor({ siteId: 7, doc: docBase(), revisao: 1 }, opcoes(api));
  api.offline = true;
  e.aplicar((d) => { d.textos.a = '1'; });
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(e.status, STATUS.OFFLINE);

  // A conexão volta; a nova tentativa sai, e durante ela a pessoa continua digitando.
  api.offline = false;
  api.segurar = true;
  mock.timers.tick(INTERVALO_NOVA_TENTATIVA_MS);
  await esvaziar();
  assert.equal(api.puts.length, 2);
  e.aplicar((d) => { d.textos.b = 'durante a tentativa'; });
  api.liberar();
  await esvaziar();
  api.segurar = false;
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(api.documento.textos.b, 'durante a tentativa', 'a alteração feita durante o envio chega ao servidor');
  assert.equal(e.status, STATUS.SALVO);
  assert.equal(e.pendente, false);
  e.destruir();
});

test('salvar() com um envio no ar só resolve true depois de enviar também o que mudou durante ele', async () => {
  const api = criarApi();
  api.segurar = true;
  const e = new EstadoEditor({ siteId: 7, doc: docBase(), revisao: 1 }, opcoes(api));
  e.aplicar((d) => { d.textos.a = 'primeiro'; });
  mock.timers.tick(ESPERA_SALVAR_MS); // salvamento automático sai e fica no ar
  await esvaziar();
  assert.equal(api.puts.length, 1);
  // Ex.: "Publicar" confirma o texto em edição e pede para salvar antes de validar.
  e.aplicar((d) => { d.textos.a = 'último'; });
  let resolvido = null;
  const p = e.salvar().then((ok) => { resolvido = ok; });
  api.liberar(); // responde o primeiro PUT
  await esvaziar();
  if (resolvido === null) {
    assert.equal(api.puts.length, 2, 'o segundo PUT sai logo depois do primeiro');
    api.liberar();
    await esvaziar();
  }
  await p;
  assert.equal(resolvido, true);
  assert.equal(api.documento.textos.a, 'último', 'quando salvar() diz true, o servidor tem a versão atual');
  assert.equal(e.pendente, false);
  e.destruir();
});

test('trocar de rota e voltar com um envio no ar não gera conflito consigo mesmo', async () => {
  const api = criarApi();
  const base = opcoes(api);
  const e1 = await EstadoEditor.carregar(7, base);
  e1.aplicar((d) => { d.textos.a = 'antes de sair'; });
  api.segurar = true;
  e1.destruir(); // desmontar: envia o pendente (fica no ar) e guarda a cópia local
  await esvaziar();
  assert.equal(api.puts.length, 1);
  const abrindo = EstadoEditor.carregar(7, base);
  await esvaziar();
  api.liberar();
  api.segurar = false;
  const e2 = await abrindo;
  await esvaziar();
  mock.timers.tick(ESPERA_SALVAR_MS);
  await esvaziar();
  assert.equal(e2.status, STATUS.SALVO, 'sem conflito');
  assert.equal(e2.conflito, null);
  assert.equal(e2.doc.textos.a, 'antes de sair');
  assert.equal(e2.revisao, api.revisao);
  e2.destruir();
});
