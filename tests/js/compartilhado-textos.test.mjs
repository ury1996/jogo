import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { criarDocumento } from '../../public_html/editor/js/compartilhado/documento.mjs';
import {
  adicionarItem, aplicarEdicaoTexto, contextoVariaveis, ehPadrao, itensLista, limitesLista, moverItem, novoIdItem,
  removerItem, retokenizar, substituirVariaveis, textoEfetivo, textoPadrao, textosPadraoEfetivos,
} from '../../public_html/editor/js/compartilhado/textos.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../fixtures/biblioteca-mini', import.meta.url)));
const novoDoc = (dados = { nome: 'Odonto Mais', cidade: 'Campinas' }) => criarDocumento({ nicho: 'clinicas', modelo: 'moderno', dados }, lib);

test('textoEfetivo: editado → nicho → comum → novoItem → ""', () => {
  const doc = novoDoc();
  assert.equal(textoEfetivo(doc, lib, 'serv.1.t'), 'Ortodontia', 'nicho');
  assert.equal(textoEfetivo(doc, lib, 'form.botao'), 'Enviar mensagem', 'comum');
  assert.equal(textoEfetivo(doc, lib, 'serv.nk3f.t'), 'Novo serviço', 'item sem padrão → novoItem');
  assert.equal(textoEfetivo(doc, lib, 'serv.9.d'), 'Descreva em uma ou duas frases o que está incluído.');
  assert.equal(textoEfetivo(doc, lib, 'faq.nk3f.q'), 'Nova pergunta?');
  assert.equal(textoEfetivo(doc, lib, 'hero.nao'), '');
  assert.equal(textoEfetivo(doc, lib, 'serv.titulo.extra.x'), '');
  doc.textos = { 'serv.1.t': 'Aparelhos', 'hero.titulo': 5 };
  assert.equal(textoEfetivo(doc, lib, 'serv.1.t'), 'Aparelhos', 'editado');
  assert.equal(textoEfetivo(doc, lib, 'hero.titulo'), 'Cuidado com o seu sorriso, do primeiro atendimento ao resultado', 'não-texto é ignorado');
  assert.equal(ehPadrao(doc, 'serv.1.t'), false);
  assert.equal(ehPadrao(doc, 'serv.2.t'), true);
});

test('variáveis {nome} {cidade} {segmento} sempre trocadas, inclusive em texto editado; vazio → exemplo do nicho', () => {
  const doc = novoDoc();
  assert.equal(textoEfetivo(doc, lib, 'hero.eyebrow'), 'Dentista em Campinas');
  assert.equal(textoEfetivo(doc, lib, 'serv.titulo'), 'Tudo o que o seu sorriso precisa na Odonto Mais');
  doc.textos = { 'hero.titulo': 'A {nome} de {cidade} ({segmento}) {outra}' };
  assert.equal(textoEfetivo(doc, lib, 'hero.titulo'), 'A Odonto Mais de Campinas (Dentista) {outra}');
  const vazio = novoDoc({ nome: '   ', cidade: '' });
  assert.deepEqual(contextoVariaveis(vazio, lib), { nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', segmento: 'Dentista' });
  vazio.especialidade = 'estetica';
  assert.equal(contextoVariaveis(vazio, lib).segmento, 'Clínica de estética');
  assert.equal(substituirVariaveis('{nome}{cidade}', { nome: '{cidade}', cidade: 'X' }), '{cidade}X', 'passada única');
  assert.equal(substituirVariaveis('{nome}', { nome: '$1 $& \\0' }), '$1 $& \\0', 'sem padrões especiais de substituição');
});

test('listas por id; remover um item NÃO ressuscita outro [M2]', () => {
  const doc = novoDoc();
  assert.deepEqual(itensLista(doc, lib, 'serv'), ['1', '2', '3', '4'], 'nicho');
  assert.deepEqual(itensLista(doc, lib, 'xyz'), []);
  const semNicho = { ...doc, nicho: 'outro' };
  assert.deepEqual(itensLista(semNicho, lib, 'serv'), ['1', '2', '3'], 'comum');
  const removido = removerItem(doc, lib, 'serv', '2');
  assert.deepEqual(itensLista(removido, lib, 'serv'), ['1', '3', '4']);
  assert.equal(textoEfetivo(removido, lib, 'serv.3.t'), 'Harmonização facial', 'o item 3 continua com o próprio texto');
  const comNovo = adicionarItem(removido, lib, 'serv').doc;
  const ids = itensLista(comNovo, lib, 'serv');
  assert.equal(ids.length, 4);
  assert.match(ids[3], /^n[0-9a-z]{4}$/);
  assert.equal(textoEfetivo(comNovo, lib, `serv.${ids[3]}.t`), 'Novo serviço', 'o novo não herda o texto do "2" removido');
  assert.deepEqual(itensLista(doc, lib, 'serv'), ['1', '2', '3', '4'], 'o documento original não muda');
});

test('remover apaga textos, imagens e ícone do item; respeita o mínimo; mover e adicionar respeitam limites', () => {
  let doc = novoDoc();
  doc.textos = { 'serv.2.t': 'X', 'serv.2.d': 'Y', 'serv.20.t': 'outro item' };
  doc.imagens = { 'serv.2.img': 'm_00000001' };
  doc.icones = { 'serv.2': 'dente' };
  doc = removerItem(doc, lib, 'serv', '2');
  assert.deepEqual(doc.textos, { 'serv.20.t': 'outro item' });
  assert.deepEqual(doc.imagens, {});
  assert.deepEqual(doc.icones, {});
  assert.deepEqual(limitesLista(lib, 'serv'), [3, 8]);
  const noMinimo = removerItem(doc, lib, 'serv', '1');
  assert.deepEqual(itensLista(noMinimo, lib, 'serv'), ['1', '3', '4'], 'já tem 3 (mínimo): não remove');
  assert.deepEqual(itensLista(moverItem(doc, lib, 'serv', '4', -2), lib, 'serv'), ['4', '1', '3']);
  assert.equal(moverItem(doc, lib, 'serv', '4', 1), doc, 'fora dos limites: sem mudança');
  const meio = adicionarItem(doc, lib, 'serv', '1', 'nabcd');
  assert.deepEqual(itensLista(meio.doc, lib, 'serv'), ['1', 'nabcd', '3', '4']);
  let cheio = doc;
  for (let i = 0; i < 10; i += 1) cheio = adicionarItem(cheio, lib, 'serv').doc;
  assert.equal(itensLista(cheio, lib, 'serv').length, 8, 'máximo 8');
  assert.equal(adicionarItem(cheio, lib, 'serv').id, null);
});

test('novoIdItem: "n" + 4 base36, sem colidir', () => {
  for (let i = 0; i < 200; i += 1) assert.match(novoIdItem(), /^n[0-9a-z]{4}$/);
  const existentes = Array.from({ length: 50 }, () => novoIdItem());
  assert.ok(!existentes.includes(novoIdItem(existentes)));
});

test('retokenizar [M4]: nome/cidade (≥ 3) viram variáveis; maiúsculas importam; o mais longo primeiro', () => {
  const dados = { nome: 'Clínica Sorriso', cidade: 'Jundiaí' };
  assert.equal(retokenizar('A Clínica Sorriso fica em Jundiaí.', dados), 'A {nome} fica em {cidade}.');
  assert.equal(retokenizar('clínica sorriso', dados), 'clínica sorriso');
  assert.equal(retokenizar('Clínica Sorriso Jundiaí', { nome: 'Clínica Sorriso Jundiaí', cidade: 'Jundiaí' }), '{nome}');
  assert.equal(retokenizar('Ab em Ab', { nome: 'Ab', cidade: 'Ab' }), 'Ab em Ab', 'menos de 3 caracteres');
  assert.equal(retokenizar('a.b*c', { nome: 'a.b*c' }), '{nome}', 'sem regex solta');
});

test('aplicarEdicaoTexto: apagar restaura o padrão; igual ao padrão remove a chave; senão grava retokenizado', () => {
  const doc = novoDoc();
  const r1 = aplicarEdicaoTexto(doc, lib, 'hero.titulo', 'Sorrisos na Odonto Mais em Campinas  ');
  assert.equal(r1.doc.textos['hero.titulo'], 'Sorrisos na {nome} em {cidade}');
  assert.equal(r1.restaurado, false);
  const r2 = aplicarEdicaoTexto(r1.doc, lib, 'hero.titulo', ' \n ');
  assert.equal(r2.restaurado, true);
  assert.equal('hero.titulo' in r2.doc.textos, false);
  const r3 = aplicarEdicaoTexto(r1.doc, lib, 'hero.eyebrow', 'Dentista em Campinas');
  assert.equal('hero.eyebrow' in r3.doc.textos, false, '"{segmento} em {cidade}" == padrão → volta a propagar');
  assert.equal(textoPadrao(doc, lib, 'hero.eyebrow'), '{segmento} em {cidade}');
});

test('textosPadraoEfetivos lista os textos ainda padrão (escalares e itens efetivos)', () => {
  const doc = novoDoc();
  doc.textos = { 'serv.1.t': 'Editado' };
  const r = textosPadraoEfetivos(doc, lib);
  assert.equal(r['hero.eyebrow'], 'Dentista em Campinas');
  assert.equal(r['serv.2.t'], 'Implantes');
  assert.equal('serv.1.t' in r, false);
  assert.equal('hero.img' in r, false, 'imagem não é texto');
  assert.equal(r['faq.4.q'], 'Onde vocês ficam em Campinas?');
});
