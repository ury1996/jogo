// Gera biblioteca/icones/icones.json a partir de @phosphor-icons/core e healthicons (MIT),
// aplicando a curadoria de ferramentas/icones-curadoria.mjs (contrato §3.7, PDF cap. 8).
//
// Uso: node ferramentas/construir-icones.mjs
//
// Pesos: Phosphor light → "fino", duotone → "duotone", fill → "preenchido".
//        Healthicons outline → "fino" e "duotone" (o PDF aceita), filled → "preenchido".
// SVG normalizado: sem width/height, viewBox mantido, fill="currentColor",
// aria-hidden="true" focusable="false", sem <title>/<desc>, sem atributos on*, sem ids.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { normalizar } from '../public_html/editor/js/compartilhado/texto.mjs';
import { CATEGORIAS, DERIVADOS, ICONES, UTILITARIOS } from './icones-curadoria.mjs';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DIR_PHOSPHOR = path.join(RAIZ, 'node_modules/@phosphor-icons/core/assets');
const DIR_HEALTH = path.join(RAIZ, 'node_modules/healthicons/public/icons/svg');
export const SAIDA = path.join(RAIZ, 'biblioteca/icones/icones.json');
export const LIMITE_BYTES = 250 * 1024;

// Casas decimais dos caminhos do Healthicons (viewBox 48): 1 casa = erro ≤ 0,05 (0,1% do ícone).
const CASAS_HEALTH = 1;
const PESOS_PHOSPHOR = { fino: 'light', duotone: 'duotone', preenchido: 'fill' };

// ------------------------------------------------------------------ leitura

function lerArquivo(caminho, descricao) {
  if (!fs.existsSync(caminho)) throw new Error(`Ícone não encontrado (${descricao}): ${path.relative(RAIZ, caminho)}`);
  return fs.readFileSync(caminho, 'utf8');
}

/** SVG do Phosphor num peso da biblioteca (light, regular, bold, duotone, fill…). */
function svgPhosphor(nome, pesoPhosphor) {
  const arquivo = pesoPhosphor === 'regular' ? `${nome}.svg` : `${nome}-${pesoPhosphor}.svg`;
  return lerArquivo(path.join(DIR_PHOSPHOR, pesoPhosphor, arquivo), `phosphor ${nome}`);
}

function svgHealth(arquivo, variante) {
  return lerArquivo(path.join(DIR_HEALTH, variante, `${arquivo}.svg`), `healthicons ${arquivo}`);
}

// ------------------------------------------------------------------ caminhos (path data)

const RE_TOKEN_CAMINHO = /([MmZzLlHhVvCcSsQqTtAa])|([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/g;

function tokensCaminho(d) {
  const tokens = [];
  for (const m of d.matchAll(RE_TOKEN_CAMINHO)) tokens.push(m[1] ? { cmd: m[1] } : { num: m[2] });
  return tokens;
}

/** Número com no máximo `casas` decimais, sem zeros sobrando (".5", "-.25", "12"). */
function formatarNumero(valor, casas) {
  let s = valor.toFixed(casas).replace(/\.?0+$/, '');
  if (s === '-0' || s === '') s = '0';
  return s.replace(/^(-?)0\./, '$1.');
}

/**
 * Arredonda as coordenadas de um caminho para `casas` decimais (o Healthicons usa 4 casas num
 * viewBox de 48; com 1 casa o erro máximo é 0,05 — 0,1% do ícone, invisível — e o JSON fica
 * ~25% menor). Só para caminhos com comandos ABSOLUTOS e sem arcos (o erro não acumula e não
 * há flags compactadas); senão o caminho volta intacto. Confere o resultado antes de devolver.
 */
export function arredondarCaminho(d, casas = 2) {
  if (/[a-z]/.test(d.replace(/[eE][+-]?\d/g, '')) || /[Aa]/.test(d)) return d;
  const tokens = tokensCaminho(d);
  let saida = '';
  let anterior = null;
  for (const t of tokens) {
    if (t.cmd) {
      saida += t.cmd;
      anterior = null;
      continue;
    }
    const s = formatarNumero(Number(t.num), casas);
    if (anterior !== null) {
      const dispensaEspaco = s.startsWith('-') || (s.startsWith('.') && /[.eE]/.test(anterior));
      if (!dispensaEspaco) saida += ' ';
    }
    saida += s;
    anterior = s;
  }
  // Conferência: mesmos comandos, mesma quantidade de números, diferença ≤ meia casa.
  const novos = tokensCaminho(saida);
  const tolerancia = 0.5 * 10 ** -casas + 1e-9;
  const ok = novos.length === tokens.length && novos.every((t, i) => (t.cmd
    ? t.cmd === tokens[i].cmd
    : tokens[i].num !== undefined && Math.abs(Number(t.num) - Number(tokens[i].num)) <= tolerancia));
  if (!ok) throw new Error(`Falha ao arredondar o caminho: ${d.slice(0, 60)}…`);
  return saida;
}

// ------------------------------------------------------------------ normalização do SVG

const ATRIBUTOS_DESCARTADOS = new Set(['id', 'class', 'style', 'clip-rule', 'data-name', 'xml:space']);
const CORES_MANTIDAS = new Set(['none', 'currentcolor']);

function lerAtributos(texto) {
  const attrs = [];
  for (const m of texto.matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|'([^']*)')/g)) {
    attrs.push([m[1], m[3] ?? m[4] ?? '']);
  }
  return attrs;
}

function escaparAtributo(v) {
  return v.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/**
 * Normaliza um SVG de origem confiável (pacotes MIT) para o formato do contrato.
 * `arredondar` (casas decimais) só é usado em caminhos absolutos (Healthicons).
 */
export function normalizarSvg(svg, { arredondar = null } = {}) {
  let s = svg
    .replace(/<\?xml[\s\S]*?\?>/g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<(title|desc|metadata|script|style)\b[\s\S]*?<\/\1>/gi, '')
    .replace(/<(title|desc|metadata|script|style)\b[^>]*\/>/gi, '')
    .trim();
  const raiz = s.match(/^<svg\b([^>]*)>([\s\S]*)<\/svg>$/);
  if (!raiz) throw new Error('SVG sem elemento raiz <svg>.');
  const viewBox = lerAtributos(raiz[1]).find(([n]) => n === 'viewBox');
  if (!viewBox) throw new Error('SVG sem viewBox.');
  const RE_TAG = /<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*?)(\/?)>/g;
  const filhos = raiz[2].replace(/>\s+</g, '><').trim().replace(RE_TAG, (_, tag, attrsTxt, auto) => {
    const attrs = [];
    for (const [nome, valor] of lerAtributos(attrsTxt)) {
      if (ATRIBUTOS_DESCARTADOS.has(nome) || /^on/i.test(nome) || /href$/i.test(nome)) continue;
      let v = valor;
      if ((nome === 'fill' || nome === 'stroke') && !CORES_MANTIDAS.has(v.toLowerCase())) v = 'currentColor';
      if (nome === 'fill' && v === 'currentColor') continue; // herda da raiz
      if (nome === 'd' && arredondar !== null) v = arredondarCaminho(v, arredondar);
      attrs.push(` ${nome}="${escaparAtributo(v)}"`);
    }
    return `<${tag}${attrs.join('')}${auto}>`;
  });
  return `<svg viewBox="${viewBox[1]}" fill="currentColor" aria-hidden="true" focusable="false">${filhos}</svg>`;
}

/** Problemas de um SVG normalizado (lista vazia = ok). Também usado pelos testes. */
export function problemasSvg(svg) {
  const p = [];
  if (typeof svg !== 'string' || !/^<svg\b[^>]*>[\s\S]*<\/svg>$/.test(svg)) return ['não é um <svg> completo'];
  const raiz = svg.match(/^<svg\b([^>]*)>/)[1];
  if (!/\sviewBox="[^"]+"/.test(raiz)) p.push('raiz sem viewBox');
  if (/\s(width|height)=/.test(raiz)) p.push('raiz com width/height');
  if (!/\sfill="currentColor"/.test(raiz)) p.push('raiz sem fill="currentColor"');
  if (!/\saria-hidden="true"/.test(raiz)) p.push('sem aria-hidden="true"');
  if (!/\sfocusable="false"/.test(raiz)) p.push('sem focusable="false"');
  if (/<title|<desc|<script|<style|<foreignObject/i.test(svg)) p.push('elemento proibido');
  if (/\son[a-z]+\s*=/i.test(svg)) p.push('atributo on*');
  if (/\s(id|href|xlink:href|style|class)=/.test(svg) || /url\(|javascript:/i.test(svg)) p.push('referência/estilo externo');
  if (/#[0-9a-f]{3,8}\b|rgb\(|hsl\(/i.test(svg)) p.push('cor literal');
  return p;
}

// ------------------------------------------------------------------ montagem

function pesosPhosphor(nome, trocas = {}) {
  const r = {};
  for (const [peso, padrao] of Object.entries(PESOS_PHOSPHOR)) {
    r[peso] = normalizarSvg(svgPhosphor(nome, trocas[peso] ?? padrao));
  }
  return r;
}

function pesosHealth(arquivo) {
  const fino = normalizarSvg(svgHealth(arquivo, 'outline'), { arredondar: CASAS_HEALTH });
  const preenchido = normalizarSvg(svgHealth(arquivo, 'filled'), { arredondar: CASAS_HEALTH });
  return { fino, duotone: fino, preenchido };
}

/** Caminhos (atributo d) de um Healthicons, já arredondados. */
function caminhosHealth(arquivo, variante) {
  const svg = svgHealth(arquivo, variante);
  return [...svg.matchAll(/\sd="([^"]+)"/g)].map((m) => arredondarCaminho(m[1], CASAS_HEALTH));
}

function pesosDerivado(id) {
  const def = DERIVADOS[id];
  if (!def) throw new Error(`Ícone derivado sem definição: ${id}`);
  const contorno = caminhosHealth(def.base, 'outline').join('');
  const solido = caminhosHealth(def.base, 'filled').join('');
  const fino = normalizarSvg('<svg viewBox="0 0 48 48">'
    + `<path fill-rule="evenodd" d="${contorno}"/><path d="${def.fio}${def.braquete}"/></svg>`);
  const preenchido = normalizarSvg(`<svg viewBox="0 0 48 48"><path fill-rule="evenodd" d="${solido}${def.vazado}"/></svg>`);
  return { fino, duotone: fino, preenchido };
}

function svgsDe(curado) {
  if (curado.fonte === 'phosphor') return pesosPhosphor(curado.arquivo);
  if (curado.fonte === 'healthicons') return pesosHealth(curado.arquivo);
  if (curado.fonte === 'derivado') return pesosDerivado(curado.arquivo);
  throw new Error(`Fonte desconhecida: ${curado.fonte}`);
}

/** Palavras normalizadas, sem repetição, na ordem da curadoria. */
function normalizarPalavras(palavras) {
  const vistas = new Set();
  const r = [];
  for (const p of palavras) {
    const n = normalizar(p);
    if (n === '' || vistas.has(n)) continue;
    vistas.add(n);
    r.push(n);
  }
  return r;
}

/** Valida a curadoria: ids únicos, categorias válidas, palavras não repetidas entre ícones. */
export function validarCuradoria(icones = ICONES) {
  const erros = [];
  const ids = new Set();
  const donoDaPalavra = new Map();
  for (const ic of icones) {
    if (!/^[a-z0-9-]+$/.test(ic.id)) erros.push(`id inválido: "${ic.id}"`);
    if (ids.has(ic.id)) erros.push(`id repetido: "${ic.id}"`);
    ids.add(ic.id);
    if (!CATEGORIAS.includes(ic.categoria)) erros.push(`${ic.id}: categoria inválida "${ic.categoria}"`);
    if (typeof ic.nome !== 'string' || ic.nome.trim() === '') erros.push(`${ic.id}: sem nome`);
    const palavras = normalizarPalavras(ic.palavras ?? []);
    if (palavras.length === 0) erros.push(`${ic.id}: sem palavras-chave`);
    for (const p of palavras) {
      if (donoDaPalavra.has(p)) erros.push(`palavra "${p}" em "${donoDaPalavra.get(p)}" e "${ic.id}"`);
      else donoDaPalavra.set(p, ic.id);
    }
  }
  for (const chave of Object.keys(UTILITARIOS)) {
    if (ids.has(chave)) erros.push(`utilitário "${chave}" com o mesmo id de um ícone`);
  }
  return erros;
}

/** Monta o objeto do icones.json. */
export function montarIcones() {
  const erros = validarCuradoria();
  if (erros.length > 0) throw new Error(`Curadoria inválida:\n- ${erros.join('\n- ')}`);
  const icones = ICONES.map((c) => ({
    id: c.id,
    nome: c.nome,
    categoria: c.categoria,
    palavras: normalizarPalavras(c.palavras),
    svg: svgsDe(c),
  }));
  const utilitarios = {};
  for (const [chave, u] of Object.entries(UTILITARIOS)) {
    utilitarios[chave] = { id: chave, nome: u.nome, categoria: 'utilitario', palavras: [], svg: pesosPhosphor(u.arquivo, u.pesos) };
  }
  for (const def of [...icones, ...Object.values(utilitarios)]) {
    for (const [peso, svg] of Object.entries(def.svg)) {
      const p = problemasSvg(svg);
      if (p.length > 0) throw new Error(`SVG de "${def.id}" (${peso}) fora do padrão: ${p.join(', ')}`);
    }
  }
  return { versao: 1, icones, utilitarios };
}

/** JSON com um ícone por linha (diffs legíveis, sem o peso da indentação completa). */
export function serializarIcones(dados) {
  const linhasIcones = dados.icones.map((i) => JSON.stringify(i)).join(',\n');
  const linhasUtil = Object.entries(dados.utilitarios).map(([k, v]) => `${JSON.stringify(k)}:${JSON.stringify(v)}`).join(',\n');
  return `{"versao":${dados.versao},\n"icones":[\n${linhasIcones}\n],\n"utilitarios":{\n${linhasUtil}\n}}\n`;
}

/** Avisos de licença (MIT) dos pacotes de origem, gravados ao lado do icones.json. */
function textoLicencas() {
  const ler = (rel) => fs.readFileSync(path.join(RAIZ, 'node_modules', rel), 'utf8').trim();
  return 'Ícones de biblioteca/icones/icones.json — licença MIT.\n'
    + 'O ícone "braces" (Aparelho dental) é derivado do "tooth" do Healthicons, com fio e bráquete acrescentados.\n\n'
    + `==== Phosphor Icons (@phosphor-icons/core) ====\n\n${ler('@phosphor-icons/core/LICENSE')}\n\n`
    + `==== Healthicons (healthicons) ====\n\n${ler('healthicons/LICENSE')}\n`;
}

export function construirIcones({ saida = SAIDA, silencioso = false } = {}) {
  const dados = montarIcones();
  const json = serializarIcones(dados);
  JSON.parse(json); // garantia
  const bytes = Buffer.byteLength(json, 'utf8');
  if (bytes > LIMITE_BYTES) throw new Error(`icones.json com ${(bytes / 1024).toFixed(1)} KB (limite ${LIMITE_BYTES / 1024} KB).`);
  fs.mkdirSync(path.dirname(saida), { recursive: true });
  fs.writeFileSync(saida, json);
  fs.writeFileSync(path.join(path.dirname(saida), 'LICENCAS.txt'), textoLicencas());
  if (!silencioso) {
    const porFonte = ICONES.reduce((acc, c) => ({ ...acc, [c.fonte]: (acc[c.fonte] ?? 0) + 1 }), {});
    console.log(`ícones: ${dados.icones.length} (${Object.entries(porFonte).map(([f, n]) => `${f} ${n}`).join(', ')}) + `
      + `${Object.keys(dados.utilitarios).length} utilitários · ${(bytes / 1024).toFixed(1)} KB → ${path.relative(RAIZ, saida)}`);
  }
  return { dados, bytes };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    construirIcones();
  } catch (erro) {
    console.error(erro.message);
    process.exit(1);
  }
}
