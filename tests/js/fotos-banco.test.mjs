// Banco de imagens (Pexels) no editor: termo sugerido por nicho/espaço, chips de sugestões e
// rótulo acessível das miniaturas (funções puras de editor/fotos-banco.mjs).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { termoSugerido, sugestoesDoNicho, rotuloFoto } from '../../public_html/editor/js/editor/fotos-banco.mjs';
import { registroCampos } from '../../public_html/editor/js/compartilhado/documento.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));

test('termoSugerido: equipe vira retrato profissional; o resto segue o nicho/especialidade', () => {
  assert.equal(termoSugerido({ nicho: 'clinicas', especialidade: 'odontologia', chave: 'equipe.2.f', rotulo: 'Foto da pessoa' }), 'retrato profissional');
  assert.equal(termoSugerido({ nicho: 'advocacia', especialidade: 'advocacia', chave: 'hero.img' }), 'escritório de advocacia');
  assert.equal(termoSugerido({ nicho: 'advocacia', chave: 'sobre.img' }), 'escritório de advocacia', 'sem especialidade, usa o nicho');
  assert.equal(termoSugerido({ nicho: 'clinicas', especialidade: 'odontologia', chave: 'hero.img' }), 'consultório odontológico');
  assert.equal(termoSugerido({ nicho: 'clinicas', especialidade: 'odontologia', chave: 'dep.img' }), 'paciente sorrindo');
  assert.equal(termoSugerido({ nicho: 'financas', especialidade: 'contabilidade', chave: 'dep.img' }), 'cliente satisfeito');
  assert.equal(termoSugerido({ nicho: 'empresas', especialidade: 'industria', chave: 'passos.img' }), 'atendimento ao cliente');
  assert.equal(termoSugerido({ nicho: 'clinicas', especialidade: 'estetica', chave: 'serv.3.img', titulo: '  Limpeza  de pele ' }), 'Limpeza de pele');
  assert.equal(termoSugerido({ nicho: 'clinicas', especialidade: 'estetica', chave: 'serv.3.img', titulo: '' }), 'clínica de estética');
  assert.equal(termoSugerido({ nicho: 'desconhecido', chave: 'hero.img' }), 'escritório');
  assert.equal(termoSugerido(), 'escritório');
});

test('termoSugerido nunca fica vazio para os espaços de imagem da biblioteca', () => {
  const chaves = Object.entries(registroCampos(lib)).filter(([, d]) => d.tipo === 'imagem');
  assert.ok(chaves.length > 5);
  for (const nicho of Object.keys(lib.nichos)) {
    for (const esp of [...(lib.nichos[nicho].especialidades ?? []).map((e) => e.id), '']) {
      for (const [chave, def] of chaves) {
        const t = termoSugerido({ nicho, especialidade: esp, chave: chave.replace('*', '1'), rotulo: def.rotulo });
        assert.ok(t.length >= 3 && t.length <= 60, `${nicho}/${esp}/${chave}: "${t}"`);
      }
    }
  }
});

test('sugestoesDoNicho: termo do espaço primeiro, sem repetir, limitado', () => {
  const s = sugestoesDoNicho({ nicho: 'clinicas', especialidade: 'odontologia' }, 'consultório odontológico');
  assert.equal(s[0], 'consultório odontológico');
  assert.equal(s.length, 7);
  assert.equal(new Set(s.map((x) => x.toLowerCase())).size, s.length);
  assert.ok(s.includes('dentista'));
  assert.deepEqual(sugestoesDoNicho({ nicho: 'advocacia', especialidade: 'advocacia' }, '', 3), ['escritório de advocacia', 'advogado', 'balança da justiça']);
  assert.equal(sugestoesDoNicho({}, '').length, 6, 'nicho desconhecido usa as genéricas');
});

test('rotuloFoto descreve a foto e o fotógrafo', () => {
  assert.equal(rotuloFoto({ alt: 'Dentista sorrindo', autor: 'Ana Souza' }), 'Dentista sorrindo, foto de Ana Souza. Usar esta foto');
  assert.equal(rotuloFoto({ alt: '', autor: '' }), 'Foto sem descrição. Usar esta foto');
});
