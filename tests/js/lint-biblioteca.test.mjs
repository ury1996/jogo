// Lint da biblioteca (contrato §2.2, §3.1–§3.8). Roda sobre biblioteca/ (pula com aviso se
// ainda não houver seções) e sobre tests/fixtures/biblioteca-mini; no fim, casos sintéticos
// garantem que cada regra realmente acusa o problema.
//
// Regras de template (§3.3): nº 1/2 sem cor/fonte fixa, nº 3 data-k só com texto, nº 6 data-li
// de lista conhecida, nº 7 triplo bigode só para .html/.svg/u.*, nº 8 seções só sobre
// booleanos/listas/objetos (nunca texto), nº 9 atributos dos botões de WhatsApp/telefone,
// nº 10 <h1> só no hero, nº 11 formulário pela parcial, nº 12 sem <svg> colado; além disso:
// nomes desconhecidos e campos de item que escondem a raiz (d/c — use dados./conteudo.).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Mustache from '../../public_html/editor/js/vendor/mustache.mjs';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { registroCampos, registroListas } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { normalizar } from '../../public_html/editor/js/compartilhado/texto.mjs';
import { substituirVariaveis } from '../../public_html/editor/js/compartilhado/textos.mjs';
import { normalizarCor } from '../../public_html/editor/js/compartilhado/paleta.mjs';

const RAIZ = fileURLToPath(new URL('../..', import.meta.url));
const temChave = (o, k) => o !== null && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k);

// ---------------------------------------------------------------------------
// Tabelas do contrato

/** §2.2: grupos (escalares) e listas, com dono, max (ou tipo) e repetição. */
export const TABELA = {
  grupos: {
    hero: { dono: 'hero', campos: { eyebrow: 40, titulo: 90, texto: 200, cta: 28, cta2: 28, img: 'imagem', img2: 'imagem', selo: 40 } },
    form: { dono: 'hero', campos: { titulo: 60, nota: 120, botao: 28 } },
    dif: { dono: 'diferenciais', campos: { eyebrow: 40, titulo: 80, img: 'imagem' } },
    cli: { dono: 'clientes', campos: { titulo: 60 } },
    sobre: { dono: 'sobre', campos: { eyebrow: 40, titulo: 80, texto: 420, img: 'imagem', img2: 'imagem', cta: 28 } },
    serv: { dono: 'servicos', campos: { eyebrow: 40, titulo: 80, texto: 220, link: 24 } },
    passos: { dono: 'passos', campos: { eyebrow: 40, titulo: 80, texto: 220, img: 'imagem' } },
    equipe: { dono: 'equipe', campos: { eyebrow: 40, titulo: 80, texto: 220 } },
    dep: { dono: 'depoimentos', campos: { eyebrow: 40, titulo: 80, img: 'imagem' } },
    aval: { dono: 'depoimentos', campos: { nota: 4, txt: 60 } },
    faq: { dono: 'faq', campos: { eyebrow: 40, titulo: 80, texto: 220, img: 'imagem', ajuda: 120 } },
    cta: { dono: 'cta', campos: { titulo: 80, texto: 180, botao: 28, img: 'imagem' } },
    contato: { dono: 'contato', campos: { eyebrow: 40, titulo: 80, texto: 200, botao: 28, mapa: 24 } },
    rodape: { dono: 'rodape', campos: { sobre: 200, texto: 120 } },
  },
  listas: {
    dif: { dono: 'diferenciais', repete: [3, 3], campos: { t: 40, d: 140, ic: 'icone' } },
    cli: { dono: 'clientes', repete: [3, 8], campos: { t: 30 } },
    sobrel: { dono: 'sobre', repete: [4, 4], campos: { t: 60 } },
    serv: { dono: 'servicos', repete: [3, 8], campos: { t: 40, d: 160, img: 'imagem', ic: 'icone' } },
    num: { dono: 'numeros', repete: [3, 3], campos: { v: 8, l: 40 } },
    passos: { dono: 'passos', repete: [3, 3], campos: { t: 40, d: 160 } },
    equipe: { dono: 'equipe', repete: [3, 3], campos: { n: 40, c: 50, f: 'imagem' } },
    dep: { dono: 'depoimentos', repete: [3, 3], campos: { t: 240, n: 40, c: 50 } },
    faq: { dono: 'faq', repete: [3, 10], campos: { q: 100, a: 320 } },
  },
};

/** §3.2: opções (ids estáveis) e tom de cada uma. */
export const CATALOGO = {
  header: { simples: 'branco', barra: 'branco' },
  hero: { 'cards-flutuantes': 'claro', 'fundo-cards': 'escuro', formulario: 'cor', centralizado: 'claro' },
  diferenciais: { 'faixa-icones': 'claro', 'foto-selo': 'claro' },
  clientes: { faixa: 'claro' },
  sobre: { 'duas-fotos': 'claro', 'foto-numeros': 'claro' },
  servicos: { cards: 'claro', lista: 'claro', 'cards-foto': 'claro', blocos: 'claro' },
  numeros: { 'faixa-clara': 'claro', 'faixa-cor': 'cor' },
  passos: { 'linha-tempo': 'claro', 'lista-foto': 'claro' },
  equipe: { 'fotos-nome': 'claro', compacta: 'claro' },
  depoimentos: { 'cards-nota': 'claro', 'destaque-foto': 'claro' },
  faq: { centralizada: 'claro', 'foto-ajuda': 'claro' },
  cta: { 'faixa-cor': 'cor', 'caixa-clara': 'claro', 'foto-fundo': 'escuro' },
  contato: { formulario: 'claro', mapa: 'claro' },
  rodape: { completo: 'escuro', simples: 'escuro' },
};

const TONS = ['claro', 'escuro', 'branco', 'tom-claro', 'cor'];
const TIPOS_CAMPO = ['texto', 'texto-longo', 'imagem', 'icone'];
const UTILITARIOS = ['seta', 'check', 'estrela', 'pin', 'relogio', 'telefone', 'email', 'whatsapp', 'instagram', 'facebook', 'linkedin',
  'youtube', 'google', 'mais', 'menos', 'aspas', 'mapa', 'menu', 'fechar', 'calendario', 'circulo'];
const NOMES_DE_CORES = ('aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood '
  + 'cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen '
  + 'darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray '
  + 'darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia '
  + 'gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender '
  + 'lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey '
  + 'lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen '
  + 'magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen '
  + 'mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange '
  + 'orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple '
  + 'rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue '
  + 'slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen').split(' ');
const RE_COR_LITERAL = new RegExp(`#[0-9a-fA-F]{3,8}(?![\\w-])|\\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\\(|(?<![\\w-])(?:${NOMES_DE_CORES.join('|')})(?![\\w-])`, 'i');

// ---------------------------------------------------------------------------
// Manifestos (§3.1, §2.2, §3.2)

export function lintManifestos(lib, { catalogoCompleto = false } = {}) {
  const erros = [];
  const ancoras = new Map();
  for (const [tipo, sec] of Object.entries(lib.secoes)) {
    const m = sec.manifest ?? {};
    const arq = `secoes/${tipo}/manifest.json`;
    if (m.tipo !== tipo) erros.push(`${arq}: "tipo" deve ser "${tipo}"`);
    if (typeof m.nome !== 'string' || m.nome === '') erros.push(`${arq}: falta "nome"`);
    if (!Number.isInteger(m.ordem)) erros.push(`${arq}: "ordem" deve ser inteiro`);
    const fixaEsperada = tipo === 'header' ? 'inicio' : tipo === 'rodape' ? 'fim' : null;
    if ((m.fixa ?? null) !== fixaEsperada) erros.push(`${arq}: "fixa" deve ser ${JSON.stringify(fixaEsperada)}`);
    if (typeof m.ancora !== 'string' || !/^[a-z][a-z0-9-]*$/.test(m.ancora)) erros.push(`${arq}: "ancora" inválida`);
    else if (ancoras.has(m.ancora)) erros.push(`${arq}: âncora "${m.ancora}" repetida (também em ${ancoras.get(m.ancora)})`);
    else ancoras.set(m.ancora, tipo);
    if (m.menu !== null && (typeof m.menu !== 'string' || m.menu === '')) erros.push(`${arq}: "menu" deve ser texto ou null`);
    if (!Array.isArray(m.opcoes) || m.opcoes.length === 0) erros.push(`${arq}: "opcoes" vazio`);
    const ids = new Set();
    for (const [i, o] of (Array.isArray(m.opcoes) ? m.opcoes : []).entries()) {
      const onde = `${arq}: opções[${i}]`;
      if (typeof o?.id !== 'string' || !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(o.id)) { erros.push(`${onde}: id inválido`); continue; }
      if (ids.has(o.id)) erros.push(`${onde}: id "${o.id}" repetido`);
      ids.add(o.id);
      if (typeof o.nome !== 'string' || o.nome === '') erros.push(`${onde} (${o.id}): falta "nome"`);
      if (!TONS.includes(o.tom)) erros.push(`${onde} (${o.id}): tom "${o.tom}" inválido (${TONS.join(', ')})`);
      if (temChave(CATALOGO, tipo)) {
        if (!temChave(CATALOGO[tipo], o.id)) erros.push(`${onde}: opção "${o.id}" fora do catálogo §3.2 (${Object.keys(CATALOGO[tipo]).join(', ')})`);
        else if (CATALOGO[tipo][o.id] !== o.tom) erros.push(`${onde} (${o.id}): tom deve ser "${CATALOGO[tipo][o.id]}" (§3.2)`);
      }
      if (o.sizes !== undefined && (typeof o.sizes !== 'object' || Array.isArray(o.sizes) || !Object.values(o.sizes).every((v) => typeof v === 'string'))) {
        erros.push(`${onde} (${o.id}): "sizes" deve mapear chave → texto`);
      }
    }
    if (catalogoCompleto && temChave(CATALOGO, tipo)) {
      for (const id of Object.keys(CATALOGO[tipo])) if (!ids.has(id)) erros.push(`${arq}: falta a opção "${id}" do catálogo §3.2`);
    }
    // campos escalares
    const campos = m.campos ?? {};
    for (const [chave, def] of Object.entries(campos)) {
      const [grupo, campo, ...resto] = chave.split('.');
      const onde = `${arq}: campos["${chave}"]`;
      if (resto.length > 0 || !campo || !/^[a-z]+$/.test(grupo) || !/^[a-z][a-z0-9]*$/.test(campo)) { erros.push(`${onde}: chave fora do formato grupo.campo`); continue; }
      if (['itens', 'qtd', 'tem', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9'].includes(campo)) erros.push(`${onde}: nome de campo proibido`);
      const linha = TABELA.grupos[grupo];
      if (!linha || !temChave(linha.campos, campo)) { erros.push(`${onde}: não existe na tabela §2.2`); continue; }
      if (linha.dono !== tipo) erros.push(`${onde}: o dono é o manifesto "${linha.dono}" (§2.2)`);
      erros.push(...conferirCampo(onde, def, linha.campos[campo]));
    }
    // listas
    for (const [nome, ldef] of Object.entries(m.listas ?? {})) {
      const onde = `${arq}: listas["${nome}"]`;
      const linha = TABELA.listas[nome];
      if (!linha) { erros.push(`${onde}: lista fora da tabela §2.2`); continue; }
      if (linha.dono !== tipo) erros.push(`${onde}: o dono é o manifesto "${linha.dono}"`);
      if (JSON.stringify(ldef.repete) !== JSON.stringify(linha.repete)) erros.push(`${onde}: repete deve ser ${JSON.stringify(linha.repete)}`);
      const lc = ldef.campos ?? {};
      for (const campo of Object.keys(linha.campos)) if (!temChave(lc, campo)) erros.push(`${onde}: falta o campo "${campo}"`);
      for (const [campo, def] of Object.entries(lc)) {
        if (!temChave(linha.campos, campo)) erros.push(`${onde}.campos["${campo}"]: fora da tabela §2.2`);
        else erros.push(...conferirCampo(`${onde}.campos["${campo}"]`, def, linha.campos[campo]));
        if (def?.tipo === 'icone' && def.automatico !== undefined && !temChave(lc, def.automatico)) erros.push(`${onde}.campos["${campo}"]: "automatico" aponta para campo inexistente`);
      }
    }
    // completude: o dono define todos os grupos/listas dele
    for (const [grupo, linha] of Object.entries(TABELA.grupos)) {
      if (linha.dono !== tipo) continue;
      for (const campo of Object.keys(linha.campos)) if (!temChave(campos, `${grupo}.${campo}`)) erros.push(`${arq}: falta o campo "${grupo}.${campo}" (§2.2)`);
    }
    for (const [nome, linha] of Object.entries(TABELA.listas)) {
      if (linha.dono === tipo && !temChave(m.listas ?? {}, nome)) erros.push(`${arq}: falta a lista "${nome}" (§2.2)`);
    }
    for (const g of m.alegacoes ?? []) if (!['dep', 'num', 'aval', 'cli'].includes(g)) erros.push(`${arq}: alegação "${g}" desconhecida (§2.4)`);
  }
  if (catalogoCompleto) {
    for (const tipo of Object.keys(CATALOGO)) if (!temChave(lib.secoes, tipo)) erros.push(`secoes/${tipo}: seção do catálogo §3.2 ausente`);
  }
  return erros;
}

function conferirCampo(onde, def, esperado) {
  const erros = [];
  if (!def || typeof def !== 'object') return [`${onde}: definição ausente`];
  if (!TIPOS_CAMPO.includes(def.tipo)) return [`${onde}: tipo "${def.tipo}" inválido`];
  if (esperado === 'imagem' || esperado === 'icone') {
    if (def.tipo !== esperado) erros.push(`${onde}: tipo deve ser "${esperado}"`);
  } else {
    if (def.tipo !== 'texto' && def.tipo !== 'texto-longo') erros.push(`${onde}: tipo deve ser "texto" ou "texto-longo"`);
    if (def.max !== esperado) erros.push(`${onde}: max deve ser ${esperado} (§2.2), está ${def.max}`);
  }
  return erros;
}

// ---------------------------------------------------------------------------
// Templates (§3.3) — esquema tipado da view (§5.3) e caminhada pelos tokens do mustache.js

/**
 * Registro da biblioteca completado com a tabela §2.2 para grupos/listas cujo manifesto
 * ainda não existe (biblioteca em construção): o template pode exibir qualquer chave, e a
 * falta da seção já é acusada pelo lint dos manifestos.
 */
function registrosCompletos(lib) {
  const registro = { ...registroCampos(lib) };
  const listas = { ...registroListas(lib) };
  const def = (esperado) => (esperado === 'imagem' || esperado === 'icone' ? { tipo: esperado } : { tipo: 'texto', max: esperado });
  for (const [grupo, linha] of Object.entries(TABELA.grupos)) {
    for (const [campo, esperado] of Object.entries(linha.campos)) {
      const chave = `${grupo}.${campo}`;
      if (!temChave(registro, chave)) registro[chave] = { ...def(esperado), dono: linha.dono, grupo, campo };
    }
  }
  for (const [nome, linha] of Object.entries(TABELA.listas)) {
    if (temChave(listas, nome)) continue;
    const campos = Object.fromEntries(Object.entries(linha.campos).map(([campo, esperado]) => [campo, def(esperado)]));
    if (temChave(campos, 'ic')) campos.ic.automatico = 't';
    listas[nome] = { repete: linha.repete, campos, dono: linha.dono };
    for (const [campo, d] of Object.entries(campos)) registro[`${nome}.*.${campo}`] = { ...d, dono: linha.dono, lista: nome, campo };
  }
  return { registro, listas };
}

const T = 'texto';
const N = 'numero';
const B = 'booleano';
const H = 'html';
const S = 'svg';
const objeto = (campos, aberto = null) => ({ tipo: 'objeto', campos, aberto });
const lista = (el) => ({ tipo: 'lista', el });

export function esquemaView(lib) {
  const { registro, listas } = registrosCompletos(lib);
  const valor = (def) => (def.tipo === 'imagem' ? objeto({ html: H, vazio: B }) : def.tipo === 'icone' ? objeto({ id: T, svg: S }) : T);
  const grupos = {};
  for (const [chave, def] of Object.entries(registro)) {
    if (chave.includes('*')) continue;
    if (!temChave(grupos, def.grupo)) grupos[def.grupo] = {};
    grupos[def.grupo][def.campo] = valor(def);
  }
  const camposItem = {};
  for (const [nome, ldef] of Object.entries(listas)) {
    const item = { id: T, k: T, i: N, primeiro: B, ultimo: B, par: B };
    for (const [campo, def] of Object.entries(ldef.campos ?? {})) item[campo] = valor(def ?? {});
    camposItem[nome] = item;
    if (!temChave(grupos, nome)) grupos[nome] = {};
    Object.assign(grupos[nome], { itens: lista(objeto(item, nome)), qtd: N, tem: B });
    for (let p = 1; p <= 9; p += 1) grupos[nome][`p${p}`] = objeto(item, nome);
  }
  const c = objeto(Object.fromEntries(Object.entries(grupos).map(([g, campos]) => [g, objeto(campos)])));
  const utilitarios = Object.keys(lib.icones?.utilitarios ?? {});
  const u = utilitarios.length > 0 ? objeto(Object.fromEntries(utilitarios.map((k) => [k, S]))) : objeto({}, '*svg');
  const d = objeto({
    nome: T, cidade: T, uf: T, cidadeUf: T, inicial: T, whatsapp: T, whatsappLink: T, temWhatsapp: B, telefone: T, telefoneLink: T,
    temTelefone: B, email: T, emailLink: T, temEmail: B, endereco: T, enderecoLinhas: lista(T), temEndereco: B, mapaLink: T, mapaEmbed: T,
    horarios: lista(objeto({ dias: T, horas: T })), horariosTexto: T, temHorarios: B, registro: T, temRegistro: B,
    redes: lista(objeto({ rede: T, rotulo: T, url: T, svg: S })), temRedes: B, logo: objeto({ html: H, temLogo: B }), ano: N, segmento: T,
  });
  return objeto({
    c, d, dados: d, conteudo: c, u,
    s: objeto({ tipo: T, opcao: T, fundo: T, escuro: B, indice: N }),
    e: objeto({ acabamento: T, fonte: T, classico: B, moderno: B, direto: B }),
    menu: lista(objeto({ rotulo: T, href: T })),
    modo: objeto({ editor: B, publicar: B }),
  });
}

function campoDe(esquema, nome) {
  if (esquema?.tipo !== 'objeto') return undefined;
  if (temChave(esquema.campos, nome)) return esquema.campos[nome];
  if (esquema.aberto === '*svg') return S;
  return undefined;
}

/** Resolve um nome como o mustache.php (1º segmento na pilha; o resto só dentro do valor). */
function resolver(nome, pilha) {
  if (nome === '.') return { esquema: pilha[pilha.length - 1], quadro: pilha.length - 1 };
  const partes = nome.split('.');
  for (let q = pilha.length - 1; q >= 0; q -= 1) {
    let v = campoDe(pilha[q], partes[0]);
    if (v === undefined) continue;
    for (const p of partes.slice(1)) {
      v = campoDe(v, p);
      if (v === undefined) return { erro: true, quadro: q };
    }
    return { esquema: v, quadro: q };
  }
  return { erro: true, quadro: -1 };
}

function descrever(nome, pilha, r) {
  if (r.quadro > 0) {
    const raiz = resolver(nome, [pilha[0]]);
    if (!raiz.erro) {
      const primeiro = nome.split('.')[0];
      const apelido = primeiro === 'd' ? 'dados' : primeiro === 'c' ? 'conteudo' : null;
      return `"${nome}" é escondido pelo campo "${primeiro}" do contexto atual (no PHP sai vazio)${apelido ? `; use "${apelido}${nome.slice(1)}"` : ''}`;
    }
  }
  return `nome desconhecido "${nome}"`;
}

function caminharTokens(tokens, pilha, ctx, profundidade = 0) {
  for (const tok of tokens) {
    const [tipo, nome] = tok;
    if (tipo === 'text' || tipo === '!' || tipo === '=') continue;
    if (tipo === '>') {
      const parcial = ctx.lib.parciais?.[nome];
      if (typeof parcial !== 'string') ctx.erro(`parcial inexistente "{{> ${nome}}}"`);
      else if (profundidade < 6) {
        ctx.parciaisUsadas.add(nome);
        try {
          caminharTokens(Mustache.parse(parcial), pilha, { ...ctx, erro: (m) => ctx.erro(`(parcial ${nome}) ${m}`) }, profundidade + 1);
        } catch (e) {
          ctx.erro(`parcial ${nome} não compila: ${e.message}`);
        }
      }
      continue;
    }
    const r = resolver(nome, pilha);
    if (r.erro) ctx.erro(descrever(nome, pilha, r));
    const esquema = r.erro ? null : r.esquema;
    if (tipo === 'name') {
      if (esquema && typeof esquema === 'object') ctx.erro(`"{{${nome}}}" imprime ${esquema.tipo} (vira "[object Object]"/lista no HTML)`);
    } else if (tipo === '&') {
      const permitido = /(^|\.)(html|svg)$/.test(nome) || /^u\.[a-z]+$/.test(nome);
      if (!permitido || (esquema && esquema !== H && esquema !== S)) ctx.erro(`triplo bigode só para chaves .html/.svg/u.* (§3.3 nº 7): "{{{${nome}}}}"`);
    } else if (tipo === '#' || tipo === '^') {
      if (esquema === T) ctx.erro(`seção sobre campo de texto "{{${tipo}${nome}}}" — use tem/vazio/temX (§3.3 nº 8; "0" é falso no PHP)`);
      let novaPilha = pilha;
      if (tipo === '#' && esquema && typeof esquema === 'object') novaPilha = [...pilha, esquema.tipo === 'lista' ? esquema.el : esquema];
      else if (tipo === '#' && esquema === T) novaPilha = [...pilha, T];
      caminharTokens(tok[4], novaPilha, ctx, profundidade);
    }
  }
}

const RE_DATA_K = /<([a-zA-Z][\w-]*)\b([^>]*?)\sdata-k="([^"]*)"([^>]*)>([^<]*)(?:<(\/?)([a-zA-Z][\w-]*))?/g;

export function lintTemplate(lib, arquivo, texto, { tipo = null, parcial = false } = {}, esquema = esquemaView(lib)) {
  const erros = [];
  const erro = (m) => erros.push(`${arquivo}: ${m}`);
  const { registro, listas } = registrosCompletos(lib);
  const camposTextoDeLista = new Set();
  const camposImagemDeLista = new Set();
  for (const ldef of Object.values(listas)) {
    for (const [campo, def] of Object.entries(ldef.campos ?? {})) {
      if (def?.tipo === 'imagem') camposImagemDeLista.add(campo);
      else if (def?.tipo !== 'icone') camposTextoDeLista.add(campo);
    }
  }
  // nº 3: elemento com data-k contém só texto
  for (const m of texto.matchAll(RE_DATA_K)) {
    const [, tag, , chave, , conteudo, barra, fecha] = m;
    if (barra !== '/' || fecha !== tag || conteudo.includes('{{{') || conteudo.includes('{{>')) {
      erro(`elemento <${tag} data-k="${chave}"> deve conter só o texto, sem elementos filhos (§3.3 nº 3)`);
    }
    if (!chave.includes('{{')) {
      const def = registro[chave];
      if (!def || def.tipo === 'imagem' || def.tipo === 'icone' || chave.split('.').length !== 2) erro(`data-k="${chave}" não é um campo de texto conhecido`);
    } else if (!/^\{\{k\}\}\.[a-z]+$/.test(chave) || !camposTextoDeLista.has(chave.split('.').pop())) {
      erro(`data-k="${chave}" deve ser "{{k}}.campo" com campo de texto de lista`);
    }
  }
  for (const m of texto.matchAll(/data-img="([^"]*)"/g)) {
    const chave = m[1];
    const ok = chave.includes('{{')
      ? /^\{\{k\}\}\.[a-z]+$/.test(chave) && camposImagemDeLista.has(chave.split('.').pop())
      : registro[chave]?.tipo === 'imagem';
    if (!ok) erro(`data-img="${chave}" não é um campo de imagem conhecido`);
  }
  for (const m of texto.matchAll(/data-li="([^"]*)"/g)) if (!temChave(listas, m[1])) erro(`data-li="${m[1]}" não é uma lista conhecida (§3.3 nº 6)`);
  // nº 1/2: sem cor ou fonte fixa em style=""
  for (const m of texto.matchAll(/\sstyle="([^"]*)"/g)) {
    if (RE_COR_LITERAL.test(m[1].replace(/var\([^)]*\)/g, '')) || /font-family/i.test(m[1])) erro(`style="${m[1]}" com cor/fonte fixa (§3.3 nº 2)`);
  }
  // nº 9: botões de WhatsApp e telefone
  for (const m of texto.matchAll(/<a\b[^>]*href="\{\{(?:d|dados)\.(whatsappLink|telefoneLink)\}\}"[^>]*>/g)) {
    const tag = m[0];
    if (m[1] === 'whatsappLink' && !(/\sdata-ev="whatsapp"/.test(tag) && /\sdata-pos="[^"]+"/.test(tag) && /\starget="_blank"/.test(tag) && /\srel="noopener"/.test(tag))) {
      erro(`botão de WhatsApp sem data-ev="whatsapp" data-pos target="_blank" rel="noopener" (§3.3 nº 9): ${tag}`);
    }
    if (m[1] === 'telefoneLink' && !/\sdata-ev="telefone"/.test(tag)) erro(`link de telefone sem data-ev="telefone" (§3.3 nº 9): ${tag}`);
  }
  // Linha só com tags de controle ({{#}} {{^}} {{/}} {{>}} {{!}}) e nada mais: o mustache.js
  // apaga a linha inteira; o mustache.php só apaga se houver UMA tag. Uma por linha.
  texto.split('\n').forEach((linha, n) => {
    const tags = linha.match(/\{\{\{?[^}]*\}?\}\}/g) ?? [];
    const resto = linha.replace(/\{\{\{?[^}]*\}?\}\}/g, '').trim();
    if (tags.length >= 2 && resto === '' && tags.every((t) => /^\{\{\s*[#^/>!=]/.test(t))) {
      erro(`linha ${n + 1}: "${linha.trim()}" — linha só com tags de bloco deve ter uma tag só (o mustache.js e o mustache.php tratam diferente)`);
    }
  });
  // nº 10, 11, 12
  const h1 = (texto.match(/<h1\b/g) ?? []).length;
  if (h1 > 0 && tipo !== 'hero') erro('só o hero usa <h1> (§3.3 nº 10)');
  if (h1 > 1) erro('mais de um <h1>');
  if (!parcial && /<form\b/.test(texto)) erro('formulário deve vir da parcial {{> formulario}} (§3.3 nº 11)');
  if (/<svg\b/i.test(texto)) erro('SVG colado no template; use u.* (§3.3 nº 12)');
  // nº 7, nº 8, nomes e sombras
  const ctx = { lib, erro, parciaisUsadas: new Set() };
  try {
    caminharTokens(Mustache.parse(texto), [esquema], ctx);
  } catch (e) {
    erro(`template não compila: ${e.message}`);
  }
  return { erros, parciaisUsadas: ctx.parciaisUsadas };
}

export function lintTemplates(lib, dir) {
  const erros = [];
  const esquema = esquemaView(lib);
  const usadas = new Set();
  for (const [tipo, sec] of Object.entries(lib.secoes)) {
    const opcoes = new Set((sec.manifest?.opcoes ?? []).map((o) => o?.id));
    for (const id of opcoes) {
      if (typeof id === 'string' && !temChave(sec.templates, id)) erros.push(`secoes/${tipo}/${id}.mustache: arquivo ausente (opção do manifesto)`);
    }
    const pasta = path.join(dir, 'secoes', tipo);
    for (const arquivo of fs.readdirSync(pasta).filter((n) => n.endsWith('.mustache'))) {
      if (!opcoes.has(arquivo.replace(/\.mustache$/, ''))) erros.push(`secoes/${tipo}/${arquivo}: template sem opção no manifesto`);
    }
    for (const [id, texto] of Object.entries(sec.templates)) {
      const r = lintTemplate(lib, `secoes/${tipo}/${id}.mustache`, texto, { tipo }, esquema);
      erros.push(...r.erros);
      for (const p of r.parciaisUsadas) usadas.add(p);
    }
  }
  for (const [nome, texto] of Object.entries(lib.parciais ?? {})) {
    if (usadas.has(nome)) continue;
    erros.push(...lintTemplate(lib, `parciais/${nome}.mustache`, texto, { parcial: true }, esquema).erros);
  }
  return erros;
}

// ---------------------------------------------------------------------------
// CSS das seções (§3.4)

function dividirTopo(texto, separador) {
  const partes = [];
  let nivel = 0;
  let ini = 0;
  for (let i = 0; i < texto.length; i += 1) {
    const ch = texto[i];
    if (ch === '(' || ch === '[') nivel += 1;
    else if (ch === ')' || ch === ']') nivel -= 1;
    else if (ch === separador && nivel === 0) {
      partes.push(texto.slice(ini, i));
      ini = i + 1;
    }
  }
  partes.push(texto.slice(ini));
  return partes.map((p) => p.trim()).filter((p) => p !== '');
}

/** Lê o CSS em regras { seletor, declaracoes, contexto[] } (sem aninhamento CSS). */
export function analisarCss(css) {
  const s = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const regras = [];
  const problemas = [];
  let i = 0;
  const pularAspas = () => {
    const q = s[i];
    i += 1;
    while (i < s.length && s[i] !== q) i += s[i] === '\\' ? 2 : 1;
    i += 1;
  };
  const lerBloco = (contexto) => {
    while (i < s.length) {
      const ini = i;
      let nivel = 0;
      while (i < s.length) {
        const ch = s[i];
        if (ch === '"' || ch === "'") { pularAspas(); continue; }
        if (ch === '(') nivel += 1;
        else if (ch === ')') nivel -= 1;
        else if (nivel === 0 && (ch === '{' || ch === '}' || ch === ';')) break;
        i += 1;
      }
      const preludio = s.slice(ini, i).trim();
      if (i >= s.length) { if (preludio) problemas.push(`texto solto no fim: "${preludio.slice(0, 40)}"`); return; }
      const ch = s[i];
      i += 1;
      if (ch === '}') { if (preludio) problemas.push(`declaração fora de regra: "${preludio.slice(0, 60)}"`); return; }
      if (ch === ';') { if (preludio) problemas.push(`instrução solta "${preludio.slice(0, 60)}"`); continue; }
      if (preludio.startsWith('@')) {
        const nome = /^@([\w-]+)/.exec(preludio)?.[1] ?? '';
        if (nome === 'keyframes') {
          const iniK = i;
          let profundidade = 1;
          while (i < s.length && profundidade > 0) { if (s[i] === '{') profundidade += 1; else if (s[i] === '}') profundidade -= 1; i += 1; }
          regras.push({ seletor: preludio, declaracoes: s.slice(iniK, i - 1), contexto, keyframes: true });
        } else {
          regras.push({ arroba: preludio, contexto });
          lerBloco([...contexto, preludio]);
        }
        continue;
      }
      const iniD = i;
      while (i < s.length && s[i] !== '}') {
        if (s[i] === '"' || s[i] === "'") { pularAspas(); continue; }
        if (s[i] === '{') { problemas.push(`aninhamento CSS em "${preludio.slice(0, 60)}" não é suportado`); break; }
        i += 1;
      }
      regras.push({ seletor: preludio, declaracoes: s.slice(iniD, i), contexto });
      i += 1;
    }
  };
  lerBloco([]);
  return { regras, problemas };
}

const escaparRe = (t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export function lintCss(tipo, opcoes, css, arquivo = `secoes/${tipo}/estilo.css`) {
  const erros = [];
  const { regras, problemas } = analisarCss(css);
  for (const p of problemas) erros.push(`${arquivo}: ${p}`);
  const ops = opcoes.filter((o) => typeof o === 'string').map(escaparRe).join('|') || '(?!)';
  const reSeletor = new RegExp(`^(?:\\.k-(?:classico|moderno|direto)(?:\\s*[>+~]\\s*|\\s+))?(?:\\.rk-sec--${escaparRe(tipo)}|\\.rk-op--${escaparRe(tipo)}-(?:${ops}))(?![\\w-])`);
  for (const r of regras) {
    if (r.arroba !== undefined) {
      const a = r.arroba.replace(/\s+/g, ' ');
      if (/^@container\b/.test(a)) {
        if (!/^@container site \(max-width: ?(1060|760)px\)$/.test(a)) erros.push(`${arquivo}: "${r.arroba}" — só @container site (max-width: 1060px|760px) (§3.4)`);
      } else erros.push(`${arquivo}: "${r.arroba}" não é permitido (só @container site e @keyframes) (§3.4)`);
      continue;
    }
    if (!r.keyframes) {
      for (const sel of dividirTopo(r.seletor, ',')) {
        if (!reSeletor.test(sel)) erros.push(`${arquivo}: seletor "${sel}" deve começar com .rk-sec--${tipo} ou .rk-op--${tipo}-{opção} (§3.4)`);
      }
    }
    for (const decl of dividirTopo(r.declaracoes, ';')) {
      const pos = decl.indexOf(':');
      if (pos < 0) { if (!r.keyframes) erros.push(`${arquivo}: declaração inválida "${decl.slice(0, 60)}"`); continue; }
      const prop = decl.slice(0, pos).trim().toLowerCase();
      const valor = decl.slice(pos + 1).replace(/"[^"]*"|'[^']*'/g, '""').replace(/url\([^)]*\)/gi, 'url()');
      if (RE_COR_LITERAL.test(valor.replace(/var\(--[\w-]+/g, 'var('))) erros.push(`${arquivo}: cor literal em "${decl.trim().slice(0, 80)}" — use var(--…) (§3.4)`);
      if (prop === 'font-family' && !/^\s*(var\(--[\w-]+\)|inherit)\s*$/.test(valor)) erros.push(`${arquivo}: fonte fixa em "${decl.trim()}" — use var(--ft)/var(--fb)`);
    }
  }
  return erros;
}

export function lintEstilos(lib) {
  const erros = [];
  for (const [tipo, sec] of Object.entries(lib.secoes)) {
    erros.push(...lintCss(tipo, (sec.manifest?.opcoes ?? []).map((o) => o?.id), sec.css ?? ''));
  }
  return erros;
}

// ---------------------------------------------------------------------------
// Nichos, modelos, ícones e fontes

export function lintConteudo(lib, { estrito = false } = {}) {
  const erros = [];
  const registro = registroCampos(lib);
  const listas = registroListas(lib);
  const fontes = Object.keys(lib.fontes?.pares ?? {});
  const idsIcones = new Set([...(lib.icones?.icones ?? []).map((i) => i?.id), ...Object.keys(lib.icones?.utilitarios ?? {})]);
  for (const [id, modelo] of Object.entries(lib.modelos)) {
    const arq = `modelos/${id}.json`;
    if (!['classico', 'moderno', 'direto'].includes(modelo.acabamento)) erros.push(`${arq}: acabamento inválido`);
    for (const [i, e] of (modelo.secoes ?? []).entries()) {
      const [tipo, opcao, filtro] = Array.isArray(e) ? e : [e?.tipo, e?.opcao, e];
      const sec = lib.secoes[tipo];
      if (!sec) erros.push(`${arq}: secoes[${i}] usa a seção "${tipo}", que não existe`);
      else if (!(sec.manifest?.opcoes ?? []).some((o) => o?.id === opcao)) erros.push(`${arq}: secoes[${i}] usa a opção "${tipo}/${opcao}", que não existe`);
      for (const f of ['so', 'exceto']) {
        if (filtro?.[f] !== undefined && !Array.isArray(filtro[f])) erros.push(`${arq}: secoes[${i}].${f} deve ser lista de nichos`);
        // Na biblioteca real todo nicho citado existe; a de teste cita nichos que não tem.
        for (const n of estrito ? filtro?.[f] ?? [] : []) if (!temChave(lib.nichos, n)) erros.push(`${arq}: secoes[${i}].${f} cita o nicho "${n}", que não existe`);
      }
    }
    const tipos = (modelo.secoes ?? []).map((e) => (Array.isArray(e) ? e[0] : e?.tipo));
    if (tipos[0] !== 'header') erros.push(`${arq}: header deve ser a primeira seção`);
    if (tipos[tipos.length - 1] !== 'rodape') erros.push(`${arq}: rodape deve ser a última seção`);
  }
  const conferirTextos = (arq, textos, contexto) => {
    for (const [chave, valor] of Object.entries(textos ?? {})) {
      const partes = chave.split('.');
      let def = null;
      if (partes.length === 2) def = registro[chave];
      else if (partes.length === 3 && /^[a-z0-9]+$/.test(partes[1])) def = registro[`${partes[0]}.*.${partes[2]}`];
      if (!def || def.tipo === 'imagem' || def.tipo === 'icone') { erros.push(`${arq}: texto "${chave}" não corresponde a campo de texto`); continue; }
      if (typeof valor !== 'string') { erros.push(`${arq}: texto "${chave}" deve ser string`); continue; }
      const efetivo = substituirVariaveis(valor, contexto);
      if (Number.isInteger(def.max) && [...efetivo].length > def.max) erros.push(`${arq}: "${chave}" tem ${[...efetivo].length} caracteres (máx. ${def.max}) com o exemplo`);
      const variaveis = valor.match(/\{[a-z]+\}/g) ?? [];
      for (const v of variaveis) if (!['{nome}', '{cidade}', '{segmento}'].includes(v)) erros.push(`${arq}: "${chave}" usa variável desconhecida ${v}`);
    }
  };
  const conferirListas = (arq, ls) => {
    for (const [nome, ids] of Object.entries(ls ?? {})) {
      if (!temChave(listas, nome)) { erros.push(`${arq}: lista "${nome}" não existe`); continue; }
      const [min, max] = listas[nome].repete ?? [0, Infinity];
      if (!Array.isArray(ids) || !ids.every((x) => typeof x === 'string' && /^[0-9]+$/.test(x))) erros.push(`${arq}: listas.${nome} deve ter ids "1", "2", …`);
      else if (ids.length < min || ids.length > max) erros.push(`${arq}: listas.${nome} tem ${ids.length} itens (repete ${min}–${max})`);
    }
  };
  const comum = lib.comum ?? {};
  conferirTextos('nichos/comum.json', comum.textos, { nome: 'Empresa Exemplo Ltda', cidade: 'São José dos Campos', segmento: 'Segmento' });
  conferirListas('nichos/comum.json', comum.listas);
  for (const [lista, campos] of Object.entries(comum.novoItem ?? {})) {
    if (!temChave(listas, lista)) erros.push(`nichos/comum.json: novoItem.${lista} não é lista`);
    else for (const campo of Object.keys(campos ?? {})) if (!temChave(listas[lista].campos ?? {}, campo)) erros.push(`nichos/comum.json: novoItem.${lista}.${campo} não é campo da lista`);
  }
  for (const [id, nicho] of Object.entries(lib.nichos)) {
    const arq = `nichos/${id}.json`;
    if (nicho.id !== id) erros.push(`${arq}: "id" deve ser "${id}"`);
    const esps = nicho.especialidades ?? [];
    if (!Array.isArray(esps) || esps.length === 0) erros.push(`${arq}: sem especialidades [M7]`);
    for (const e of esps) {
      if (!e?.id || !e.nome || !e.segmento || !e.schemaOrg) erros.push(`${arq}: especialidade ${JSON.stringify(e?.id)} sem id/nome/segmento/schemaOrg`);
      if (e?.registroObrigatorio && !e.rotuloRegistro) erros.push(`${arq}: especialidade "${e.id}" com registro obrigatório sem rotuloRegistro`);
    }
    for (const campo of ['nome', 'cidade', 'uf', 'whatsapp']) if (typeof nicho.exemplo?.[campo] !== 'string' || nicho.exemplo[campo] === '') erros.push(`${arq}: exemplo.${campo} ausente`);
    for (const modelo of Object.keys(lib.modelos)) {
      const p = nicho.padroesPorModelo?.[modelo];
      if (!p) { erros.push(`${arq}: padroesPorModelo.${modelo} ausente`); continue; }
      if (normalizarCor(p.cor) !== p.cor) erros.push(`${arq}: padroesPorModelo.${modelo}.cor deve ser #rrggbb minúsculo`);
      if (!fontes.includes(p.fonte)) erros.push(`${arq}: padroesPorModelo.${modelo}.fonte "${p.fonte}" não existe`);
    }
    for (const tipo of Object.keys(nicho.menu ?? {})) if (!temChave(lib.secoes, tipo)) erros.push(`${arq}: menu.${tipo} não é seção`);
    if (typeof nicho.mensagemWhatsapp !== 'string' || nicho.mensagemWhatsapp === '') erros.push(`${arq}: mensagemWhatsapp ausente`);
    for (const [lista, ids] of Object.entries(nicho.iconesPadrao ?? {})) {
      if (!temChave(listas, lista)) erros.push(`${arq}: iconesPadrao.${lista} não é lista`);
      for (const ic of ids ?? []) if (!idsIcones.has(ic)) erros.push(`${arq}: iconesPadrao.${lista} cita o ícone "${ic}", que não existe`);
    }
    const ex = nicho.exemplo ?? {};
    conferirTextos(arq, nicho.textos, { nome: ex.nome, cidade: ex.cidade, segmento: esps.reduce((m, e) => ((e?.segmento ?? '').length > m.length ? e.segmento : m), '') });
    conferirListas(arq, nicho.listas);
  }
  return erros;
}

export function lintIcones(lib) {
  const erros = [];
  const arq = 'icones/icones.json';
  const icones = lib.icones ?? {};
  const ids = new Set();
  const svgOk = (onde, svg) => {
    if (typeof svg !== 'string' || !svg.startsWith('<svg')) { erros.push(`${arq}: ${onde} sem SVG`); return; }
    const raiz = /^<svg\b[^>]*>/.exec(svg)?.[0] ?? '';
    if (/\s(width|height)=/.test(raiz)) erros.push(`${arq}: ${onde} com width/height no <svg>`);
    if (!/\sviewBox=/.test(raiz)) erros.push(`${arq}: ${onde} sem viewBox`);
    if (!/\saria-hidden="true"/.test(raiz) || !/\sfocusable="false"/.test(raiz)) erros.push(`${arq}: ${onde} sem aria-hidden="true" focusable="false"`);
    if (/<title\b|<script\b|\son[a-z]+=|javascript:/i.test(svg)) erros.push(`${arq}: ${onde} com <title>, <script> ou atributo on*`);
    if (!/currentColor/.test(svg)) erros.push(`${arq}: ${onde} sem currentColor`);
  };
  for (const [i, ic] of (icones.icones ?? []).entries()) {
    const onde = `icones[${i}] (${ic?.id})`;
    if (typeof ic?.id !== 'string' || !/^[a-z0-9-]+$/.test(ic.id)) erros.push(`${arq}: ${onde} id inválido`);
    else if (ids.has(ic.id)) erros.push(`${arq}: ${onde} id repetido`);
    ids.add(ic?.id);
    if (typeof ic?.nome !== 'string' || ic.nome === '') erros.push(`${arq}: ${onde} sem nome`);
    for (const p of ic?.palavras ?? []) if (normalizar(p) !== p) erros.push(`${arq}: ${onde} palavra "${p}" não normalizada`);
    for (const peso of ['fino', 'duotone', 'preenchido']) svgOk(`${onde}.${peso}`, ic?.svg?.[peso]);
  }
  for (const nome of UTILITARIOS) {
    const u = icones.utilitarios?.[nome];
    if (!u) { erros.push(`${arq}: falta o utilitário "${nome}"`); continue; }
    for (const peso of ['fino', 'duotone', 'preenchido']) svgOk(`utilitarios.${nome}.${peso}`, u.svg?.[peso]);
  }
  return erros;
}

export function lintFontes(lib, dir) {
  const erros = [];
  const arq = 'fontes/fontes.json';
  const pares = lib.fontes?.pares ?? {};
  for (const id of ['classica', 'editorial', 'moderna', 'amigavel']) {
    const p = pares[id];
    if (!p || !p.nome || !p.titulos || !p.texto) erros.push(`${arq}: par "${id}" incompleto`);
  }
  const familias = new Set();
  for (const a of lib.fontes?.arquivos ?? []) {
    familias.add(a?.familia);
    if (!/^[a-z0-9.-]+\.woff2$/.test(a?.arquivo ?? '')) erros.push(`${arq}: arquivo inválido ${JSON.stringify(a?.arquivo)}`);
    else if (!fs.existsSync(path.join(dir, 'fontes', a.arquivo))) erros.push(`${arq}: ${a.arquivo} não existe em fontes/`);
  }
  return erros;
}

// ---------------------------------------------------------------------------
// Execução

const formatar = (erros) => `\n  ${erros.join('\n  ')}\n(${erros.length} problema(s))`;

function verificarBiblioteca(rotulo, dir, { catalogoCompleto }) {
  describe(`lint ${rotulo}`, () => {
    let lib = null;
    let motivo = null;
    const temSecoes = fs.existsSync(path.join(dir, 'secoes')) && fs.readdirSync(path.join(dir, 'secoes')).some((n) => fs.existsSync(path.join(dir, 'secoes', n, 'manifest.json')));
    if (!temSecoes) motivo = `${rotulo}: ainda não há seções (secoes/*/manifest.json) — lint pulado`;
    else {
      try {
        lib = carregarBiblioteca(dir);
      } catch (e) {
        test('a biblioteca carrega', () => assert.fail(e.message));
        return;
      }
    }
    const caso = (nome, precisa, fn) => test(nome, (t) => {
      if (motivo) return t.skip(motivo);
      const falta = precisa(lib);
      if (falta) return t.skip(`${rotulo}: ${falta} — verificação pulada`);
      const erros = fn();
      assert.deepEqual(erros, [], formatar(erros));
    });
    const sempre = () => null;
    caso('manifestos: opções (id/nome/tom), campos e listas conforme §2.2 e §3.2', sempre, () => lintManifestos(lib, { catalogoCompleto }));
    caso('templates: regras do §3.3 (nº 3, 7, 8 …) e templates ⇔ arquivos', sempre, () => lintTemplates(lib, dir));
    caso('estilo.css: seletores com prefixo da seção, só @container site, sem cores literais (§3.4)', sempre, () => lintEstilos(lib));
    caso('nichos, comum e modelos: referências válidas e textos dentro do limite', (l) => (Object.keys(l.nichos).length === 0 || Object.keys(l.modelos).length === 0 ? 'sem nichos ou modelos' : null), () => lintConteudo(lib, { estrito: catalogoCompleto }));
    caso('icones.json: ids, palavras normalizadas, SVG normalizado, utilitários (§3.7)', (l) => (!Array.isArray(l.icones?.icones) ? 'sem icones/icones.json' : null), () => lintIcones(lib));
    caso('fontes.json: 4 pares e arquivos existentes (§3.8)', (l) => (!l.fontes?.pares ? 'sem fontes/fontes.json' : null), () => lintFontes(lib, dir));
  });
}

verificarBiblioteca('biblioteca/', path.join(RAIZ, 'biblioteca'), { catalogoCompleto: true });
verificarBiblioteca('tests/fixtures/biblioteca-mini', path.join(RAIZ, 'tests/fixtures/biblioteca-mini'), { catalogoCompleto: false });

describe('o lint acusa cada regra', () => {
  const lib = carregarBiblioteca(path.join(RAIZ, 'tests/fixtures/biblioteca-mini'));
  const acusa = (texto, re, opcoes = { tipo: 'servicos' }) => {
    const { erros } = lintTemplate(lib, 'x.mustache', texto, opcoes);
    assert.ok(erros.some((e) => re.test(e)), `esperava ${re} em: ${JSON.stringify(erros)}`);
  };
  const limpo = (texto, opcoes = { tipo: 'servicos' }) => assert.deepEqual(lintTemplate(lib, 'x.mustache', texto, opcoes).erros, []);

  test('nº 3: data-k com elemento filho', () => {
    acusa('<h2 data-k="serv.titulo"><b>{{c.serv.titulo}}</b></h2>', /só o texto/);
    acusa('<p data-k="serv.titulo">{{c.serv.titulo}}<br>x</p>', /só o texto/);
    acusa('<p data-k="serv.inexistente">{{c.serv.titulo}}</p>', /não é um campo de texto/);
    limpo('<h2 class="rk-h2" data-k="serv.titulo">{{c.serv.titulo}}</h2>');
    limpo('<ul data-li="serv">{{#c.serv.itens}}<li data-it="{{id}}"><span data-k="{{k}}.t">{{t}}</span></li>{{/c.serv.itens}}</ul>');
  });

  test('nº 7: triplo bigode só para .html/.svg/u.*', () => {
    acusa('{{{c.serv.titulo}}}', /nº 7/);
    acusa('{{&c.serv.titulo}}', /nº 7/);
    limpo('{{{c.hero.img.html}}}{{{u.seta}}}{{#c.serv.p1}}{{{ic.svg}}}{{{img.html}}}{{/c.serv.p1}}{{{d.logo.html}}}');
  });

  test('nº 8: seção sobre texto', () => {
    acusa('{{#c.hero.texto}}x{{/c.hero.texto}}', /nº 8/);
    acusa('{{^d.whatsapp}}x{{/d.whatsapp}}', /nº 8/);
    acusa('{{#c.serv.itens}}{{#t}}x{{/t}}{{/c.serv.itens}}', /nº 8/);
    limpo('{{#c.serv.tem}}x{{/c.serv.tem}}{{^c.hero.img.vazio}}x{{/c.hero.img.vazio}}{{#d.temWhatsapp}}x{{/d.temWhatsapp}}{{#d.logo.temLogo}}x{{/d.logo.temLogo}}{{#c.serv.qtd}}x{{/c.serv.qtd}}');
  });

  test('sombra: campo d/c do item esconde a raiz; nomes desconhecidos', () => {
    acusa('{{#c.serv.itens}}<a href="{{d.whatsappLink}}">x</a>{{/c.serv.itens}}', /escondido pelo campo "d".*dados\.whatsappLink/);
    limpo('{{#c.faq.p1}}{{c.hero.titulo}}{{/c.faq.p1}}');
    acusa('{{c.hero.titlo}}', /nome desconhecido "c.hero.titlo"/);
    acusa('{{#c.serv.itens}}{{q}}{{/c.serv.itens}}', /nome desconhecido "q"/);
    acusa('{{c.hero.img}}', /imprime objeto/);
    limpo('{{#c.serv.itens}}{{dados.whatsappLink}}{{conteudo.hero.titulo}}{{k}}{{i}}{{/c.serv.itens}}{{#d.enderecoLinhas}}{{.}}{{/d.enderecoLinhas}}{{#d.horarios}}{{dias}}{{/d.horarios}}');
  });

  test('nº 9, 10, 11, 12 e cor fixa no template', () => {
    acusa('<a href="{{d.whatsappLink}}">x</a>', /nº 9/);
    acusa('<a href="{{d.telefoneLink}}">x</a>', /data-ev="telefone"/);
    limpo('<a class="rk-btn" href="{{d.whatsappLink}}" data-ev="whatsapp" data-pos="servicos" target="_blank" rel="noopener">x</a>');
    acusa('<h1>x</h1>', /nº 10/);
    limpo('<h1 data-k="hero.titulo">{{c.hero.titulo}}</h1>', { tipo: 'hero' });
    acusa('<form></form>', /nº 11/);
    acusa('<svg viewBox="0 0 1 1"></svg>', /nº 12/);
    acusa('<div style="color:#fff">x</div>', /cor\/fonte fixa/);
    limpo('<div class="rk-foto" data-img="hero.img" style="--ar:4/3">{{{c.hero.img.html}}}</div>');
    acusa('<div data-img="hero.titulo"></div>', /data-img/);
    acusa('<ul data-li="xyz"></ul>', /data-li/);
    acusa('{{> nao-existe}}', /parcial inexistente/);
    acusa('{{#aberta}}', /não compila/);
    acusa('<ul>\n  {{#c.serv.tem}}{{#c.serv.itens}}\n  <li>{{t}}</li>\n  {{/c.serv.itens}}{{/c.serv.tem}}\n</ul>', /uma tag só/);
    limpo('<ul>\n  {{#c.serv.tem}}\n  {{#c.serv.itens}}<li>{{t}}</li>{{/c.serv.itens}}\n  {{/c.serv.tem}}\n</ul>');
  });

  test('CSS (§3.4)', () => {
    const erros = lintCss('hero', ['formulario'], [
      'h2{color:var(--fg)}',
      '.rk-sec--heroi .x{margin:0}',
      '.rk-op--hero-outra .x{margin:0}',
      '.rk-sec--hero .x{color:#fff;background:rgb(0 0 0);border-color:white}',
      '.rk-sec--hero .y{font-family:Arial}',
      '@media (max-width: 760px){.rk-sec--hero .x{margin:0}}',
      '@container site (max-width: 900px){.rk-sec--hero .x{margin:0}}',
    ].join('\n'));
    for (const re of [/"h2"/, /"\.rk-sec--heroi \.x"/, /"\.rk-op--hero-outra \.x"/, /cor literal.*#fff/, /cor literal.*rgb/, /cor literal.*white/, /fonte fixa/, /@media/, /900px/]) {
      assert.ok(erros.some((e) => re.test(e)), `esperava ${re} em ${JSON.stringify(erros)}`);
    }
    assert.deepEqual(lintCss('hero', ['formulario'], [
      '/* comentário com #fff */',
      '.rk-sec--hero .x, .rk-op--hero-formulario .y:not(.a, .b){color:var(--fg);background:transparent;border:1px solid currentColor}',
      '.k-moderno .rk-sec--hero .x{border-radius:var(--rc);font-family:var(--ft);grid-template-areas:"red blue"}',
      '.rk-sec--hero.rk-bg--escuro .x{color:var(--fg2)}',
      '@keyframes hero-sobe{from{opacity:0}to{opacity:1}}',
      '@container site (max-width: 1060px){.rk-sec--hero .x{gap:8px}}',
      '@container site (max-width:760px){.rk-op--hero-formulario .x{gap:4px}}',
    ].join('\n')), []);
  });

  test('manifesto fora da tabela §2.2', () => {
    const ruim = JSON.parse(JSON.stringify(lib));
    ruim.secoes.servicos.manifest.campos['serv.titulo'].max = 99;
    ruim.secoes.servicos.manifest.campos['serv.extra'] = { tipo: 'texto', max: 10 };
    ruim.secoes.servicos.manifest.listas.serv.repete = [1, 20];
    ruim.secoes.servicos.manifest.opcoes[0].tom = 'roxo';
    delete ruim.secoes.servicos.manifest.campos['serv.link'];
    const erros = lintManifestos(ruim);
    for (const re of [/serv\.titulo.*max deve ser 80/, /serv\.extra.*não existe na tabela/, /repete deve ser \[3,8\]/, /tom "roxo" inválido/, /falta o campo "serv\.link"/]) {
      assert.ok(erros.some((e) => re.test(e)), `esperava ${re} em ${JSON.stringify(erros)}`);
    }
  });
});
