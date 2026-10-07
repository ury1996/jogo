// Roda o teste de paridade completo (tests/paridade/comparar.mjs) sobre a biblioteca de teste.
// Sobre a biblioteca real: npm run paridade (ou node tests/paridade/comparar.mjs).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { trechoDivergente } from '../paridade/comparar.mjs';

const RAIZ = fileURLToPath(new URL('../..', import.meta.url));

test('paridade JS ≡ PHP sobre tests/fixtures/biblioteca-mini [M8]', () => {
  const r = spawnSync(process.execPath, ['tests/paridade/comparar.mjs', '--biblioteca', 'tests/fixtures/biblioteca-mini'], { cwd: RAIZ, encoding: 'utf8', maxBuffer: 1 << 26 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  assert.match(r.stdout, /Paridade OK/);
});

test('trechoDivergente aponta linha, coluna e seção', () => {
  const a = '<div data-sec="0">x</div>\n<div data-sec="3">abc</div>';
  const b = '<div data-sec="0">x</div>\n<div data-sec="3">abd</div>';
  const t = trechoDivergente(a, b);
  assert.equal(t.linha, 2);
  assert.equal(t.coluna, 21);
  assert.equal(t.secao, '3');
  assert.match(t.js, /⟦c<\/div>⟧$/);
  assert.equal(trechoDivergente('igual', 'igual'), null);
});
