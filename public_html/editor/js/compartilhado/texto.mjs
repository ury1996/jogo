// Funções de texto e utilitários de tipo compartilhados.
// PARIDADE OBRIGATÓRIA com app/Preparo/Texto.php (contrato §5.1).

// Mapa explícito de acentos (não depende de Normalizer/intl).
const ACENTOS = {
  á: 'a', à: 'a', â: 'a', ã: 'a', ä: 'a', å: 'a',
  é: 'e', è: 'e', ê: 'e', ë: 'e',
  í: 'i', ì: 'i', î: 'i', ï: 'i',
  ó: 'o', ò: 'o', ô: 'o', õ: 'o', ö: 'o',
  ú: 'u', ù: 'u', û: 'u', ü: 'u',
  ç: 'c', ñ: 'n', ý: 'y', ÿ: 'y',
  Á: 'A', À: 'A', Â: 'A', Ã: 'A', Ä: 'A', Å: 'A',
  É: 'E', È: 'E', Ê: 'E', Ë: 'E',
  Í: 'I', Ì: 'I', Î: 'I', Ï: 'I',
  Ó: 'O', Ò: 'O', Ô: 'O', Õ: 'O', Ö: 'O',
  Ú: 'U', Ù: 'U', Û: 'U', Ü: 'U',
  Ç: 'C', Ñ: 'N', Ý: 'Y', Ÿ: 'Y',
};
const RE_ACENTOS = new RegExp(`[${Object.keys(ACENTOS).join('')}]`, 'g');
// Acentos combinantes (texto em NFD, comum em macOS).
const RE_COMBINANTES = /[̀-ͯ]/g;

// Classe explícita de espaços (igual nos dois lados; \s varia entre motores).
export const CLASSE_ESPACOS = '\\t\\n\\v\\f\\r \\u00a0\\u1680\\u2000-\\u200a\\u2028\\u2029\\u202f\\u205f\\u3000\\ufeff';
const RE_ESPACOS = new RegExp(`[${CLASSE_ESPACOS}]+`, 'g');
const RE_APARAR = new RegExp(`^[${CLASSE_ESPACOS}]+|[${CLASSE_ESPACOS}]+$`, 'g');

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** Valor como texto: strings como estão; inteiros viram texto; o resto vira "". */
export function textoDe(v) {
  if (typeof v === 'string') return v;
  if (typeof v === 'number' && Number.isInteger(v) && Math.abs(v) < 2 ** 53) return String(v);
  return '';
}

/** É um objeto-mapa (não nulo, não lista)? */
export function ehMapa(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

/** Objeto-mapa ou {} (a API PHP pode mandar [] no lugar de {}). */
export function comoMapa(v) {
  return ehMapa(v) ? v : {};
}

/** Lista ou []. */
export function comoLista(v) {
  return Array.isArray(v) ? v : [];
}

/** Chave própria de um mapa (nunca do protótipo). */
export function temChave(mapa, chave) {
  return ehMapa(mapa) && Object.prototype.hasOwnProperty.call(mapa, chave);
}

/** Valor de uma chave própria do mapa, ou undefined. */
export function pegar(mapa, chave) {
  return temChave(mapa, chave) ? mapa[chave] : undefined;
}

/** Valor de texto de uma chave própria, ou null se ausente/não-texto. */
export function textoDaChave(mapa, chave) {
  return temChave(mapa, chave) && typeof mapa[chave] === 'string' ? mapa[chave] : null;
}

export function semAcentos(s) {
  return textoDe(s).replace(RE_ACENTOS, (c) => ACENTOS[c]).replace(RE_COMBINANTES, '');
}

/** Colapsa sequências de espaços em um e apara as pontas. */
export function colapsarEspacos(s) {
  return textoDe(s).replace(RE_ESPACOS, ' ').replace(/^ +| +$/g, '');
}

/** Apara espaços (classe explícita) só nas pontas, sem colapsar o meio. */
export function aparar(s) {
  return textoDe(s).replace(RE_APARAR, '');
}

/** minúsculas + sem acento + espaços colapsados. */
export function normalizar(s) {
  return colapsarEspacos(semAcentos(s).toLowerCase());
}

/**
 * Escape HTML: só & < > " ' (nada mais). Booleanos viram "true"/"false" e números
 * o seu texto, como o mustache.js faria; o PHP imita isso.
 */
export function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

/** Arredondamento da especificação: floor(x + 0.5) (não usar Math.round). */
export function arred(x) {
  return Math.floor(x + 0.5);
}

const RE_SURROGATE_SOLTO = /[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]/g;

/** Comportamento de encodeURIComponent (sem lançar para surrogates soltos). */
export function codificarUri(s) {
  return encodeURIComponent(textoDe(s).replace(RE_SURROGATE_SOLTO, '�'));
}
