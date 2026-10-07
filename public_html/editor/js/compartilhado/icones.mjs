// Ícones automáticos (PDF §8.2) e SVG por acabamento (contrato §3.7, §5.3).
// PARIDADE OBRIGATÓRIA com app/Preparo/Icones.php.

import { comoLista, comoMapa, ehMapa, normalizar, pegar, temChave, textoDe } from './texto.mjs';
import { nichoDe, registroListas } from './documento.mjs';
import { textoEfetivo } from './textos.mjs';

export const PESOS = { classico: 'fino', moderno: 'duotone', direto: 'preenchido' };
export const ICONE_RESERVA = 'circulo';
const RE_NORMALIZADA = /^[a-z0-9]+( [a-z0-9]+)*$/;

function listaDeIcones(icones) {
  return Array.isArray(icones) ? icones : comoLista(comoMapa(icones).icones);
}

function ehAlnum(c) {
  return c !== undefined && c !== '' && /^[a-z0-9]$/.test(c);
}

/** `palavra` aparece em `texto` com limites [^a-z0-9] (ou início/fim) dos dois lados? */
function contemPalavraInteira(texto, palavra) {
  let i = texto.indexOf(palavra);
  while (i !== -1) {
    const antes = i === 0 ? '' : texto[i - 1];
    const depois = texto[i + palavra.length];
    if (!ehAlnum(antes) && !ehAlnum(depois)) return true;
    i = texto.indexOf(palavra, i + 1);
  }
  return false;
}

/**
 * Algoritmo do PDF §8.2: palavras com até 3 letras casam só inteiras; as demais por
 * "contém"; a mais longa vence; empate = ordem do arquivo. Sem casamento → null.
 * `icones` = o icones.json inteiro ou só a lista.
 */
export function escolherIcone(titulo, icones) {
  const texto = normalizar(titulo);
  if (texto === '') return null;
  let melhor = null;
  let pontos = 0;
  for (const icone of listaDeIcones(icones)) {
    if (!ehMapa(icone) || typeof icone.id !== 'string') continue;
    for (const p of comoLista(icone.palavras)) {
      // Atalho: palavra já normalizada (o normal no icones.json) dispensa normalizar().
      const palavra = typeof p === 'string' && RE_NORMALIZADA.test(p) ? p : normalizar(p);
      const tamanho = [...palavra].length;
      if (tamanho === 0 || tamanho <= pontos) continue;
      const casou = tamanho <= 3 ? contemPalavraInteira(texto, palavra) : texto.includes(palavra);
      if (casou) {
        melhor = icone.id;
        pontos = tamanho;
      }
    }
  }
  return melhor;
}

/** Definição do ícone (lista principal ou utilitários) ou null. */
export function acharIcone(lib, id) {
  if (typeof id !== 'string' || id === '') return null;
  const icones = comoMapa(comoMapa(lib).icones);
  for (const icone of comoLista(icones.icones)) {
    if (ehMapa(icone) && icone.id === id) return icone;
  }
  const util = pegar(comoMapa(icones.utilitarios), id);
  return ehMapa(util) ? util : null;
}

/** Campo de texto de onde a lista tira o ícone automático (manifesto: "automatico"; padrão "t"). */
export function campoAutomatico(lib, lista) {
  const campos = comoMapa(comoMapa(pegar(registroListas(lib), textoDe(lista))).campos);
  for (const def of Object.values(campos)) {
    const d = comoMapa(def);
    if (d.tipo === 'icone' && typeof d.automatico === 'string' && d.automatico !== '') return d.automatico;
  }
  return 't';
}

/**
 * Ícone do item: manual (doc.icones["lista.id"], se existir na biblioteca) →
 * automático pelo texto → nicho.iconesPadrao[lista][posição % n] → "circulo".
 * `posicao` é a posição do item na lista efetiva, começando em 0.
 */
export function iconeDoItem(doc, lib, lista, id, posicao, contexto) {
  const nomeLista = textoDe(lista);
  const manual = pegar(comoMapa(comoMapa(doc).icones), `${nomeLista}.${textoDe(id)}`);
  if (typeof manual === 'string' && acharIcone(lib, manual) !== null) return manual;
  const titulo = textoEfetivo(doc, lib, `${nomeLista}.${textoDe(id)}.${campoAutomatico(lib, nomeLista)}`, contexto);
  const automatico = escolherIcone(titulo, comoMapa(lib).icones);
  if (automatico !== null) return automatico;
  const padroes = comoLista(pegar(comoMapa(nichoDe(doc, lib).iconesPadrao), nomeLista));
  if (padroes.length > 0) {
    const n = padroes.length;
    const pos = Number.isInteger(posicao) ? posicao : 0;
    const escolhido = padroes[((pos % n) + n) % n];
    if (typeof escolhido === 'string' && acharIcone(lib, escolhido) !== null) return escolhido;
  }
  return ICONE_RESERVA;
}

/** SVG do ícone no peso do acabamento (classico→fino, moderno→duotone, direto→preenchido). */
export function svg(lib, id, acabamento) {
  const def = acharIcone(lib, id);
  return def === null ? '' : svgDaDefinicao(def, acabamento);
}

/** SVG de uma definição de ícone no peso do acabamento (peso ausente → o que existir). */
export function svgDaDefinicao(def, acabamento) {
  const svgs = comoMapa(comoMapa(def).svg);
  const peso = temChave(PESOS, acabamento) ? PESOS[acabamento] : 'fino';
  for (const p of [peso, 'fino', 'duotone', 'preenchido']) {
    const s = pegar(svgs, p);
    if (typeof s === 'string' && s !== '') return s;
  }
  return '';
}
