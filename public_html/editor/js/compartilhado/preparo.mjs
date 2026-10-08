// Preparo do site: view de cada template, invólucros, imagens, menu e dados derivados.
// PARIDADE OBRIGATÓRIA com app/Preparo/Preparo.php e app/Preparo/Html.php (contrato §5).

import Mustache from '../vendor/mustache.mjs';
import {
  arred, codificarUri, colapsarEspacos, comoLista, comoMapa, ehMapa, escapeHtml, pegar, temChave, textoDe,
} from './texto.mjs';
import { cssPaleta, gerarPaleta, normalizarCor, TEXTO_CLARO } from './paleta.mjs';
import { calcularFundos } from './tons.mjs';
import { acharIcone, ICONE_RESERVA, iconeDoItem, svg as svgIcone, svgDaDefinicao } from './icones.mjs';
import {
  digitosNacionais, formatarHorarios, formatarRegistro, formatarTelefone, horariosTexto, inicial,
  linhasEndereco, linkTelefone, linkWhatsapp, rotuloRede, urlRede, validarEmail, validarWhatsapp,
} from './dados.mjs';
import { contextoVariaveis, itensLista, limitesLista, substituirVariaveis, textoEfetivo } from './textos.mjs';
import {
  completarDados, especialidade, migrar, nichoDe, REDES, registroCampos, registroListas,
} from './documento.mjs';

// §5.1: o escape padrão do mustache.js também escapa / ` = (o PHP não).
Mustache.escape = escapeHtml;

function temPropriedade(valor, nome) {
  if (valor === null || typeof valor !== 'object') return false;
  if (Array.isArray(valor)) return /^(0|[1-9][0-9]*)$/.test(nome) && Number(nome) < valor.length;
  return Object.prototype.hasOwnProperty.call(valor, nome);
}

/**
 * Busca de nomes como na especificação Mustache (igual ao mustache.php): o primeiro
 * segmento de "a.b.c" é procurado na pilha de contextos e os demais SÓ dentro do valor
 * achado. O mustache.js original volta a procurar nos contextos de cima quando o
 * caminho falha — e aí `{{d.whatsappLink}}` dentro de um item com campo `d` funcionaria
 * na prévia e sairia vazio na publicação.
 */
function buscarComoEspecificacao(nome) {
  const cache = this.cache;
  let valor;
  if (Object.prototype.hasOwnProperty.call(cache, nome)) {
    valor = cache[nome];
  } else {
    const nomes = nome.indexOf('.') > 0 ? nome.split('.') : [nome];
    for (let ctx = this; ctx; ctx = ctx.parent) {
      if (temPropriedade(ctx.view, nomes[0])) {
        valor = ctx.view[nomes[0]];
        break;
      }
    }
    for (let i = 1; i < nomes.length && valor !== undefined; i++) {
      valor = temPropriedade(valor, nomes[i]) ? valor[nomes[i]] : undefined;
    }
    cache[nome] = valor;
  }
  if (typeof valor === 'function') valor = valor.call(this.view);
  return valor;
}
Mustache.Context.prototype.lookup = buscarComoEspecificacao;

/**
 * Parcial só é indentada quando a tag está sozinha na linha (como na especificação e no
 * mustache.php). O mustache.js também indenta parciais no meio da linha, alinhando as
 * linhas seguintes à coluna da tag.
 */
function ajustarParciais(tokens, template) {
  for (const token of tokens) {
    if (token[0] === '>') {
      const inicioLinha = template.lastIndexOf('\n', token[2] - 1) + 1;
      const fimLinha = template.indexOf('\n', token[3]);
      const antes = template.slice(inicioLinha, token[2]);
      const depois = template.slice(token[3], fimLinha === -1 ? template.length : fimLinha);
      if (!/^[ \t]*$/.test(antes) || !/^[ \t]*\r?$/.test(depois)) token[4] = '';
    } else if ((token[0] === '#' || token[0] === '^') && Array.isArray(token[4])) {
      ajustarParciais(token[4], template);
    }
  }
}
const analisarOriginal = Mustache.Writer.prototype.parse;
Mustache.Writer.prototype.parse = function analisar(template, tags) {
  const tokens = analisarOriginal.call(this, template, tags);
  ajustarParciais(tokens, template);
  return tokens;
};

/** Objeto vazio é falso nas seções, como o [] do PHP ({} do JSON vira [] lá). */
function objetoVazio(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0;
}
/**
 * Seções como no mustache.php: objeto vazio é falso e `true` entra na pilha de contextos
 * (no mustache.js original, {{.}} dentro de {{#flag}} mostraria o contexto de fora).
 */
const secaoOriginal = Mustache.Writer.prototype.renderSection;
Mustache.Writer.prototype.renderSection = function secao(token, context, partials, originalTemplate, config) {
  const valor = context.lookup(token[1]);
  if (objetoVazio(valor)) return undefined;
  if (valor === true) return this.renderTokens(token[4], context.push(valor), partials, originalTemplate, config);
  return secaoOriginal.call(this, token, context, partials, originalTemplate, config);
};
const invertidaOriginal = Mustache.Writer.prototype.renderInverted;
Mustache.Writer.prototype.renderInverted = function invertida(token, context, partials, originalTemplate, config) {
  if (objetoVazio(context.lookup(token[1]))) return this.renderTokens(token[4], context, partials, originalTemplate, config);
  return invertidaOriginal.call(this, token, context, partials, originalTemplate, config);
};

/**
 * Renderiza um template com o motor configurado (escape do §5.1, busca de nomes, seções
 * e parciais como no mustache.php). Lança se o template não compilar.
 */
export function renderizarTemplate(template, view, parciais = {}) {
  const p = Object.create(null);
  for (const [nome, t] of Object.entries(comoMapa(parciais))) if (typeof t === 'string') p[nome] = t;
  return Mustache.render(textoDe(template), view ?? {}, p);
}

const MENU_MAXIMO = 5;
const POSICOES = 9;

/** Opções de prepararSite com padrões: { modo, urlMidia, midia, ano }. */
export function normalizarOpcoes(opcoes) {
  const o = comoMapa(opcoes);
  const modo = o.modo === 'publicar' ? 'publicar' : 'editor';
  return {
    modo,
    urlMidia: typeof o.urlMidia === 'string' && o.urlMidia !== ''
      ? o.urlMidia
      : (modo === 'publicar' ? 'img/{id}-{w}.{ext}' : '/api/media/{id}/{w}'),
    midia: comoMapa(o.midia),
    ano: Number.isInteger(o.ano) ? o.ano : null,
  };
}

/** Troca {id} {w} {ext} no modelo de URL (uma passada). */
export function urlMidia(modelo, id, w, ext) {
  const valores = { id: textoDe(id), w: String(w), ext: textoDe(ext) };
  return textoDe(modelo).replace(/\{(id|w|ext)\}/g, (_, k) => valores[k]);
}

function inteiro(v) {
  return typeof v === 'number' && Number.isInteger(v) ? v : 0;
}

function larguras(variantes) {
  const r = [];
  for (const v of comoLista(variantes)) if (inteiro(v) > 0 && !r.includes(v)) r.push(v);
  return r.sort((a, b) => a - b);
}

function maiorAte(lista, limite) {
  let r = null;
  for (const w of lista) if (w <= limite) r = w;
  return r;
}

function midiaDe(op, id) {
  if (typeof id !== 'string' || id === '') return null;
  const m = pegar(op.midia, id);
  return ehMapa(m) ? m : null;
}

function htmlVazio(rotulo, modo) {
  return modo === 'editor'
    ? `<span class="rk-foto__vazio">${escapeHtml(textoDe(rotulo))} · enviar</span>`
    : '<span class="rk-foto__vazio" aria-hidden="true"></span>';
}

/**
 * HTML de uma imagem (§5.5) → { html, vazio }.
 * entrada = { chave, midiaId, rotulo, alt, sizes, lcp }; opcoes = as de prepararSite.
 */
export function imagemHtml(entrada, opcoes) {
  const e = comoMapa(entrada);
  const op = normalizarOpcoes(opcoes);
  const id = textoDe(e.midiaId);
  const m = midiaDe(op, id);
  if (m === null) return { html: htmlVazio(e.rotulo, op.modo), vazio: true };
  const alt = escapeHtml(colapsarEspacos(m.alt) !== '' ? textoDe(m.alt) : textoDe(e.alt));
  const lcp = e.lcp === true;
  const carregamento = `loading="${lcp ? 'eager' : 'lazy'}" decoding="async"${lcp ? ' fetchpriority="high"' : ''}`;
  const largura = inteiro(m.largura);
  const altura = inteiro(m.altura);
  const dimensoes = largura > 0 && altura > 0 ? ` width="${largura}" height="${altura}"` : '';
  if (op.modo === 'editor' && typeof m.local === 'string' && m.local !== '') {
    return { html: `<img src="${escapeHtml(m.local)}"${dimensoes} alt="${alt}" ${carregamento}>`, vazio: false };
  }
  const ext = m.formato === 'svg' ? 'svg' : 'webp';
  const variantes = larguras(m.variantes);
  if (ext === 'svg' || variantes.length === 0) {
    const src = escapeHtml(urlMidia(op.urlMidia, id, 'orig', ext));
    return { html: `<img src="${src}"${dimensoes} alt="${alt}" ${carregamento}>`, vazio: false };
  }
  const maior = variantes[variantes.length - 1];
  const larguraSrc = maiorAte(variantes, 960) ?? variantes[0];
  const src = escapeHtml(urlMidia(op.urlMidia, id, larguraSrc, ext));
  const srcset = escapeHtml(variantes.map((w) => `${urlMidia(op.urlMidia, id, w, ext)} ${w}w`).join(', '));
  const sizes = escapeHtml(textoDe(e.sizes) !== '' ? textoDe(e.sizes) : '100vw');
  const alturaProporcional = largura > 0 ? arred((altura * maior) / largura) : altura;
  return {
    html: `<img src="${src}" srcset="${srcset}" sizes="${sizes}" width="${maior}" height="${alturaProporcional}" alt="${alt}" ${carregamento}>`,
    vazio: false,
  };
}

/** HTML do logo (§5.5) → { html, temLogo }. entrada = { midiaId, nome, inicial }. */
export function logoHtml(entrada, opcoes) {
  const e = comoMapa(entrada);
  const op = normalizarOpcoes(opcoes);
  const id = textoDe(e.midiaId);
  const m = midiaDe(op, id);
  const nome = escapeHtml(textoDe(e.nome));
  if (m === null) {
    return { html: `<span class="rk-logo__ini" aria-hidden="true">${escapeHtml(textoDe(e.inicial))}</span>`, temLogo: false };
  }
  if (op.modo === 'editor' && typeof m.local === 'string' && m.local !== '') {
    return { html: `<img class="rk-logo__img" src="${escapeHtml(m.local)}" alt="${nome}">`, temLogo: true };
  }
  const largura = inteiro(m.largura);
  const altura = inteiro(m.altura);
  const ext = m.formato === 'svg' ? 'svg' : 'webp';
  const variantes = larguras(m.variantes);
  if (ext === 'svg' || variantes.length === 0) {
    const src = escapeHtml(urlMidia(op.urlMidia, id, 'orig', ext));
    return { html: `<img class="rk-logo__img" src="${src}" width="${largura}" height="${altura}" alt="${nome}">`, temLogo: true };
  }
  const w1 = maiorAte(variantes, 320) ?? variantes[0];
  const url1 = urlMidia(op.urlMidia, id, w1, ext);
  let srcset = `${url1} 1x`;
  if (variantes.includes(640) && w1 < 640) srcset += `, ${urlMidia(op.urlMidia, id, 640, ext)} 2x`;
  const h = largura > 0 ? arred((altura * w1) / largura) : altura;
  return {
    html: `<img class="rk-logo__img" src="${escapeHtml(url1)}" srcset="${escapeHtml(srcset)}" width="${w1}" height="${h}" alt="${nome}">`,
    temLogo: true,
  };
}

/** Invólucro da seção (§5.4). */
export function involucro(tipo, opcao, fundo, ancora, indice, html) {
  const t = escapeHtml(tipo);
  return `<div class="rk-sec rk-sec--${t} rk-op--${t}-${escapeHtml(opcao)} rk-bg--${escapeHtml(fundo)}" id="${escapeHtml(ancora)}" data-sec="${indice}">${html}</div>`;
}

/** Botão flutuante de WhatsApp (§5.4). */
export function botaoWhatsapp(link, svg) {
  return `<a class="rk-wa" href="${escapeHtml(link)}" data-ev="whatsapp" data-pos="flutuante" target="_blank" rel="noopener" aria-label="Conversar no WhatsApp">${textoDe(svg)}</a>`;
}

function sizesPara(sizes, chave) {
  const exato = pegar(sizes, chave);
  if (typeof exato === 'string') return exato;
  const partes = chave.split('.');
  for (const [padrao, valor] of Object.entries(sizes)) {
    if (typeof valor !== 'string' || !padrao.includes('*')) continue;
    const pp = padrao.split('.');
    if (pp.length === partes.length && pp.every((p, i) => p === '*' || p === partes[i])) return valor;
  }
  return '100vw';
}

function rotuloImagem(def) {
  const r = colapsarEspacos(comoMapa(def).rotulo);
  return r !== '' ? r : 'Foto';
}

function altCom(base, nome) {
  if (base !== '' && nome !== '') return `${base} — ${nome}`;
  return base !== '' ? base : nome;
}

function ehCampoTexto(def) {
  return def.tipo !== 'imagem' && def.tipo !== 'icone';
}

/** Parte da view que não depende da seção: textos, ícones e listas efetivas. */
function montarContexto(doc, lib, acabamento) {
  const vars = contextoVariaveis(doc, lib);
  const imagens = comoMapa(doc.imagens);
  const iconesDoc = comoMapa(doc.icones);
  const grupos = new Map();
  for (const [chave, def] of Object.entries(registroCampos(lib))) {
    if (chave.includes('*')) continue;
    let valor = null;
    if (def.tipo === 'icone') {
      const manual = pegar(iconesDoc, chave);
      const id = typeof manual === 'string' && acharIcone(lib, manual) !== null ? manual : ICONE_RESERVA;
      valor = { id, svg: svgIcone(lib, id, acabamento) };
    } else if (ehCampoTexto(def)) {
      valor = textoEfetivo(doc, lib, chave, vars);
    }
    if (!grupos.has(def.grupo)) grupos.set(def.grupo, []);
    grupos.get(def.grupo).push({ campo: def.campo, def, chave, valor });
  }
  const listas = new Map();
  for (const [lista, ldef] of Object.entries(registroListas(lib))) {
    const campos = Object.entries(comoMapa(ldef.campos)).map(([campo, def]) => ({ campo, def: comoMapa(def) }));
    const [, max] = limitesLista(lib, lista);
    const ids = itensLista(doc, lib, lista).slice(0, max);
    const itens = ids.map((id, pos) => {
      const textos = {};
      let icone = null;
      for (const { campo, def } of campos) {
        if (def.tipo === 'icone') {
          const iconeId = iconeDoItem(doc, lib, lista, id, pos, vars);
          icone = { id: iconeId, svg: svgIcone(lib, iconeId, acabamento) };
        } else if (ehCampoTexto(def)) {
          textos[campo] = textoEfetivo(doc, lib, `${lista}.${id}.${campo}`, vars);
        }
      }
      const base = [textos.t, textos.n].find((t) => typeof t === 'string' && t !== '') ?? '';
      return { id, textos, icone, altBase: base };
    });
    listas.set(lista, { campos, itens });
  }
  return { vars, imagens, grupos, listas };
}

/** `c` da view, construído POR SEÇÃO (sizes dependem da opção; lcp, da posição). */
function montarC(ctx, opcaoDef, lcp, op) {
  const sizes = comoMapa(opcaoDef.sizes);
  const imagem = (chave, rotulo, altBase) => imagemHtml({
    chave,
    midiaId: pegar(ctx.imagens, chave),
    rotulo,
    alt: altCom(altBase, ctx.vars.nome),
    sizes: sizesPara(sizes, chave),
    lcp,
  }, op);
  const c = {};
  for (const [grupo, campos] of ctx.grupos) {
    const g = {};
    for (const { campo, def, chave, valor } of campos) {
      if (def.tipo === 'imagem') {
        const rotulo = rotuloImagem(def);
        g[campo] = imagem(chave, rotulo, rotulo);
      } else {
        g[campo] = valor;
      }
    }
    c[grupo] = g;
  }
  for (const [lista, info] of ctx.listas) {
    if (!temChave(c, lista)) c[lista] = {};
    const g = c[lista];
    const n = info.itens.length;
    const itens = info.itens.map((it, pos) => {
      const item = {
        id: it.id, k: `${lista}.${it.id}`, i: pos + 1, primeiro: pos === 0, ultimo: pos === n - 1, par: (pos + 1) % 2 === 0,
      };
      for (const { campo, def } of info.campos) {
        if (def.tipo === 'imagem') item[campo] = imagem(`${lista}.${it.id}.${campo}`, rotuloImagem(def), it.altBase);
        else if (def.tipo === 'icone') item[campo] = it.icone;
        else item[campo] = it.textos[campo];
      }
      return item;
    });
    g.itens = itens;
    g.qtd = n;
    g.tem = n > 0;
    for (let p = 1; p <= Math.min(POSICOES, n); p++) g[`p${p}`] = itens[p - 1];
  }
  return c;
}

/** Ícones utilitários no peso do acabamento: { seta: "<svg…>", … } (direto dos utilitários,
 *  mesmo que a lista principal tenha um ícone com o mesmo id). */
function montarUtilitarios(lib, acabamento) {
  const u = {};
  for (const [nome, def] of Object.entries(comoMapa(comoMapa(comoMapa(lib).icones).utilitarios))) {
    u[nome] = svgDaDefinicao(def, acabamento);
  }
  return u;
}

/** `d` da view: dados derivados, já formatados (§5.3). */
export function montarDados(doc, lib, opcoes, contexto, u) {
  const op = normalizarOpcoes(opcoes);
  const vars = contexto ?? contextoVariaveis(doc, lib);
  const utilitarios = comoMapa(u);
  const dados = completarDados(comoMapa(doc).dados);
  const nicho = nichoDe(doc, lib);
  const exemplo = comoMapa(nicho.exemplo);
  const ufDado = colapsarEspacos(dados.uf).toUpperCase();
  const uf = ufDado !== '' ? ufDado : (colapsarEspacos(dados.cidade) === '' ? colapsarEspacos(exemplo.uf).toUpperCase() : '');
  const cidadeUf = vars.cidade !== '' && uf !== '' ? `${vars.cidade} - ${uf}` : vars.cidade + uf;
  const whatsapp = colapsarEspacos(dados.whatsapp) !== '' ? colapsarEspacos(dados.whatsapp) : colapsarEspacos(exemplo.whatsapp);
  const mensagem = substituirVariaveis(textoDe(nicho.mensagemWhatsapp), vars);
  const telefone = colapsarEspacos(dados.telefone);
  const temTelefone = digitosNacionais(telefone).length >= 8;
  const email = colapsarEspacos(dados.email);
  const temEmail = validarEmail(email);
  const enderecoLinhas = linhasEndereco(dados);
  const endereco = enderecoLinhas.join(' - ');
  const consultaMapa = endereco !== '' ? endereco : cidadeUf;
  const horarios = formatarHorarios(dados.horarios);
  const registro = formatarRegistro(dados, especialidade(doc, lib));
  const redes = [];
  for (const rede of REDES) {
    const url = urlRede(rede, dados.redes[rede]);
    if (url !== '') redes.push({ rede, rotulo: rotuloRede(rede), url, svg: textoDe(pegar(utilitarios, rede)) });
  }
  const letra = inicial(vars.nome);
  return {
    nome: vars.nome,
    cidade: vars.cidade,
    uf,
    cidadeUf,
    inicial: letra,
    whatsapp: whatsapp !== '' ? formatarTelefone(whatsapp) : '',
    whatsappLink: linkWhatsapp(whatsapp, mensagem),
    temWhatsapp: validarWhatsapp(whatsapp),
    telefone: temTelefone ? formatarTelefone(telefone) : '',
    telefoneLink: temTelefone ? linkTelefone(telefone) : '',
    temTelefone,
    email: temEmail ? email : '',
    emailLink: temEmail ? `mailto:${email}` : '',
    temEmail,
    endereco,
    enderecoLinhas,
    temEndereco: enderecoLinhas.length > 0,
    mapaLink: consultaMapa !== '' ? `https://www.google.com/maps/search/?api=1&query=${codificarUri(consultaMapa)}` : '',
    mapaEmbed: consultaMapa !== '' ? `https://www.google.com/maps?q=${codificarUri(consultaMapa)}&output=embed` : '',
    horarios,
    horariosTexto: horariosTexto(horarios),
    temHorarios: horarios.length > 0,
    registro,
    temRegistro: registro !== '',
    redes,
    temRedes: redes.length > 0,
    logo: logoHtml({ midiaId: dados.logo, nome: vars.nome, inicial: letra }, op),
    ano: op.ano,
    segmento: vars.segmento,
  };
}

/** Menu (§5.6): seções com `menu`, na ordem da página, rótulo do nicho ?? manifesto, até 5. */
function montarMenu(validas, nicho) {
  const menu = [];
  const doNicho = comoMapa(nicho.menu);
  for (const v of validas) {
    if (menu.length >= MENU_MAXIMO) break;
    const rotuloManifest = v.manifest.menu;
    if (typeof rotuloManifest !== 'string' || rotuloManifest === '') continue;
    const r = pegar(doNicho, v.tipo);
    menu.push({ rotulo: typeof r === 'string' && r !== '' ? r : rotuloManifest, href: `#${v.ancora}` });
  }
  return menu;
}

function ancoraDe(manifest, tipo) {
  if (tipo === 'header') return 'topo';
  const a = manifest.ancora;
  return typeof a === 'string' && /^[a-z][a-z0-9-]*$/.test(a) ? a : tipo;
}

/**
 * Prepara e renderiza o site inteiro.
 * → { html, cssPaleta, classesRaiz, secoes: [{indice, tipo, opcao, fundo, ancora, html}], avisos, alvoPular }
 * Nunca lança por dado faltando: seção/opção/template desconhecidos são pulados com aviso.
 * `secoes[].html` é o invólucro completo; `alvoPular` é a âncora do primeiro conteúdo após o header.
 */
export function prepararSite(docEntrada, lib, opcoes) {
  const avisos = [];
  const avisar = (codigo, mensagem, extra = {}) => avisos.push({ codigo, mensagem, ...extra });
  const op = normalizarOpcoes(opcoes);
  const biblioteca = comoMapa(lib);
  const bruto = comoMapa(docEntrada);
  const doc = migrar(bruto, biblioteca);
  const nicho = nichoDe(doc, biblioteca);
  if (!ehMapa(pegar(comoMapa(biblioteca.nichos), doc.nicho))) {
    avisar('nicho_desconhecido', `Nicho desconhecido: "${doc.nicho}".`);
  }
  const estiloBruto = comoMapa(bruto.estilo);
  const estilo = doc.estilo;
  if (temChave(estiloBruto, 'cor') && normalizarCor(estiloBruto.cor) === null) {
    avisar('cor_invalida', 'A cor do site é inválida; usando a cor padrão.');
  }
  const { acabamento, fonte } = estilo;
  const paleta = gerarPaleta(estilo.cor);

  const validas = [];
  const vistos = new Set();
  doc.secoes.forEach((s, indice) => {
    const sec = pegar(comoMapa(biblioteca.secoes), s.tipo);
    if (!ehMapa(sec)) {
      avisar('secao_desconhecida', `Seção desconhecida: "${s.tipo}".`, { indice });
      return;
    }
    const manifest = comoMapa(sec.manifest);
    const opcaoDef = comoLista(manifest.opcoes).find((o) => ehMapa(o) && o.id === s.opcao);
    if (!opcaoDef) {
      avisar('opcao_desconhecida', `Opção desconhecida: "${s.tipo}/${s.opcao}".`, { indice });
      return;
    }
    const template = pegar(comoMapa(sec.templates), s.opcao);
    if (typeof template !== 'string') {
      avisar('template_ausente', `Template ausente: "${s.tipo}/${s.opcao}".`, { indice });
      return;
    }
    if (vistos.has(s.tipo)) {
      avisar('secao_duplicada', `A seção "${s.tipo}" aparece mais de uma vez; só a primeira é mostrada.`, { indice });
      return;
    }
    vistos.add(s.tipo);
    validas.push({ indice, tipo: s.tipo, opcao: s.opcao, manifest, opcaoDef, template, ancora: ancoraDe(manifest, s.tipo) });
  });

  const fundos = calcularFundos(validas.map((v) => v.opcaoDef.tom));
  const contexto = montarContexto(doc, biblioteca, acabamento);
  const u = montarUtilitarios(biblioteca, acabamento);
  const d = montarDados(doc, biblioteca, op, contexto.vars, u);
  const e = {
    acabamento, fonte, classico: acabamento === 'classico', moderno: acabamento === 'moderno', direto: acabamento === 'direto',
    elegante: acabamento === 'elegante', suave: acabamento === 'suave', impacto: acabamento === 'impacto',
  };
  const menu = montarMenu(validas, nicho);
  const modo = { editor: op.modo === 'editor', publicar: op.modo === 'publicar' };
  const parciais = comoMapa(biblioteca.parciais);
  const posicaoLcp = validas.findIndex((v) => v.tipo !== 'header');
  const corClara = paleta.tokens['--on-p'] === TEXTO_CLARO;

  const secoes = [];
  validas.forEach((v, pos) => {
    const fundo = fundos[pos];
    const c = montarC(contexto, v.opcaoDef, pos === posicaoLcp, op);
    // `dados` e `conteudo` são apelidos de `d` e `c` para usar dentro de laços de itens
    // cujos campos se chamam d (serv, dif, passos) ou c (equipe, dep) e escondem a raiz.
    const view = {
      c,
      d,
      s: { tipo: v.tipo, opcao: v.opcao, fundo, escuro: fundo === 'escuro' || (fundo === 'cor' && corClara), indice: v.indice },
      e,
      u,
      menu,
      modo,
      dados: d,
      conteudo: c,
    };
    let interno;
    try {
      interno = renderizarTemplate(v.template, view, parciais);
    } catch (erro) {
      avisar('erro_template', `Erro no template "${v.tipo}/${v.opcao}".`, { indice: v.indice, detalhe: String(erro?.message ?? erro) });
      return;
    }
    secoes.push({
      indice: v.indice, tipo: v.tipo, opcao: v.opcao, fundo, ancora: v.ancora,
      html: involucro(v.tipo, v.opcao, fundo, v.ancora, v.indice, interno),
    });
  });

  const partes = secoes.map((s) => s.html);
  if (estilo.whatsappFlutuante && d.temWhatsapp) partes.push(botaoWhatsapp(d.whatsappLink, pegar(u, 'whatsapp')));
  const alvo = secoes.find((s) => s.tipo !== 'header');
  return {
    html: partes.join('\n'),
    cssPaleta: cssPaleta(paleta.tokens),
    classesRaiz: `rk k-${acabamento} f-${fonte}`,
    secoes,
    avisos,
    alvoPular: alvo ? alvo.ancora : '',
  };
}
