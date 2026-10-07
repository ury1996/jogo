// Testes das funções puras das telas do editor (EDITOR-BASE): rotas do app (§9), login,
// painel de sites, assistente (cap. 6: exemplo do nicho, documento, corpo do POST, validação,
// cores com a do logo) e leads (origem legível, resumo).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { lerHash, resolverRota, ROTAS } from '../../public_html/editor/js/app.mjs';
import { problemaEmail, problemaSenhaNova } from '../../public_html/editor/js/login.mjs';
import { textoLeadsNovos, textoQuantidadeSites, corDoSite } from '../../public_html/editor/js/sites.mjs';
import { descreverOrigem, resumoLeads } from '../../public_html/editor/js/leads.mjs';
import {
  dadosExemplo, documentoExemplo, documentoDoAssistente, corpoCriacao, errosDadosNegocio, coresParaEscolher,
  corPadrao, metaModelo, nichoNoTitulo, dadosLimpos, estadoInicial, MAX_NOME, MAX_CIDADE,
} from '../../public_html/editor/js/assistente.mjs';
import { CORES_SUGERIDAS } from '../../public_html/editor/js/ui.mjs';
import { receitaModelo, aplicarModelo } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { prepararSite } from '../../public_html/editor/js/compartilhado/preparo.mjs';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));

/* ------------------------------------------------------------------ rotas */

test('rotas por hash do §9 levam ao módulo e parâmetros certos', () => {
  const casos = [
    ['', './sites.mjs', {}],
    ['#/', './sites.mjs', {}],
    ['#/sites', './sites.mjs', {}],
    ['#/entrar', './login.mjs', { modo: 'entrar' }],
    ['#/esqueci', './login.mjs', { modo: 'esqueci' }],
    ['#/redefinir?token=abc', './login.mjs', { modo: 'redefinir', token: 'abc' }],
    ['#/novo', './assistente.mjs', { passo: 'nicho' }],
    ['#/novo/modelo', './assistente.mjs', { passo: 'modelo' }],
    ['#/novo/dados/', './assistente.mjs', { passo: 'dados' }],
    ['#/site/12/modelo', './assistente.mjs', { passo: 'modelo', siteId: 12 }],
    ['#/site/12', './editor/editor.mjs', { siteId: 12 }],
    ['#/site/7/leads', './leads.mjs', { siteId: 7 }],
  ];
  for (const [hash, modulo, params] of casos) {
    const r = resolverRota(hash);
    assert.ok(r, hash);
    assert.equal(r.rota.modulo, modulo, hash);
    for (const [k, v] of Object.entries(params)) assert.deepEqual(r.params[k], v, `${hash} → ${k}`);
  }
  assert.equal(resolverRota('#/qualquer'), null);
  assert.equal(resolverRota('#/site/abc'), null);
  assert.equal(resolverRota('#/site/12').rota.layout, 'editor', 'o editor ocupa a tela inteira');
  assert.ok(resolverRota('#/entrar').rota.publica && resolverRota('#/redefinir').rota.publica);
  assert.ok(ROTAS.filter((r) => !r.publica).every((r) => r.layout !== 'login'));
  const { caminho, query } = lerHash('#/redefinir?token=a%2Bb&x=1');
  assert.equal(caminho, '/redefinir');
  assert.equal(query.get('token'), 'a+b');
});

/* ------------------------------------------------------------------ login */

test('login: e-mail e senha nova com mensagens em português', () => {
  assert.match(problemaEmail(''), /Informe/);
  assert.match(problemaEmail('ana@'), /formato/);
  assert.equal(problemaEmail(' ana@clinica.com.br '), null);
  assert.match(problemaSenhaNova('1234567'), /8 caracteres/);
  assert.equal(problemaSenhaNova('12345678'), null);
  assert.match(problemaSenhaNova('12345678', '12345679'), /não são iguais/);
  assert.equal(problemaSenhaNova('senha-boa-1', 'senha-boa-1'), null);
});

/* ------------------------------------------------------------------ painel */

test('painel: textos de quantidade e cor do site', () => {
  assert.equal(textoQuantidadeSites(0), 'Nenhum site');
  assert.equal(textoQuantidadeSites(1), '1 site');
  assert.equal(textoQuantidadeSites(4), '4 sites');
  assert.equal(textoLeadsNovos(1), '1 contato novo');
  assert.equal(textoLeadsNovos(3), '3 contatos novos');
  assert.equal(corDoSite(lib, { nicho: 'clinicas', modelo: 'moderno' }), lib.nichos.clinicas.padroesPorModelo.moderno.cor);
  assert.equal(corDoSite(lib, { nicho: 'clinicas', modelo: 'moderno', cor: '#1F7A4D' }), '#1f7a4d', 'a cor do site, se vier, vence');
  assert.equal(corDoSite(lib, { nicho: 'x', modelo: 'y' }), '#2457d6');
});

/* ------------------------------------------------------------------ assistente */

test('passo 1: miniatura do nicho usa o modelo Moderno com todos os dados de exemplo', () => {
  for (const id of Object.keys(lib.nichos)) {
    const ex = dadosExemplo(lib.nichos[id]);
    assert.equal(ex.nome, lib.nichos[id].exemplo.nome);
    assert.equal(ex.whatsapp, lib.nichos[id].exemplo.whatsapp);
    if (lib.nichos[id].exemplo.dados?.telefone) assert.equal(ex.telefone, lib.nichos[id].exemplo.dados.telefone);
    const doc = documentoExemplo(lib, id);
    assert.equal(doc.modelo, 'moderno');
    assert.equal(doc.nicho, id);
    assert.equal(doc.dados.nome, lib.nichos[id].exemplo.nome);
    assert.deepEqual(doc.secoes, receitaModelo(lib, 'moderno', id));
    const r = prepararSite(doc, lib, { modo: 'editor', urlMidia: '/api/media/{id}/{w}', midia: {}, ano: 2026 });
    assert.ok(r.html.includes(lib.nichos[id].exemplo.nome.replace(/&/g, '&amp;')), `${id}: nome do exemplo no HTML`);
  }
  // Não altera o exemplo da biblioteca.
  const ex = dadosExemplo(lib.nichos.clinicas);
  ex.endereco.cep = 'mexido';
  assert.notEqual(lib.nichos.clinicas.exemplo.dados.endereco.cep, 'mexido');
});

test('passo 2: meta do modelo (seções e fonte) e nome do nicho no título', () => {
  const m = metaModelo(lib, 'moderno', 'clinicas');
  const n = receitaModelo(lib, 'moderno', 'clinicas').length;
  assert.match(m, new RegExp(`^${n} seções · fonte [a-zà-ú]+$`));
  assert.equal(nichoNoTitulo(lib, 'clinicas'), 'clínicas');
});

test('passo 3: dados limpos, documento da prévia e corpo do POST /api/sites', () => {
  const estado = {
    ...estadoInicial(), nicho: 'clinicas', especialidade: 'estetica', modelo: 'direto',
    dados: { nome: '  Clínica   Bem Sorrir ', cidade: 'Jundiaí', uf: 'sp', whatsapp: '11987654321' },
  };
  assert.deepEqual(dadosLimpos(estado.dados), { nome: 'Clínica Bem Sorrir', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' });
  assert.equal(dadosLimpos({ nome: 'x'.repeat(80) }).nome.length, MAX_NOME);
  assert.equal(dadosLimpos({ uf: 'ZZ' }).uf, '');

  const corpo = corpoCriacao(lib, estado);
  assert.deepEqual(corpo, {
    nicho: 'clinicas', modelo: 'direto', especialidade: 'estetica',
    dados: { nome: 'Clínica Bem Sorrir', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' },
    estilo: { cor: corPadrao(lib, 'clinicas', 'direto') },
  });
  assert.equal(corpoCriacao(lib, { ...estado, cor: '#6b4fa0' }).estilo.cor, '#6b4fa0');
  assert.equal(corpoCriacao(lib, { ...estado, especialidade: null }).especialidade, undefined);

  const doc = documentoDoAssistente(lib, estado);
  assert.equal(doc.modelo, 'direto');
  assert.equal(doc.especialidade, 'estetica');
  assert.equal(doc.estilo.cor, corPadrao(lib, 'clinicas', 'direto'));
  assert.equal(doc.dados.whatsapp, '(11) 98765-4321');
  assert.equal(documentoDoAssistente(lib, estado, { modelo: 'classico' }).modelo, 'classico');

  // Prévia: WhatsApp incompleto não some com os botões (usa o do exemplo).
  const parcial = { ...estado, dados: { ...estado.dados, whatsapp: '(20) 987' } };
  assert.equal(documentoDoAssistente(lib, parcial).dados.whatsapp, '(20) 987');
  assert.equal(documentoDoAssistente(lib, parcial, { previa: true }).dados.whatsapp, '');
});

test('passo 3: validação de nome, cidade e WhatsApp (DDD)', () => {
  assert.deepEqual(errosDadosNegocio({ nome: '', cidade: '', whatsapp: '' }), {}, 'tudo vazio usa o exemplo');
  assert.deepEqual(errosDadosNegocio({ nome: 'Ok', cidade: 'Jundiaí', whatsapp: '(11) 98765-4321' }), {});
  const e = errosDadosNegocio({ nome: 'x'.repeat(MAX_NOME + 1), cidade: 'y'.repeat(MAX_CIDADE + 1), whatsapp: '(20) 98765-4321' });
  assert.match(e.nome, /60/);
  assert.match(e.cidade, /40/);
  assert.match(e.whatsapp, /DDD 20/);
  assert.match(errosDadosNegocio({ whatsapp: '(11) 9876' }).whatsapp, /completo/);
});

test('passo 3: cores — a do logo é a primeira bolinha; a do modelo entra se não for sugestão; sem repetir', () => {
  const sem = coresParaEscolher({});
  assert.deepEqual(sem.map((c) => c.cor), CORES_SUGERIDAS.map((c) => c.cor));
  assert.equal(sem.length, 9);
  const comLogo = coresParaEscolher({ corLogo: '#FF0000', padrao: '#2563c9' });
  assert.equal(comLogo[0].cor, '#ff0000');
  assert.equal(comLogo[0].origem, 'logo');
  assert.equal(comLogo[1].cor, '#2563c9');
  assert.equal(comLogo[1].origem, 'modelo');
  assert.equal(comLogo.length, 11);
  const repetida = coresParaEscolher({ corLogo: CORES_SUGERIDAS[2].cor, padrao: CORES_SUGERIDAS[0].cor });
  assert.equal(repetida.length, 9, 'cor do logo igual a uma sugestão não duplica');
  assert.equal(repetida[0].origem, 'logo');
  assert.equal(coresParaEscolher({ corLogo: 'inválida' }).length, 9);
});

test('troca de modelo de site existente mantém textos, dados e cor (aplicarModelo)', () => {
  const doc = documentoDoAssistente(lib, { ...estadoInicial(), nicho: 'clinicas', modelo: 'moderno', cor: '#1f7a4d', dados: { nome: 'A' } });
  doc.textos['hero.titulo'] = 'Meu título';
  const novo = aplicarModelo(doc, lib, 'classico');
  assert.equal(novo.modelo, 'classico');
  assert.equal(novo.textos['hero.titulo'], 'Meu título');
  assert.equal(novo.estilo.cor, '#1f7a4d');
  assert.equal(novo.dados.nome, 'A');
  assert.deepEqual(novo.secoes, receitaModelo(lib, 'classico', 'clinicas'));
});

/* ------------------------------------------------------------------ leads */

test('leads: origem legível (Google Ads, utm, referência, direto) e detalhes', () => {
  assert.deepEqual(descreverOrigem({ gclid: 'abc', pagina: 'https://x.com/servicos/?a=1' }), { canal: 'Google Ads (gclid)', detalhes: ['página: /servicos/'] });
  assert.equal(descreverOrigem({ gbraid: '1', wbraid: '2' }).canal, 'Google Ads (gbraid, wbraid)');
  assert.deepEqual(descreverOrigem({ gclid: 'a', utm_source: 'google', utm_medium: 'cpc' }).detalhes, ['utm: google / cpc']);
  assert.deepEqual(descreverOrigem({ utm_source: 'instagram', utm_medium: 'cpc', utm_campaign: 'outubro' }), { canal: 'utm: instagram / cpc', detalhes: ['campanha: outubro'] });
  assert.equal(descreverOrigem({ utm_source: 'newsletter' }).canal, 'utm: newsletter');
  assert.equal(descreverOrigem({ utm_medium: 'email' }).canal, 'utm: — / email');
  assert.equal(descreverOrigem({ referencia: 'https://www.google.com.br/' }).canal, 'Veio do Google');
  assert.equal(descreverOrigem({ referencia: 'https://l.instagram.com/?u=x' }).canal, 'Veio do Instagram');
  assert.equal(descreverOrigem({ referencia: 'https://blog.parceiro.com.br/post' }).canal, 'Veio de blog.parceiro.com.br');
  assert.equal(descreverOrigem({ referencia: 'lixo' }).canal, 'Acesso direto');
  assert.equal(descreverOrigem({}).canal, 'Acesso direto');
  assert.equal(descreverOrigem(null).canal, 'Acesso direto');
  assert.equal(descreverOrigem([]).canal, 'Acesso direto');
});

test('leads: resumo com total e não lidos', () => {
  assert.equal(resumoLeads(0, 0), 'Nenhum contato');
  assert.equal(resumoLeads(1, 1), '1 contato · 1 não lido');
  assert.equal(resumoLeads(12, 3), '12 contatos · 3 não lidos');
  assert.equal(resumoLeads(5, 0), '5 contatos · todos lidos');
});
