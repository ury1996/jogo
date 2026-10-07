// Revisão adversarial da lógica pura do editor (operacoes.mjs) com a biblioteca real.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { criarDocumento } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { prepararSite } from '../../public_html/editor/js/compartilhado/preparo.mjs';
import { itensLista } from '../../public_html/editor/js/compartilhado/textos.mjs';
import * as op from '../../public_html/editor/js/editor/operacoes.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));
const novoDoc = () => criarDocumento({ nicho: 'clinicas', modelo: 'moderno', dados: { nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', uf: 'SP' } }, lib);

test('seção repetida: um cabeçalho/rodapé duplicado (dado antigo ou da API) pode ser removido; o original não', () => {
  const doc = novoDoc();
  const ultimo = doc.secoes.length - 1;
  const comCopia = { ...doc, secoes: [...doc.secoes.slice(0, 2), { ...doc.secoes[0] }, ...doc.secoes.slice(2), { ...doc.secoes[ultimo] }] };
  const iCopiaHeader = 2;
  const iCopiaRodape = comCopia.secoes.length - 1;
  // O site mostra só a primeira de cada tipo (e o validador bloqueia a publicação).
  const r = prepararSite(comCopia, lib, { modo: 'editor', midia: {} });
  assert.ok(r.avisos.some((a) => a.codigo === 'secao_duplicada'));
  assert.equal(op.podeRemoverSecao(comCopia, lib, 0), false, 'o cabeçalho original fica');
  assert.equal(op.podeRemoverSecao(comCopia, lib, ultimo + 1), false, 'o rodapé original fica');
  assert.equal(op.ehSecaoRepetida(comCopia, iCopiaHeader), true);
  assert.equal(op.podeRemoverSecao(comCopia, lib, iCopiaHeader), true);
  assert.equal(op.podeRemoverSecao(comCopia, lib, iCopiaRodape), true);
  const semCopias = op.removerSecao(op.removerSecao(comCopia, lib, iCopiaRodape), lib, iCopiaHeader);
  assert.deepEqual(semCopias.secoes, doc.secoes);
  assert.ok(!prepararSite(semCopias, lib, { modo: 'editor', midia: {} }).avisos.some((a) => a.codigo === 'secao_duplicada'));
});

test('[M2] remover itens até o mínimo e adicionar de novo nunca traz de volta um item padrão removido', () => {
  let doc = novoDoc();
  const padrao = itensLista(doc, lib, 'serv');
  const [min] = op.limitesLista(lib, 'serv');
  for (const id of padrao.slice(0, padrao.length - min)) doc = op.removerItem(doc, lib, 'serv', id);
  assert.equal(itensLista(doc, lib, 'serv').length, min);
  assert.equal(op.removerItem(doc, lib, 'serv', itensLista(doc, lib, 'serv')[0]), doc, 'no mínimo não remove');
  const r = op.adicionarItem(doc, lib, 'serv');
  assert.match(r.id, /^n[0-9a-z]{4}$/);
  const removidos = padrao.slice(0, padrao.length - min);
  for (const id of removidos) assert.ok(!itensLista(r.doc, lib, 'serv').includes(id), `${id} não volta`);
  // O item novo não herda texto de um removido.
  assert.equal(op.textoMostrado(r.doc, lib, `serv.${r.id}.t`), op.textoPadraoMostrado(r.doc, lib, `serv.${r.id}.t`));
});

test('texto: colar com quebras e tabulações vira uma linha e respeita o limite em pontos de código (emoji)', () => {
  const r = op.inserirNoLimite('Olá ', 4, 4, 'mundo\r\ncom\temoji 😀😀😀', 14);
  assert.equal(r.texto, 'Olá mundo com ');
  assert.equal(r.cortado, true);
  const e = op.inserirNoLimite('', 0, 0, '😀😀😀', 2);
  assert.equal(e.texto, '😀😀');
  assert.equal(op.contarCaracteres(e.texto), 2);
});
