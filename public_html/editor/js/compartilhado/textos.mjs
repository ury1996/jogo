// Resolução de textos, variáveis, retokenização e listas (contrato §2.1–§2.3).
// PARIDADE OBRIGATÓRIA com app/Preparo/Textos.php.

import { aparar, colapsarEspacos, comoLista, comoMapa, pegar, textoDaChave, textoDe } from './texto.mjs';
import { especialidade, idsValidos, nichoDe, registroCampos, registroListas } from './documento.mjs';

/** Valores das variáveis {nome} {cidade} {segmento} (vazio → exemplo do nicho). */
export function contextoVariaveis(doc, lib) {
  const dados = comoMapa(comoMapa(doc).dados);
  const exemplo = comoMapa(nichoDe(doc, lib).exemplo);
  return {
    nome: colapsarEspacos(dados.nome) || colapsarEspacos(exemplo.nome),
    cidade: colapsarEspacos(dados.cidade) || colapsarEspacos(exemplo.cidade),
    segmento: textoDe(comoMapa(especialidade(doc, lib)).segmento),
  };
}

/** Troca {nome} {cidade} {segmento} numa única passada; outras chaves ficam como estão. */
export function substituirVariaveis(txt, contexto) {
  const ctx = comoMapa(contexto);
  return textoDe(txt).replace(/\{(nome|cidade|segmento)\}/g, (_, nome) => textoDe(ctx[nome]));
}

/** Texto padrão bruto (sem variáveis trocadas): nicho → comum → novoItem; ou "". */
export function textoPadrao(doc, lib, chave) {
  const k = textoDe(chave);
  const nicho = nichoDe(doc, lib);
  const comum = comoMapa(comoMapa(lib).comum);
  const doNicho = textoDaChave(comoMapa(nicho.textos), k);
  if (doNicho !== null) return doNicho;
  const doComum = textoDaChave(comoMapa(comum.textos), k);
  if (doComum !== null) return doComum;
  const partes = k.split('.');
  if (partes.length === 3) {
    const novo = textoDaChave(comoMapa(pegar(comoMapa(comum.novoItem), partes[0])), partes[2]);
    if (novo !== null) return novo;
  }
  return '';
}

/** Texto efetivo (§2.3): editado ?? nicho ?? comum ?? novoItem ?? "", com variáveis trocadas. */
export function textoEfetivo(doc, lib, chave, contexto) {
  const editado = textoDaChave(comoMapa(comoMapa(doc).textos), textoDe(chave));
  const bruto = editado !== null ? editado : textoPadrao(doc, lib, chave);
  return substituirVariaveis(bruto, contexto ?? contextoVariaveis(doc, lib));
}

/** O texto da chave é o padrão (não foi editado)? */
export function ehPadrao(doc, chave) {
  return textoDaChave(comoMapa(comoMapa(doc).textos), textoDe(chave)) === null;
}

function escaparRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * [M4] Ocorrências exatas (sensível a maiúsculas) do nome e da cidade (≥ 3 caracteres)
 * viram {nome} / {cidade}. Passada única, casando a mais longa primeiro (como strtr).
 * `dados` pode ser o próprio doc.dados ou contextoVariaveis(doc, lib) (valores efetivos).
 */
export function retokenizar(txt, dados) {
  const d = comoMapa(dados);
  const nome = textoDe(d.nome);
  const cidade = textoDe(d.cidade);
  const pares = [];
  if ([...nome].length >= 3) pares.push([nome, '{nome}']);
  if ([...cidade].length >= 3 && cidade !== nome) pares.push([cidade, '{cidade}']);
  const texto = textoDe(txt);
  if (pares.length === 0) return texto;
  pares.sort((a, b) => b[0].length - a[0].length);
  const re = new RegExp(pares.map((p) => escaparRegex(p[0])).join('|'), 'g');
  return texto.replace(re, (achado) => pares.find((p) => p[0] === achado)[1]);
}

/** Lista efetiva de ids: doc.listas ?? nicho.listas ?? comum.listas ?? []. Sem cortar no máximo. */
export function itensLista(doc, lib, lista) {
  const nome = textoDe(lista);
  const fontes = [
    comoMapa(comoMapa(doc).listas),
    comoMapa(nichoDe(doc, lib).listas),
    comoMapa(comoMapa(comoMapa(lib).comum).listas),
  ];
  for (const fonte of fontes) {
    const ids = pegar(fonte, nome);
    if (Array.isArray(ids)) return idsValidos(ids);
  }
  return [];
}

/** [mín, máx] de itens da lista pelo manifesto (sem manifesto: [0, ∞]). */
export function limitesLista(lib, lista) {
  const repete = comoLista(comoMapa(pegar(registroListas(lib), textoDe(lista))).repete);
  const min = Number.isInteger(repete[0]) && repete[0] >= 0 ? repete[0] : 0;
  const max = Number.isInteger(repete[1]) && repete[1] >= min ? repete[1] : Number.POSITIVE_INFINITY;
  return [min, max];
}

const BASE36 = '0123456789abcdefghijklmnopqrstuvwxyz';

/** Id de item novo: "n" + 4 caracteres base36 aleatórios, diferente dos existentes. */
export function novoIdItem(existentes = []) {
  const usados = new Set(comoLista(existentes));
  const sortear = (n) => {
    const bytes = new Uint8Array(n);
    if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
    else for (let i = 0; i < n; i++) bytes[i] = Math.floor(Math.random() * 256);
    return bytes;
  };
  for (;;) {
    const id = 'n' + Array.from(sortear(4), (b) => BASE36[b % 36]).join('');
    if (!usados.has(id)) return id;
  }
}

/** Mapa chave → texto efetivo de todos os textos que ainda são padrão (para `versoes.resolvidos` [M5]). */
export function textosPadraoEfetivos(doc, lib) {
  const contexto = contextoVariaveis(doc, lib);
  const resultado = {};
  const ehTexto = (def) => def.tipo === 'texto' || def.tipo === 'texto-longo';
  const registro = registroCampos(lib);
  for (const [chave, def] of Object.entries(registro)) {
    if (chave.includes('*') || !ehTexto(def) || !ehPadrao(doc, chave)) continue;
    resultado[chave] = textoEfetivo(doc, lib, chave, contexto);
  }
  for (const [lista, ldef] of Object.entries(registroListas(lib))) {
    const campos = Object.entries(comoMapa(ldef.campos)).filter(([, def]) => ehTexto(comoMapa(def)));
    for (const id of itensLista(doc, lib, lista)) {
      for (const [campo] of campos) {
        const chave = `${lista}.${id}.${campo}`;
        if (ehPadrao(doc, chave)) resultado[chave] = textoEfetivo(doc, lib, chave, contexto);
      }
    }
  }
  return resultado;
}

// ---------------------------------------------------------------------------
// Edição (usadas pelo editor; devolvem um documento novo, sem alterar o recebido)

function copiar(doc) {
  return JSON.parse(JSON.stringify(comoMapa(doc)));
}

/**
 * Aplica a edição de um texto: apagado por completo → volta ao padrão (restaurado);
 * senão retokeniza [M4] e, se ficar igual ao padrão (como token ou já com as variáveis
 * trocadas — ex.: campo só clicado), remove a chave (volta a propagar).
 * → { doc, restaurado }
 */
export function aplicarEdicaoTexto(doc, lib, chave, texto) {
  const novo = copiar(doc);
  const textos = { ...comoMapa(novo.textos) };
  const k = textoDe(chave);
  const limpo = aparar(texto);
  let restaurado = false;
  if (colapsarEspacos(limpo) === '') {
    delete textos[k];
    restaurado = true;
  } else {
    const contexto = contextoVariaveis(novo, lib);
    const tokenizado = retokenizar(limpo, contexto);
    const padrao = textoPadrao(novo, lib, k);
    // Igual ao padrão (como token ou como o usuário vê) → volta a propagar.
    if (tokenizado === padrao || substituirVariaveis(tokenizado, contexto) === substituirVariaveis(padrao, contexto)) delete textos[k];
    else textos[k] = tokenizado;
  }
  novo.textos = textos;
  return { doc: novo, restaurado };
}

/** Adiciona um item novo (id "n…") após `aposId` (ou no fim). Respeita o máximo. → { doc, id } */
export function adicionarItem(doc, lib, lista, aposId = null, id = null) {
  const ids = itensLista(doc, lib, lista);
  const [, max] = limitesLista(lib, lista);
  if (ids.length >= max) return { doc, id: null };
  const novoId = typeof id === 'string' && /^[a-z0-9]+$/.test(id) && !ids.includes(id) ? id : novoIdItem(ids);
  const pos = aposId !== null && ids.includes(aposId) ? ids.indexOf(aposId) + 1 : ids.length;
  ids.splice(pos, 0, novoId);
  const novo = copiar(doc);
  novo.listas = { ...comoMapa(novo.listas), [textoDe(lista)]: ids };
  return { doc: novo, id: novoId };
}

/**
 * Remove o item (respeita o mínimo). A ordem passa a ser explícita em doc.listas, então
 * nenhum outro item "ressuscita" [M2]; textos, imagens e ícone do item saem do documento.
 */
export function removerItem(doc, lib, lista, id) {
  const nome = textoDe(lista);
  const ids = itensLista(doc, lib, nome);
  const [min] = limitesLista(lib, nome);
  if (!ids.includes(id) || ids.length <= min) return doc;
  const novo = copiar(doc);
  novo.listas = { ...comoMapa(novo.listas), [nome]: ids.filter((i) => i !== id) };
  const prefixo = `${nome}.${id}.`;
  for (const mapa of ['textos', 'imagens']) {
    const m = { ...comoMapa(novo[mapa]) };
    for (const k of Object.keys(m)) if (k.startsWith(prefixo)) delete m[k];
    novo[mapa] = m;
  }
  const icones = { ...comoMapa(novo.icones) };
  delete icones[`${nome}.${id}`];
  novo.icones = icones;
  return novo;
}

/** Move o item `delta` posições (−1 sobe, +1 desce). */
export function moverItem(doc, lib, lista, id, delta) {
  const nome = textoDe(lista);
  const ids = itensLista(doc, lib, nome);
  const de = ids.indexOf(id);
  const para = de + Math.trunc(delta);
  if (de < 0 || para < 0 || para >= ids.length || para === de) return doc;
  ids.splice(de, 1);
  ids.splice(para, 0, id);
  const novo = copiar(doc);
  novo.listas = { ...comoMapa(novo.listas), [nome]: ids };
  return novo;
}
