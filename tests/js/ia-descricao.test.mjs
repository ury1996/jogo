// IA em destaque: exemplos por nicho, dica de qualidade ao vivo, rótulo do botão final e
// quando mostrar a faixa "os textos ainda são de exemplo" no editor.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  EXEMPLOS_DESCRICAO, exemplosDescricao, trechoParaTrocar, dicaDescricao, rotuloGerar,
  textosSaoDeExemplo, mostrarFaixaIa, placeholderDescricao, faixaDispensada, dispensarFaixa, MIN_DESCRICAO, MAX_DESCRICAO,
} from '../../public_html/editor/js/ia.mjs';

const pastaNichos = fileURLToPath(new URL('../../biblioteca/nichos/', import.meta.url));
const nichos = readdirSync(pastaNichos).filter((f) => f.endsWith('.json'))
  .map((f) => JSON.parse(readFileSync(pastaNichos + f, 'utf8')))
  .filter((n) => typeof n.id === 'string');

test('todo nicho e toda especialidade da biblioteca têm um exemplo próprio', () => {
  assert.ok(nichos.length > 0);
  for (const n of nichos) {
    for (const e of n.especialidades ?? []) {
      const lista = exemplosDescricao(n.id, e.id);
      assert.equal(lista[0].nicho, n.id, `${n.id}/${e.id}: o primeiro chip é do nicho`);
      assert.equal(lista[0].especialidade, e.id, `${n.id}/${e.id}: o primeiro chip é da especialidade`);
    }
  }
});

test('exemplosDescricao: especialidade primeiro, depois o nicho, sempre termina no modelo em branco', () => {
  const l = exemplosDescricao('clinicas', 'psicologia');
  assert.equal(l.length, 4);
  assert.equal(l[0].id, 'psicologia');
  assert.equal(l.at(-1).id, 'geral');
  assert.ok(l.slice(0, -1).every((e) => e.nicho === 'clinicas'));
  assert.equal(new Set(l.map((e) => e.id)).size, l.length, 'sem repetir chips');
  assert.deepEqual(exemplosDescricao('nicho-que-nao-existe').map((e) => e.id), ['geral']);
  assert.equal(exemplosDescricao('clinicas', null, 2).length, 2);
});

test('textos-modelo cabem no limite, passam do mínimo e não trazem números inventados', () => {
  for (const e of EXEMPLOS_DESCRICAO) {
    assert.ok(e.texto.length >= MIN_DESCRICAO && e.texto.length <= MAX_DESCRICAO, e.id);
    assert.ok(!/\d/.test(e.texto.replace(/\[[^\]]*\]/g, '')), `${e.id}: sem números fora dos [colchetes]`);
    assert.ok(trechoParaTrocar(e.texto), `${e.id}: tem um [trecho] para a pessoa trocar`);
  }
  const odonto = EXEMPLOS_DESCRICAO.find((e) => e.id === 'odontologia');
  assert.match(odonto.texto, /^Clínica odontológica focada em implantes e ortodontia/);
});

test('trechoParaTrocar acha o primeiro [trecho]', () => {
  const t = 'Clínica no bairro [seu bairro] com [algo].';
  const r = trechoParaTrocar(t);
  assert.equal(t.slice(r.inicio, r.fim), '[seu bairro]');
  assert.equal(trechoParaTrocar('sem colchetes'), null);
  assert.equal(trechoParaTrocar(''), null);
});

test('dicaDescricao evolui com o tamanho e o conteúdo', () => {
  assert.equal(dicaDescricao('').nivel, 'vazio');
  assert.equal(dicaDescricao('   ').nivel, 'vazio');
  assert.equal(dicaDescricao('Dentista').nivel, 'curto');
  assert.equal(dicaDescricao('Clínica no [seu bairro] com implantes e ortodontia.').nivel, 'colchetes');
  const curto = dicaDescricao('Clínica odontológica com implantes.');
  assert.equal(curto.nivel, 'bom');
  assert.match(curto.texto, /Bom começo/);
  const semDiferencial = dicaDescricao('Clínica odontológica com implantes, ortodontia, clareamento e limpeza. Atendemos convênios aos sábados.');
  assert.equal(semDiferencial.nivel, 'bom');
  assert.match(semDiferencial.texto, /diferenciais/);
  const semPublico = dicaDescricao('Clínica odontológica com implantes, ortodontia e clareamento. Diferenciais: anos de experiência e equipe própria.');
  assert.match(semPublico.texto, /para quem/);
  assert.equal(dicaDescricao('Clínica odontológica com implantes e ortodontia. Diferenciais: atendimento sem pressa. Público: famílias do bairro.').nivel, 'otimo');
});

test('rotuloGerar deixa claro quando a IA vai escrever', () => {
  assert.equal(rotuloGerar({ ia: true, descricao: 'Clínica odontológica com implantes.' }), 'Gerar meu site com IA');
  assert.equal(rotuloGerar({ ia: true, descricao: '  ' }), 'Gerar com textos de exemplo');
  assert.equal(rotuloGerar({ ia: true, descricao: 'curto' }), 'Gerar com textos de exemplo');
  assert.equal(rotuloGerar({ ia: false, descricao: 'Clínica odontológica com implantes.' }), 'Gerar com textos de exemplo');
  assert.equal(rotuloGerar(), 'Gerar com textos de exemplo');
});

test('faixa do editor: só com IA, textos todos de exemplo e sem dispensa', () => {
  const novo = { textos: {}, seo: { titulo: null, descricao: null } };
  assert.equal(textosSaoDeExemplo(novo), true);
  assert.equal(textosSaoDeExemplo({}), true);
  assert.equal(textosSaoDeExemplo({ textos: { 'hero.titulo': '' } }), true, 'texto vazio volta ao padrão');
  assert.equal(textosSaoDeExemplo({ textos: { 'hero.titulo': 'Meu título' } }), false);
  assert.equal(textosSaoDeExemplo({ textos: {}, seo: { titulo: 'Escrito pela IA', descricao: null } }), false);
  assert.equal(mostrarFaixaIa({ doc: novo, ia: true }), true);
  assert.equal(mostrarFaixaIa({ doc: novo, ia: false }), false);
  assert.equal(mostrarFaixaIa({ doc: novo, ia: true, dispensada: true }), false);
  assert.equal(mostrarFaixaIa({ doc: { textos: { 'faq.1.q': 'Pergunta' } }, ia: true }), false);
});

test('dispensa da faixa: por site, e sem localStorage não quebra', () => {
  const antes = globalThis.localStorage;
  try {
    delete globalThis.localStorage;
    assert.equal(faixaDispensada(7), false);
    assert.doesNotThrow(() => dispensarFaixa(7));
    const mapa = new Map();
    globalThis.localStorage = { getItem: (k) => mapa.get(k) ?? null, setItem: (k, v) => mapa.set(k, String(v)) };
    dispensarFaixa(7);
    assert.equal(faixaDispensada(7), true);
    assert.equal(faixaDispensada(8), false);
    globalThis.localStorage = { getItem() { throw new Error('bloqueado'); }, setItem() { throw new Error('bloqueado'); } };
    assert.equal(faixaDispensada(7), false);
    assert.doesNotThrow(() => dispensarFaixa(7));
  } finally {
    if (antes === undefined) delete globalThis.localStorage;
    else globalThis.localStorage = antes;
  }
});

test('placeholderDescricao usa a primeira frase do exemplo do nicho, sem [trechos]', () => {
  assert.match(placeholderDescricao('clinicas', 'odontologia'), /^Ex\.: Clínica odontológica/);
  assert.match(placeholderDescricao('advocacia'), /^Ex\.: Escritório de advocacia/);
  for (const n of nichos) assert.ok(!placeholderDescricao(n.id).includes('['), n.id);
  assert.match(placeholderDescricao('desconhecido'), /^Ex\.:/);
});
