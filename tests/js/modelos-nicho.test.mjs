// Modelos exclusivos de um nicho ("nichos") e cor/fonte padrão vindas do próprio modelo ("padrao").
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { criarDocumento, modeloDoNicho } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { modelosOrdenados } from '../../public_html/editor/js/biblioteca.mjs';

const base = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));
const lib = {
  ...base,
  modelos: {
    ...base.modelos,
    togado: { ...base.modelos.classico, id: 'togado', nome: 'Togado', nichos: ['advocacia'], ordem: 5, padrao: { cor: '#7a5c2e', fonte: 'classica' } },
  },
};

test('modelo com "nichos" só aparece e só vale para aqueles nichos', () => {
  assert.equal(modeloDoNicho(lib, 'togado', 'advocacia'), true);
  assert.equal(modeloDoNicho(lib, 'togado', 'clinicas'), false);
  assert.equal(modeloDoNicho(lib, 'classico', 'clinicas'), true);
  assert.equal(modeloDoNicho(lib, 'nao-existe', 'clinicas'), false);
  assert.deepEqual(modelosOrdenados(lib, 'advocacia').map((m) => m.id), ['tribuna', 'boutique', 'retrato', 'institucional', 'togado', 'classico', 'moderno', 'direto']);
  assert.ok(!modelosOrdenados(lib, 'clinicas').some((m) => m.id === 'togado'));
});

test('sem padrão do nicho para o modelo, cor e fonte vêm do próprio modelo', () => {
  const doc = criarDocumento({ nicho: 'advocacia', modelo: 'togado', dados: {} }, lib);
  assert.equal(doc.estilo.cor, '#7a5c2e');
  assert.equal(doc.estilo.fonte, 'classica');
});
