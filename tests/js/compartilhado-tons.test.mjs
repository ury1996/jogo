import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calcularFundos } from '../../public_html/editor/js/compartilhado/tons.mjs';

test('claro alterna a partir do anterior; os outros tons são fixos (§5.2)', () => {
  assert.deepEqual(
    calcularFundos(['branco', 'claro', 'claro', 'claro', 'escuro', 'claro', 'cor', 'claro', 'tom-claro', 'claro', 'escuro']),
    ['branco', 'tom', 'branco', 'tom', 'escuro', 'branco', 'cor', 'branco', 'tom', 'branco', 'escuro'],
  );
});

test('primeira seção clara vira tom (anterior começa branco); tom desconhecido conta como claro', () => {
  assert.deepEqual(calcularFundos(['claro', 'xyz', undefined]), ['tom', 'branco', 'tom']);
  assert.deepEqual(calcularFundos([]), []);
  assert.deepEqual(calcularFundos(null), []);
});
