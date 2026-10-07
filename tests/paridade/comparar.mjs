#!/usr/bin/env node
// Teste de paridade JS ≡ PHP [M8].
//
//   node tests/paridade/comparar.mjs [--biblioteca biblioteca] [--filtro texto] [--sem-funcoes] [--salvar] [--verboso]
//
// Gera os casos automaticamente a partir da biblioteca (todo nicho × modelo × especialidade,
// acabamentos × fontes × cores, listas editadas, textos especiais, dados completos, mídia, modo
// editor, cada seção × opção cheia e vazia, robustez), renderiza em JS, chama
// "php tests/paridade/renderizar.php" em lote e compara byte a byte html, cssPaleta e classesRaiz
// (e também secoes, avisos e as funções puras). O JS roda duas vezes: com o bundle montado em
// Node e com o bundle que o PHP entrega (o mesmo JSON que a API manda ao editor).
// Código de saída: 0 = igual; 1 = divergência/erro; 2 = biblioteca incompleta.

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from './carregar-biblioteca.mjs';
import { gerarCasos } from './casos.mjs';
import { executarFuncoes, gerarChamadas } from './funcoes.mjs';
import { renderizarCasos } from './renderizar.mjs';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function lerArgs(argv) {
  const args = { biblioteca: path.join(RAIZ, 'biblioteca'), filtro: null, funcoes: true, salvar: false, verboso: false };
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === '--biblioteca') args.biblioteca = path.resolve(argv[++i]);
    else if (a.startsWith('--biblioteca=')) args.biblioteca = path.resolve(a.slice(13));
    else if (a === '--filtro') args.filtro = argv[++i];
    else if (a === '--sem-funcoes') args.funcoes = false;
    else if (a === '--salvar') args.salvar = true;
    else if (a === '--verboso') args.verboso = true;
    else if (a === '--ajuda' || a === '-h') {
      console.log('uso: node tests/paridade/comparar.mjs [--biblioteca dir] [--filtro texto] [--sem-funcoes] [--salvar] [--verboso]');
      process.exit(0);
    }
  }
  return args;
}

/** Igualdade profunda de valores JSON; {} vazio ≡ [] vazio (o PHP não distingue). */
export function iguaisJson(a, b) {
  const vazio = (x) => x !== null && typeof x === 'object' && Object.keys(x).length === 0;
  if (vazio(a) && vazio(b)) return true;
  if (a === b) return true;
  if (typeof a !== typeof b || a === null || b === null || typeof a !== 'object') return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && iguaisJson(a[k], b[k]));
}

/** Primeiro caminho onde dois valores JSON diferem (para a mensagem). */
function caminhoDiferenca(a, b, caminho = '') {
  if (iguaisJson(a, b)) return null;
  if (a && b && typeof a === 'object' && typeof b === 'object' && Array.isArray(a) === Array.isArray(b)) {
    for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
      const sub = caminhoDiferenca(a[k], b[k], `${caminho}.${k}`);
      if (sub) return sub;
    }
  }
  return { caminho: caminho || '(raiz)', js: a, php: b };
}

const curto = (v, n = 300) => {
  const s = typeof v === 'string' ? JSON.stringify(v) : JSON.stringify(v);
  return s === undefined ? 'undefined' : (s.length > n ? `${s.slice(0, n)}…` : s);
};

/** Primeiro trecho divergente de duas strings, com contexto, linha e coluna. */
export function trechoDivergente(a, b, contexto = 90) {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i += 1;
  if (i === a.length && i === b.length) return null;
  const antes = a.slice(0, i);
  const linha = antes.split('\n').length;
  const coluna = i - antes.lastIndexOf('\n');
  const secao = [...antes.matchAll(/data-sec="(\d+)"/g)].pop();
  const ini = Math.max(0, i - contexto);
  return {
    posicao: i, linha, coluna, secao: secao ? secao[1] : null,
    js: `${ini > 0 ? '…' : ''}${a.slice(ini, i)}⟦${a.slice(i, i + contexto)}⟧`,
    php: `${ini > 0 ? '…' : ''}${b.slice(ini, i)}⟦${b.slice(i, i + contexto)}⟧`,
  };
}

function rodarPhp(pedido, dirTmp) {
  const entrada = path.join(dirTmp, 'entrada.json');
  const saida = path.join(dirTmp, 'saida.json');
  fs.writeFileSync(entrada, JSON.stringify(pedido));
  const r = spawnSync('php', [path.join(RAIZ, 'tests/paridade/renderizar.php'), entrada, saida], { encoding: 'utf8', maxBuffer: 1 << 28 });
  if (r.status !== 0) throw new Error(`renderizar.php falhou (código ${r.status}):\n${r.stderr || r.stdout}`);
  return JSON.parse(fs.readFileSync(saida, 'utf8'));
}

function verificarBiblioteca(lib) {
  const faltas = [];
  if (Object.keys(lib.secoes).length === 0) faltas.push('nenhuma seção (secoes/*/manifest.json)');
  if (Object.keys(lib.nichos).length === 0) faltas.push('nenhum nicho (nichos/*.json)');
  if (Object.keys(lib.modelos).length === 0) faltas.push('nenhum modelo (modelos/*.json)');
  if (!Array.isArray(lib.icones?.icones)) faltas.push('icones/icones.json');
  if (!lib.fontes?.pares) faltas.push('fontes/fontes.json');
  return faltas;
}

export function compararCasos(rotulo, js, php) {
  const falhas = [];
  const porNome = new Map(php.map((r) => [r.nome, r]));
  for (const rj of js) {
    const rp = porNome.get(rj.nome);
    if (!rp) {
      falhas.push(`[${rotulo}] ${rj.nome}: sem resultado do PHP`);
      continue;
    }
    if (rj.erro || rp.erro) {
      falhas.push(`[${rotulo}] ${rj.nome}: erro — JS: ${rj.erro ?? 'ok'} | PHP: ${rp.erro ?? 'ok'}`);
      continue;
    }
    for (const campo of ['html', 'cssPaleta', 'classesRaiz', 'alvoPular']) {
      const t = trechoDivergente(rj[campo], rp[campo]);
      if (t) {
        falhas.push(`[${rotulo}] ${rj.nome}: "${campo}" diverge na linha ${t.linha}, coluna ${t.coluna}`
          + `${t.secao !== null ? ` (seção data-sec=${t.secao})` : ''}\n    JS : ${t.js}\n    PHP: ${t.php}`);
      }
    }
    const semDetalhe = (avisos) => avisos.map(({ detalhe, ...resto }) => resto);
    for (const [campo, a, b] of [['secoes', rj.secoes, rp.secoes], ['avisos', semDetalhe(rj.avisos), semDetalhe(rp.avisos)]]) {
      const d = caminhoDiferenca(a, b);
      if (d) falhas.push(`[${rotulo}] ${rj.nome}: "${campo}" diverge em ${d.caminho}\n    JS : ${curto(d.js)}\n    PHP: ${curto(d.php)}`);
    }
  }
  return falhas;
}

export function compararFuncoes(chamadas, js, php) {
  const falhas = [];
  js.forEach((rj, i) => {
    const rp = php[i];
    const chamada = chamadas[i];
    const descricao = `${chamada.fn}(${chamada.args.map((a) => curto(a, 80)).join(', ')})`;
    if (!rp || rp.nome !== rj.nome) {
      falhas.push(`${descricao}: sem resultado do PHP`);
    } else if ((rj.erro ?? null) !== (rp.erro ?? null) || !iguaisJson(rj.resultado ?? null, rp.resultado ?? null)) {
      const d = caminhoDiferenca(rj.erro ?? rj.resultado ?? null, rp.erro ?? rp.resultado ?? null);
      falhas.push(`${descricao}\n    diverge em ${d?.caminho}\n    JS : ${curto(d?.js)}\n    PHP: ${curto(d?.php)}`);
    }
  });
  return falhas;
}

async function principal() {
  const args = lerArgs(process.argv.slice(2));
  const rel = path.relative(process.cwd(), args.biblioteca) || '.';
  let lib;
  try {
    lib = carregarBiblioteca(args.biblioteca);
  } catch (erro) {
    console.error(`✗ ${erro.message}\n  Sugestão: node tests/paridade/comparar.mjs --biblioteca tests/fixtures/biblioteca-mini`);
    process.exit(2);
  }
  const faltas = verificarBiblioteca(lib);
  if (faltas.length > 0) {
    console.error(`✗ A biblioteca "${rel}" está incompleta: ${faltas.join('; ')}.`);
    console.error('  Sugestão: node tests/paridade/comparar.mjs --biblioteca tests/fixtures/biblioteca-mini');
    process.exit(2);
  }

  let casos = gerarCasos(lib);
  if (args.filtro) casos = casos.filter((c) => c.nome.includes(args.filtro));
  const docBase = casos.find((c) => c.nome.startsWith('dados/completos'))?.doc ?? casos[0]?.doc;
  const chamadas = args.funcoes ? gerarChamadas(lib, docBase) : [];

  const dirTmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rankly-paridade-'));
  const inicio = Date.now();
  let php;
  try {
    php = rodarPhp({ biblioteca: args.biblioteca, casos, funcoes: chamadas, bundle: true }, dirTmp);
  } finally {
    fs.rmSync(dirTmp, { recursive: true, force: true });
  }
  const msPhp = Date.now() - inicio;

  const falhas = [];
  if (php.versao !== lib.versao) falhas.push(`versão da biblioteca diverge — Node: ${lib.versao} | PHP: ${php.versao}`);
  const d = caminhoDiferenca(JSON.parse(JSON.stringify(lib)), php.bundle);
  if (d) falhas.push(`bundle diverge em ${d.caminho}\n    Node: ${curto(d.js)}\n    PHP : ${curto(d.php)}`);

  const t0 = Date.now();
  const jsNode = renderizarCasos(casos, lib);
  const jsBundlePhp = renderizarCasos(casos, php.bundle);
  const msJs = Date.now() - t0;
  falhas.push(...compararCasos('bundle Node', jsNode, php.resultados));
  falhas.push(...compararCasos('bundle PHP', jsBundlePhp, php.resultados));
  const fJs = executarFuncoes(chamadas, lib);
  falhas.push(...compararFuncoes(chamadas, fJs, php.funcoes));

  if (args.salvar) {
    const dirSaida = path.join(RAIZ, 'tests/paridade/saida');
    fs.mkdirSync(dirSaida, { recursive: true });
    for (const [lado, lista] of [['js', jsNode], ['php', php.resultados]]) {
      for (const r of lista) {
        const nome = r.nome.replace(/[^a-z0-9._-]+/gi, '_');
        fs.writeFileSync(path.join(dirSaida, `${nome}.${lado}.html`), r.html ?? r.erro ?? '');
      }
    }
  }

  const avisos = new Map();
  for (const r of php.resultados) {
    for (const a of r.avisos ?? []) {
      if (a.codigo === 'erro_template' || a.codigo === 'template_ausente') avisos.set(`${a.codigo}: ${a.mensagem}`, a.detalhe ?? '');
    }
  }

  console.log(`Biblioteca: ${rel} (versão ${lib.versao.slice(0, 12)})`);
  console.log(`Casos: ${casos.length} documentos × 2 bundles · ${chamadas.length} chamadas de funções puras · PHP ${msPhp} ms · JS ${msJs} ms`);
  for (const [a, detalhe] of avisos) console.log(`  ! ${a}${detalhe ? ` — ${detalhe}` : ''}`);
  if (falhas.length === 0) {
    console.log('✓ Paridade OK: JS e PHP produzem o mesmo resultado em todos os casos.');
    return 0;
  }
  const mostrar = args.verboso ? falhas : falhas.slice(0, 15);
  console.log(`✗ ${falhas.length} divergência(s):`);
  for (const f of mostrar) console.log(`- ${f}`);
  if (mostrar.length < falhas.length) console.log(`… e mais ${falhas.length - mostrar.length} (use --verboso).`);
  return 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === fs.realpathSync(process.argv[1])) {
  principal().then((codigo) => process.exit(codigo), (erro) => {
    console.error(erro?.stack ?? erro);
    process.exit(1);
  });
}
