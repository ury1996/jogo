import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca, listarArquivos, versaoBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { iguaisJson } from '../paridade/comparar.mjs';

const RAIZ = fileURLToPath(new URL('../..', import.meta.url));
const MINI = path.join(RAIZ, 'tests/fixtures/biblioteca-mini');

function bundlePhp(dir) {
  const codigo = `require ${JSON.stringify(path.join(RAIZ, 'vendor/autoload.php'))};
    echo json_encode(Rankly\\Preparo\\Biblioteca::carregar($argv[1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);`;
  const r = spawnSync('php', ['-r', codigo, dir], { encoding: 'utf8', maxBuffer: 1 << 26 });
  assert.equal(r.status, 0, r.stderr);
  return JSON.parse(r.stdout);
}

test('bundle §6.1: estrutura e conteúdo', () => {
  const lib = carregarBiblioteca(MINI);
  assert.deepEqual(Object.keys(lib), ['versao', 'secoes', 'parciais', 'baseCss', 'modelos', 'nichos', 'comum', 'icones', 'fontes', 'fotos']);
  assert.match(lib.versao, /^[0-9a-f]{40}$/);
  assert.deepEqual(Object.keys(lib.secoes), ['faq', 'header', 'hero', 'rodape', 'servicos']);
  assert.deepEqual(Object.keys(lib.secoes.servicos), ['manifest', 'templates', 'css']);
  assert.deepEqual(Object.keys(lib.secoes.servicos.templates), ['cards', 'lista']);
  assert.deepEqual(Object.keys(lib.parciais), ['formulario']);
  assert.deepEqual(Object.keys(lib.nichos), ['clinicas'], 'comum.json fica fora de nichos');
  assert.ok(lib.comum.novoItem.serv);
  assert.deepEqual(Object.keys(lib.modelos), ['classico', 'moderno']);
  assert.ok(lib.baseCss.includes('.rk{'));
});

test('Node e PHP montam a MESMA estrutura e a MESMA versão', () => {
  const node = carregarBiblioteca(MINI);
  const php = bundlePhp(MINI);
  assert.equal(php.versao, node.versao);
  assert.ok(iguaisJson(JSON.parse(JSON.stringify(node)), php));
});

test('versão = sha1 de "caminho\\0conteúdo\\0" em ordem de bytes; ignora ocultos; muda com o conteúdo', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rankly-bib-'));
  try {
    fs.mkdirSync(path.join(tmp, 'b'));
    fs.writeFileSync(path.join(tmp, 'b', 'x.json'), '{}');
    fs.writeFileSync(path.join(tmp, 'a.css'), 'a');
    fs.writeFileSync(path.join(tmp, 'B.txt'), 'maiúscula vem antes');
    fs.writeFileSync(path.join(tmp, '.oculto'), 'não entra');
    assert.deepEqual(listarArquivos(tmp), ['B.txt', 'a.css', 'b/x.json']);
    const v1 = versaoBiblioteca(tmp);
    assert.equal(bundlePhp(tmp).versao, v1);
    fs.writeFileSync(path.join(tmp, '.oculto'), 'mudou');
    assert.equal(versaoBiblioteca(tmp), v1);
    fs.writeFileSync(path.join(tmp, 'a.css'), 'b');
    assert.notEqual(versaoBiblioteca(tmp), v1);
    assert.equal(bundlePhp(tmp).versao, versaoBiblioteca(tmp));
  } finally {
    fs.rmSync(tmp, { recursive: true, force: true });
  }
});

test('biblioteca inexistente ou JSON inválido: erro claro', () => {
  assert.throws(() => carregarBiblioteca('/nao/existe'), /Biblioteca não encontrada/);
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rankly-bib-'));
  try {
    fs.mkdirSync(path.join(tmp, 'modelos'));
    fs.writeFileSync(path.join(tmp, 'modelos', 'x.json'), '{ruim');
    assert.throws(() => carregarBiblioteca(tmp), /JSON inválido em .*x\.json/);
  } finally {
    fs.rmSync(tmp, { recursive: true, force: true });
  }
});
