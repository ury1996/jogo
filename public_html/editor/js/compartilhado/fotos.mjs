// Fotos de exemplo da biblioteca (biblioteca/fotos/fotos.json): qual foto vai em cada espaço
// de imagem de um documento, para o modelo já nascer completo. PARIDADE com Preparo/Fotos.php.
//
// Ordem: espaços das seções do documento (na ordem da página), depois os demais espaços da
// biblioteca (para trocar de opção/seção e continuar com foto). Cada espaço tira a próxima foto
// ainda não usada do grupo certo do nicho: "cena" (ambiente) ou "gente_f"/"gente_m" (pessoas,
// pelo nome da equipe). Determinístico: mesmo documento → mesmas fotos.

import { comoMapa, comoLista, pegar, textoDe, normalizar, temChave } from './texto.mjs';
import { registroCampos } from './documento.mjs';
import { textoEfetivo, itensLista, contextoVariaveis } from './textos.mjs';

/** Prefixo dos ids de mídia das fotos de exemplo (antes de virarem mídia do site). */
export const PREFIXO_EXEMPLO = 'x_';

/** "f" ou "m" pelo nome: Dra./Sra. → f; Dr./Sr. → m; senão, primeiro nome terminado em "a" → f. */
export function generoDoNome(nome) {
  const n = normalizar(nome);
  const m = /^(dra|sra|dr|sr|eng)\.?\s+(.*)$/.exec(n);
  if (m !== null && (m[1] === 'dra' || m[1] === 'sra')) return 'f';
  if (m !== null && (m[1] === 'dr' || m[1] === 'sr')) return 'm';
  return /^[a-z]+a(?![a-z])/.test(m !== null ? m[2] : n) ? 'f' : 'm';
}

/** Opções do documento por tipo: { hero: "retrato", … }. */
function opcoesDoDoc(doc) {
  const r = {};
  for (const s of comoLista(comoMapa(doc).secoes)) {
    const tipo = textoDe(pegar(comoMapa(s), 'tipo'));
    if (tipo !== '' && !temChave(r, tipo)) r[tipo] = textoDe(pegar(comoMapa(s), 'opcao'));
  }
  return r;
}

/**
 * Espaços de imagem na ordem de preenchimento: [{chave, gente: null|"nome:<chave do nome>"|"equipe"}].
 */
function espacos(doc, lib) {
  const registro = registroCampos(lib);
  const secoesLib = comoMapa(comoMapa(lib).secoes);
  const ordemTipos = [];
  for (const s of comoLista(comoMapa(doc).secoes)) {
    const tipo = textoDe(pegar(comoMapa(s), 'tipo'));
    if (tipo !== '' && !ordemTipos.includes(tipo)) ordemTipos.push(tipo);
  }
  for (const tipo of Object.keys(secoesLib)) if (!ordemTipos.includes(tipo)) ordemTipos.push(tipo);
  const lista = [];
  const vistos = new Set();
  for (const tipo of ordemTipos) {
    for (const [chave, def] of Object.entries(registro)) {
      if (pegar(def, 'dono') !== tipo || pegar(def, 'tipo') !== 'imagem') continue;
      const partes = chave.split('.');
      if (partes.length === 2) {
        if (!vistos.has(chave)) { vistos.add(chave); lista.push({ chave, lista: null, id: null }); }
        continue;
      }
      for (const id of itensLista(doc, lib, partes[0])) {
        const k = `${partes[0]}.${id}.${partes[2]}`;
        if (!vistos.has(k)) { vistos.add(k); lista.push({ chave: k, lista: partes[0], id }); }
      }
    }
  }
  return lista;
}

/** Mapa chave → id da foto (sem prefixo) para os espaços sem imagem do documento. */
export function imagensDeExemplo(doc, lib) {
  const fotos = comoMapa(comoMapa(lib).fotos);
  const pools = comoMapa(pegar(comoMapa(fotos.nichos), textoDe(pegar(comoMapa(doc), 'nicho'))));
  const existe = (id) => typeof id === 'string' && temChave(comoMapa(fotos.fotos), id);
  const grupo = (nome) => comoLista(pegar(pools, nome)).filter(existe);
  const cena = grupo('cena');
  if (cena.length === 0) return {};
  const gente = { f: grupo('gente_f'), m: grupo('gente_m') };
  const imagens = comoMapa(comoMapa(doc).imagens);
  const opcoes = opcoesDoDoc(doc);
  const ctx = contextoVariaveis(doc, lib);
  const equipe = itensLista(doc, lib, 'equipe');

  const usados = new Set();
  const contagem = { cena: 0, f: 0, m: 0 };
  const escolher = (pool, nome) => {
    if (pool.length === 0) return null;
    for (const id of pool) {
      if (!usados.has(id)) { usados.add(id); return id; }
    }
    const id = pool[contagem[nome] % pool.length];
    contagem[nome] += 1;
    return id;
  };

  // As fotos da equipe saem primeiro: o destaque "retrato" e a chamada "com pessoa" mostram a
  // mesma pessoa do 1º (e do 2º) item da equipe, cujo nome aparece no cartão.
  const lista = espacos(doc, lib);
  const daEquipe = {};
  for (const e of lista) {
    if (e.lista !== 'equipe') continue;
    const genero = generoDoNome(textoEfetivo(doc, lib, `equipe.${e.id}.n`, ctx));
    daEquipe[e.chave] = gente[genero].length > 0 ? escolher(gente[genero], genero) : escolher(cena, 'cena');
  }
  const fotoEquipe = (i) => (equipe.length > i ? pegar(daEquipe, `equipe.${equipe[i]}.f`) ?? null : null);

  const r = {};
  for (const e of lista) {
    let id;
    if (e.lista === 'equipe') id = daEquipe[e.chave];
    else if (e.chave === 'hero.img' && opcoes.hero === 'retrato' && fotoEquipe(0) !== null) id = fotoEquipe(0);
    else if (e.chave === 'cta.img' && opcoes.cta === 'pessoa' && (fotoEquipe(1) ?? fotoEquipe(0)) !== null) id = fotoEquipe(1) ?? fotoEquipe(0);
    else if (e.chave === 'dep.img' && gente.f.length > 0) id = escolher(gente.f, 'f');
    else id = escolher(cena, 'cena');
    if (id !== null && typeof pegar(imagens, e.chave) !== 'string') r[e.chave] = id;
  }
  return r;
}

/**
 * Para prévias sem servidor (assistente): documento com as fotos de exemplo como ids "x_…" e
 * o mapa de mídia correspondente, com `local` apontando para urlBase (ex.: "/api/fotos-exemplo/").
 */
export function comFotosDeExemplo(doc, lib, urlBase) {
  const escolhidas = imagensDeExemplo(doc, lib);
  const fotos = comoMapa(comoMapa(comoMapa(lib).fotos).fotos);
  const midia = {};
  const imagens = { ...comoMapa(comoMapa(doc).imagens) };
  for (const [chave, foto] of Object.entries(escolhidas)) {
    const f = comoMapa(pegar(fotos, foto));
    const variantes = comoLista(f.variantes).filter((w) => Number.isInteger(w));
    const w = variantes.includes(960) ? 960 : variantes[variantes.length - 1];
    const id = PREFIXO_EXEMPLO + foto;
    imagens[chave] = id;
    midia[id] = {
      largura: f.largura, altura: f.altura, variantes, alt: textoDe(f.alt), tipo: 'foto', formato: 'webp',
      local: `${urlBase}${foto}-${w}.webp`,
    };
  }
  return { doc: { ...doc, imagens }, midia };
}
