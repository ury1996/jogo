// Biblioteca no editor — contrato §6.1 e §9.
//
//   carregarBiblioteca()  → Promise do bundle (GET /api/biblioteca), pedido uma vez só
//   injetarCssSite(lib)   → <style id="rk-css-site"> com @font-face (/api/fontes/…), base.css
//                           e o CSS de todas as seções (ordem do manifesto)
//   cssSite(lib)          → o mesmo CSS como texto (sem DOM; usado nos testes)
//   nomeNicho(lib, id) / nomeModelo(lib, id) / nichosOrdenados(lib) — auxiliares de exibição

import { api } from './api.mjs';

let promessa = null;

/** Bundle da biblioteca (cache em memória; o navegador revalida pelo ETag). */
export function carregarBiblioteca({ forcar = false } = {}) {
  if (!promessa || forcar) {
    promessa = api.get('/biblioteca').then((lib) => {
      if (!lib || typeof lib !== 'object' || !lib.secoes) throw new Error('Biblioteca inválida.');
      return lib;
    }).catch((e) => {
      promessa = null;
      throw e;
    });
  }
  return promessa;
}

const RE_FAMILIA = /^[A-Za-z0-9 ]{1,60}$/;
const RE_ARQUIVO = /^[a-z0-9][a-z0-9.-]{0,100}\.woff2$/;
const RE_PESO = /^\d{3}( \d{3,4})?$/;
const RE_UNICODE = /^[Uu0-9A-Fa-f+,\s?-]{1,800}$/;
const RE_PORCENTO = /^\d{1,3}(\.\d{1,3})?%$/;

function mapa(v) {
  return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
}

/** @font-face de todas as fontes da biblioteca (+ famílias "… Reserva" com ajustes métricos). */
export function cssFontes(lib, urlFontes = '/api/fontes/') {
  const fontes = mapa(lib?.fontes);
  let css = '';
  for (const a of Array.isArray(fontes.arquivos) ? fontes.arquivos : []) {
    const familia = String(a?.familia ?? '');
    const arquivo = String(a?.arquivo ?? '');
    if (!RE_FAMILIA.test(familia) || !RE_ARQUIVO.test(arquivo)) continue;
    const peso = String(a?.peso ?? '');
    const estilo = a?.estilo === 'italic' ? 'italic' : 'normal';
    const faixa = String(a?.unicodeRange ?? '');
    css += `@font-face{font-family:"${familia}";font-style:${estilo}${RE_PESO.test(peso) ? `;font-weight:${peso}` : ''}`
      + `;font-display:swap;src:url(${urlFontes}${arquivo}) format("woff2")`
      + `${RE_UNICODE.test(faixa) ? `;unicode-range:${faixa.replace(/\s+/g, '')}` : ''}}\n`;
  }
  for (const r of Object.values(mapa(fontes.reserva))) {
    const nome = String(r?.familia ?? '');
    const locais = (Array.isArray(r?.local) ? r.local : []).map(String).filter((l) => RE_FAMILIA.test(l));
    if (!RE_FAMILIA.test(nome) || locais.length === 0) continue;
    css += `@font-face{font-family:"${nome}";src:${locais.map((l) => `local("${l}")`).join(',')}`;
    for (const prop of ['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override']) {
      const v = String(mapa(r.ajustes)[prop] ?? '');
      if (RE_PORCENTO.test(v)) css += `;${prop}:${v}`;
    }
    css += '}\n';
  }
  return css;
}

/** Tipos de seção na ordem sugerida do manifesto. */
export function tiposOrdenados(lib) {
  const secoes = mapa(lib?.secoes);
  return Object.keys(secoes).sort((a, b) => {
    const oa = Number(mapa(secoes[a]?.manifest).ordem ?? 99);
    const ob = Number(mapa(secoes[b]?.manifest).ordem ?? 99);
    return oa - ob || a.localeCompare(b);
  });
}

/** CSS completo dos sites para o editor (fontes + base + todas as seções). */
export function cssSite(lib) {
  const partes = [cssFontes(lib), String(lib?.baseCss ?? '')];
  for (const tipo of tiposOrdenados(lib)) {
    const css = lib.secoes[tipo]?.css;
    if (typeof css === 'string' && css !== '') partes.push(`/* seção: ${tipo} */\n${css}`);
  }
  return partes.join('\n');
}

/** Injeta (uma vez por versão da biblioteca) o CSS dos sites no <head>. */
export function injetarCssSite(lib) {
  if (typeof document === 'undefined' || !lib) return null;
  const versao = String(lib.versao ?? '');
  let estilo = document.getElementById('rk-css-site');
  if (estilo && estilo.dataset.versao === versao) return estilo;
  if (!estilo) {
    estilo = document.createElement('style');
    estilo.id = 'rk-css-site';
    document.head.append(estilo);
  }
  estilo.textContent = cssSite(lib);
  estilo.dataset.versao = versao;
  return estilo;
}

/* ------------------------------------------------------------------ nomes para exibição */

export function nomeNicho(lib, id) {
  return String(mapa(mapa(lib?.nichos)[id]).nome ?? '') || String(id ?? '');
}

export function nomeModelo(lib, id) {
  return String(mapa(mapa(lib?.modelos)[id]).nome ?? '') || String(id ?? '');
}

/** Nichos (sem o "comum") ordenados pelo campo `ordem`. */
export function nichosOrdenados(lib) {
  return Object.values(mapa(lib?.nichos))
    .filter((n) => n && typeof n === 'object' && typeof n.id === 'string')
    .sort((a, b) => (Number(a.ordem ?? 99) - Number(b.ordem ?? 99)) || String(a.nome).localeCompare(String(b.nome), 'pt-BR'));
}

/** Modelos na ordem de apresentação (Clássico, Moderno, Direto; outros depois). */
export function modelosOrdenados(lib, nicho = null) {
  const ordem = ['classico', 'moderno', 'direto'];
  const posicao = (m) => (Number.isFinite(m.ordem) ? m.ordem : (ordem.indexOf(m.id) < 0 ? 99 : ordem.indexOf(m.id)));
  return Object.values(mapa(lib?.modelos))
    .filter((m) => m && typeof m === 'object' && typeof m.id === 'string')
    // Modelos com "nichos" são exclusivos daqueles nichos.
    .filter((m) => !nicho || !Array.isArray(m.nichos) || m.nichos.includes(nicho))
    .sort((a, b) => posicao(a) - posicao(b) || String(a.nome).localeCompare(String(b.nome), 'pt-BR'));
}

/** Nome do par de fontes ("Editorial") ou "". */
export function nomeFonte(lib, par) {
  return String(mapa(mapa(mapa(lib?.fontes).pares)[par]).nome ?? '');
}
