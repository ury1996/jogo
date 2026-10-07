// Construção dos recursos gerados da biblioteca e do editor.
//
// Uso: node ferramentas/construir.mjs   (ou: npm run construir)
//
//   1. biblioteca/icones/icones.json        ← @phosphor-icons/core + healthicons (construir-icones.mjs)
//   2. biblioteca/fontes/*.woff2 + fontes.json ← @fontsource (copiar-fontes.mjs)
//   3. public_html/editor/js/vendor/mustache.mjs (+ licença) ← node_modules/mustache

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { construirIcones } from './construir-icones.mjs';
import { copiarFontes } from './copiar-fontes.mjs';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export function copiarMustache() {
  const origem = path.join(RAIZ, 'node_modules/mustache');
  const destino = path.join(RAIZ, 'public_html/editor/js/vendor');
  const modulo = path.join(origem, 'mustache.mjs');
  if (!fs.existsSync(modulo)) throw new Error('node_modules/mustache/mustache.mjs não encontrado (rode npm install).');
  fs.mkdirSync(destino, { recursive: true });
  fs.copyFileSync(modulo, path.join(destino, 'mustache.mjs'));
  fs.copyFileSync(path.join(origem, 'LICENSE'), path.join(destino, 'mustache.LICENSE'));
  const versao = JSON.parse(fs.readFileSync(path.join(origem, 'package.json'), 'utf8')).version;
  console.log(`mustache.js ${versao} → ${path.relative(RAIZ, destino)}/mustache.mjs`);
}

try {
  construirIcones();
  copiarFontes();
  copiarMustache();
} catch (erro) {
  console.error(`Falha na construção: ${erro.message}`);
  process.exit(1);
}
