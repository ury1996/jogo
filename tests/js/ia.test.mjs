// IA no editor: aplicação do patch (desfazível, pura) e quais seções mostram "Reescrever com IA".
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { aplicarPatchIa, contarAlteracoes, resumoResultado } from '../../public_html/editor/js/ia.mjs';
import { secaoTemTextosIa } from '../../public_html/editor/js/editor/operacoes.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));

test('aplicarPatchIa aplica textos, remoções, listas, ícones e SEO sem mexer no original', () => {
  const doc = {
    textos: { 'hero.titulo': 'Antigo', 'serv.3.t': 'Sobra', 'faq.1.q': 'Fica' },
    listas: { serv: ['1', '2', '3'] },
    icones: { 'serv.1': 'tooth', 'dif.1': 'heart' },
    seo: { titulo: null, descricao: null },
    secoes: [],
  };
  const congelado = structuredClone(doc);
  const novo = aplicarPatchIa(doc, {
    textos: { 'hero.titulo': 'Novo', 'serv.1.t': 'Implantes' },
    remover: ['serv.3.t'],
    listas: { serv: ['1', '2'] },
    iconesRemover: ['serv.1'],
    seo: { titulo: 'Título', descricao: 'Descrição' },
  });
  assert.deepEqual(doc, congelado, 'o documento original não muda (o histórico depende disso)');
  assert.equal(novo.textos['hero.titulo'], 'Novo');
  assert.equal(novo.textos['serv.1.t'], 'Implantes');
  assert.equal(novo.textos['faq.1.q'], 'Fica');
  assert.ok(!('serv.3.t' in novo.textos));
  assert.deepEqual(novo.listas.serv, ['1', '2']);
  assert.deepEqual(novo.icones, { 'dif.1': 'heart' });
  assert.deepEqual(novo.seo, { titulo: 'Título', descricao: 'Descrição' });
});

test('patch sem SEO mantém o SEO atual; contagem e resumo', () => {
  const doc = { textos: {}, listas: {}, icones: {}, seo: { titulo: 'Meu', descricao: 'Minha' } };
  const patch = { textos: { 'faq.titulo': 'Dúvidas' }, remover: [], listas: {}, iconesRemover: [], seo: null, palavrasChave: ['dentista em jundiaí'] };
  assert.deepEqual(aplicarPatchIa(doc, patch).seo, { titulo: 'Meu', descricao: 'Minha' });
  assert.equal(contarAlteracoes(patch), 1);
  assert.match(resumoResultado(patch), /1 texto\./);
  assert.match(resumoResultado(patch), /dentista em jundiaí/);
});

test('"Reescrever com IA" aparece só em seções com textos que a IA escreve', () => {
  for (const tipo of ['hero', 'servicos', 'faq', 'sobre', 'passos', 'diferenciais', 'cta', 'contato']) {
    assert.equal(secaoTemTextosIa(lib, tipo), true, tipo);
  }
  // Cabeçalho não tem textos; números são alegações (só a IA não escreve).
  assert.equal(secaoTemTextosIa(lib, 'header'), false);
  assert.equal(secaoTemTextosIa(lib, 'numeros'), false);
});
