// Lógica pura do editor (sem DOM): seções, listas, textos, ícones, dados, SEO e publicação.
//
// Todas as funções que "alteram" o documento devolvem um documento NOVO e não tocam o
// recebido; camadas que não mudam continuam sendo o mesmo objeto (o EstadoEditor aproveita
// isso para o histórico ficar leve). Quando a operação não é permitida, devolvem o próprio
// documento recebido (mesma referência), então `novo === doc` significa "nada mudou".
//
// Importável no Node (testes em tests/js/tela-*.test.mjs).

import { comoLista, comoMapa, colapsarEspacos, ehMapa, normalizar, pegar, textoDe } from '../compartilhado/texto.mjs';
import {
  aplicarModelo, especialidade, nichoDe, registroCampos, registroListas, definicaoCampo, DIAS, extrasDeIcones, idIconeExtraValido,
} from '../compartilhado/documento.mjs';
import {
  aplicarEdicaoTexto, contextoVariaveis, itensLista, limitesLista, substituirVariaveis, textoEfetivo, textoPadrao,
  adicionarItem as adicionarItemCompartilhado, removerItem as removerItemCompartilhado,
  moverItem as moverItemCompartilhado,
} from '../compartilhado/textos.mjs';
import { soDigitos, urlRede, validarEmail } from '../compartilhado/dados.mjs';

/* ================================================================== biblioteca */

/** Manifesto do tipo de seção ({} se não existir). */
export function manifestoDe(lib, tipo) {
  return comoMapa(comoMapa(pegar(comoMapa(comoMapa(lib).secoes), textoDe(tipo))).manifest);
}

/** "inicio" (cabeçalho), "fim" (rodapé) ou null. */
export function fixaDe(lib, tipo) {
  const f = manifestoDe(lib, tipo).fixa;
  if (f === 'inicio' || f === 'fim') return f;
  if (tipo === 'header') return 'inicio';
  if (tipo === 'rodape') return 'fim';
  return null;
}

/** Opções do tipo, na ordem do manifesto: [{id, nome, descricao, tom}]. */
export function opcoesDe(lib, tipo) {
  return comoLista(manifestoDe(lib, tipo).opcoes).filter((o) => ehMapa(o) && typeof o.id === 'string' && o.id !== '');
}

export function nomeSecao(lib, tipo) {
  const n = manifestoDe(lib, tipo).nome;
  return typeof n === 'string' && n !== '' ? n : textoDe(tipo);
}

export function nomeOpcao(lib, tipo, opcao) {
  const o = opcoesDe(lib, tipo).find((x) => x.id === opcao);
  return o && typeof o.nome === 'string' && o.nome !== '' ? o.nome : textoDe(opcao);
}

/** Tipos da biblioteca na ordem sugerida (campo `ordem` do manifesto). */
export function tiposDaBiblioteca(lib) {
  const secoes = comoMapa(comoMapa(lib).secoes);
  return Object.keys(secoes).sort((a, b) => {
    const oa = Number(manifestoDe(lib, a).ordem ?? 99);
    const ob = Number(manifestoDe(lib, b).ordem ?? 99);
    return oa - ob || a.localeCompare(b);
  });
}

/* ================================================================== seções */

function secoesDe(doc) {
  return comoLista(comoMapa(doc).secoes).filter(ehMapa);
}

function comSecoes(doc, secoes) {
  return { ...doc, secoes };
}

/**
 * Faixa de posições que as seções não fixas podem ocupar: depois das fixas do início
 * (cabeçalho) e antes das fixas do fim (rodapé). → { min, max } (índices inclusivos).
 */
export function faixaMovel(doc, lib) {
  const secoes = secoesDe(doc);
  let min = 0;
  while (min < secoes.length && fixaDe(lib, secoes[min].tipo) === 'inicio') min++;
  let max = secoes.length - 1;
  while (max >= min && fixaDe(lib, secoes[max].tipo) === 'fim') max--;
  return { min, max };
}

/** A seção pode ir `delta` posições (−1 sobe, +1 desce)? Fixas nunca se movem. */
export function podeMoverSecao(doc, lib, indice, delta) {
  const secoes = secoesDe(doc);
  if (indice < 0 || indice >= secoes.length || fixaDe(lib, secoes[indice].tipo) !== null) return false;
  const { min, max } = faixaMovel(doc, lib);
  const para = indice + Math.trunc(delta);
  return para !== indice && para >= min && para <= max;
}

/**
 * Move a seção `de` para a posição final `para` (presa à faixa móvel).
 * Cabeçalho e rodapé não saem do lugar e nada passa para antes do cabeçalho ou depois do rodapé.
 */
export function moverSecao(doc, lib, de, para) {
  const secoes = secoesDe(doc);
  if (!Number.isInteger(de) || de < 0 || de >= secoes.length) return doc;
  if (fixaDe(lib, secoes[de].tipo) !== null) return doc;
  const { min, max } = faixaMovel(doc, lib);
  const destino = Math.min(max, Math.max(min, Math.trunc(Number(para) || 0)));
  if (destino === de) return doc;
  const novas = secoes.slice();
  const [s] = novas.splice(de, 1);
  novas.splice(destino, 0, s);
  return comSecoes(doc, novas);
}

/** Atalho de moverSecao para ↑ ↓. */
export function moverSecaoDelta(doc, lib, indice, delta) {
  if (!podeMoverSecao(doc, lib, indice, delta)) return doc;
  return moverSecao(doc, lib, indice, indice + Math.trunc(delta));
}

/**
 * Posição final de um item arrastado da posição `de` e solto antes (`antes = true`) ou
 * depois do item da posição `alvo` (índices da lista ANTES de mover).
 */
export function posicaoSoltura(de, alvo, antes) {
  let p = antes ? alvo : alvo + 1;
  if (de < p) p -= 1;
  return p;
}

/** Tipos da biblioteca que ainda não estão no site (cada tipo no máximo uma vez), na ordem sugerida. */
export function tiposAusentes(doc, lib) {
  const presentes = new Set(secoesDe(doc).map((s) => s.tipo));
  return tiposDaBiblioteca(lib).filter((t) => !presentes.has(t) && opcoesDe(lib, t).length > 0);
}

/**
 * Onde entra uma seção nova: logo depois da selecionada (se houver e não for o rodapé),
 * senão antes do rodapé. Nunca antes do cabeçalho nem depois do rodapé.
 */
export function posicaoNovaSecao(doc, lib, selecionada = null) {
  const secoes = secoesDe(doc);
  const { min, max } = faixaMovel(doc, lib);
  const fimDaFaixa = max + 1; // antes das fixas do fim
  if (Number.isInteger(selecionada) && selecionada >= 0 && selecionada < secoes.length) {
    return Math.min(fimDaFaixa, Math.max(min, selecionada + 1));
  }
  return Math.max(min, fimDaFaixa);
}

/**
 * Adiciona a seção `tipo` (opção `opcao` ou a primeira). Tipo repetido ou desconhecido → nada.
 * Cabeçalho entra sempre no início e rodapé sempre no fim. → { doc, indice } (indice null = nada).
 */
export function adicionarSecao(doc, lib, tipo, opcao = null, selecionada = null) {
  const opcoes = opcoesDe(lib, tipo);
  if (opcoes.length === 0) return { doc, indice: null };
  const secoes = secoesDe(doc);
  if (secoes.some((s) => s.tipo === tipo)) return { doc, indice: null };
  const escolhida = opcoes.some((o) => o.id === opcao) ? opcao : opcoes[0].id;
  const fixa = fixaDe(lib, tipo);
  let pos;
  if (fixa === 'inicio') pos = 0;
  else if (fixa === 'fim') pos = secoes.length;
  else pos = posicaoNovaSecao(doc, lib, selecionada);
  const novas = secoes.slice();
  novas.splice(pos, 0, { tipo, opcao: escolhida });
  return { doc: comSecoes(doc, novas), indice: pos };
}

/**
 * A seção é uma repetição de um tipo que já apareceu antes no documento? (o site mostra só a
 * primeira; o validador bloqueia a publicação até a repetida sair).
 */
export function ehSecaoRepetida(doc, indice) {
  const secoes = secoesDe(doc);
  const s = secoes[indice];
  return Boolean(s) && secoes.slice(0, indice).some((o) => o.tipo === s.tipo);
}

/**
 * Cabeçalho e rodapé não podem ser removidos (o site sempre começa e termina com eles) —
 * a não ser uma cópia repetida deles, que precisa sair para o site poder ser publicado.
 */
export function podeRemoverSecao(doc, lib, indice) {
  const s = secoesDe(doc)[indice];
  return Boolean(s) && (fixaDe(lib, s.tipo) === null || ehSecaoRepetida(doc, indice));
}

/** Remove a seção (os textos ficam guardados no documento: voltando, eles voltam). */
export function removerSecao(doc, lib, indice) {
  if (!podeRemoverSecao(doc, lib, indice)) return doc;
  const novas = secoesDe(doc).slice();
  novas.splice(indice, 1);
  return comSecoes(doc, novas);
}

/** Troca a opção (visual) da seção. Opção inexistente → nada. */
export function trocarOpcao(doc, lib, indice, opcao) {
  const secoes = secoesDe(doc);
  const s = secoes[indice];
  if (!s || s.opcao === opcao || !opcoesDe(lib, s.tipo).some((o) => o.id === opcao)) return doc;
  const novas = secoes.slice();
  novas[indice] = { ...s, opcao };
  return comSecoes(doc, novas);
}

/** Opção vizinha (circular) para as setas ‹ ›. → { id, posicao (1…n), total } */
export function opcaoVizinha(lib, tipo, atual, delta) {
  const opcoes = opcoesDe(lib, tipo);
  if (opcoes.length === 0) return null;
  const i = Math.max(0, opcoes.findIndex((o) => o.id === atual));
  const n = opcoes.length;
  const j = (((i + Math.trunc(delta)) % n) + n) % n;
  return { id: opcoes[j].id, posicao: j + 1, total: n };
}

/** Posição da opção atual: { posicao (1…n), total }. */
export function posicaoOpcao(lib, tipo, atual) {
  const opcoes = opcoesDe(lib, tipo);
  const i = opcoes.findIndex((o) => o.id === atual);
  return { posicao: i < 0 ? 1 : i + 1, total: opcoes.length };
}

/** Documento com uma única seção (miniaturas reais das galerias). */
export function docSoComSecao(doc, tipo, opcao) {
  return { ...doc, secoes: [{ tipo, opcao }] };
}

/** Troca de modelo (Documento.aplicarModelo) — passo desfazível no editor. */
export function trocarModelo(doc, lib, modelo) {
  if (comoMapa(doc).modelo === modelo) return doc;
  return aplicarModelo(doc, lib, modelo);
}

/* ================================================================== listas variáveis */

/** Definição da lista ({repete, rotuloItem, campos, dono}) ou null. */
export function definicaoLista(lib, lista) {
  const def = pegar(registroListas(lib), textoDe(lista));
  return ehMapa(def) ? def : null;
}

/** Rótulo de um item ("serviço"), para "+ Adicionar serviço". */
export function rotuloItem(lib, lista) {
  const r = comoMapa(definicaoLista(lib, lista)).rotuloItem;
  return typeof r === 'string' && r !== '' ? r : 'item';
}

/** A lista tem quantidade variável (mín < máx)? */
export function listaVariavel(lib, lista) {
  const [min, max] = limitesLista(lib, lista);
  return max > min;
}

/** Quantos itens a página mostra (a lista efetiva cortada no máximo). */
export function idsVisiveis(doc, lib, lista) {
  const [, max] = limitesLista(lib, lista);
  return itensLista(doc, lib, lista).slice(0, max);
}

export function podeAdicionarItem(doc, lib, lista) {
  const [, max] = limitesLista(lib, lista);
  return itensLista(doc, lib, lista).length < max;
}

export function podeRemoverItem(doc, lib, lista) {
  const [min] = limitesLista(lib, lista);
  return itensLista(doc, lib, lista).length > min;
}

/** + item novo (id "n…") depois de `aposId` (ou no fim). → { doc, id } (id null = no máximo). */
export function adicionarItem(doc, lib, lista, aposId = null, id = null) {
  if (!podeAdicionarItem(doc, lib, lista)) return { doc, id: null };
  return adicionarItemCompartilhado(doc, lib, lista, aposId, id);
}

/** − item (respeita o mínimo; os outros itens não "ressuscitam" [M2]). */
export function removerItem(doc, lib, lista, id) {
  if (!podeRemoverItem(doc, lib, lista) || !itensLista(doc, lib, lista).includes(id)) return doc;
  return removerItemCompartilhado(doc, lib, lista, id);
}

export function moverItem(doc, lib, lista, id, delta) {
  return moverItemCompartilhado(doc, lib, lista, id, delta);
}

/* ================================================================== textos */

/** Quantidade de caracteres como o usuário conta (pontos de código, não unidades UTF-16). */
export function contarCaracteres(s) {
  return [...textoDe(s)].length;
}

/** Corta em `max` caracteres (pontos de código). → { texto, cortado } */
export function cortarNoLimite(s, max) {
  const chars = [...textoDe(s)];
  if (!(max > 0) || chars.length <= max) return { texto: chars.join(''), cortado: false };
  return { texto: chars.slice(0, max).join(''), cortado: true };
}

/**
 * Texto de um campo do site: uma linha só (quebras e tabulações viram espaço — Enter
 * encerra a edição e campos com parágrafos ficam para a fase 2). Espaços nas pontas ficam
 * (o usuário pode estar digitando); `aparar` é feito ao confirmar.
 */
export function linhaUnica(s) {
  return textoDe(s).replace(/\r\n?|\n|\t|\u2028|\u2029/g, ' ');
}

/**
 * Resultado de inserir `inserido` no lugar da seleção [inicio, fim) de `atual` respeitando
 * `max` (0 = sem limite). Posições em unidades UTF-16 (as do DOM).
 * → { texto, cursor, cortado, inserido (o que de fato entrou) }
 */
export function inserirNoLimite(atual, inicio, fim, inserido, max = 0) {
  const texto = textoDe(atual);
  const a = Math.max(0, Math.min(texto.length, Math.min(inicio, fim)));
  const b = Math.max(a, Math.min(texto.length, Math.max(inicio, fim)));
  const antes = texto.slice(0, a);
  const depois = texto.slice(b);
  let novo = linhaUnica(inserido);
  let cortado = false;
  if (max > 0) {
    const sobra = Math.max(0, max - contarCaracteres(antes) - contarCaracteres(depois));
    if (sobra === 0) {
      cortado = novo !== '';
      novo = '';
    } else {
      const c = cortarNoLimite(novo, sobra);
      novo = c.texto;
      cortado = c.cortado;
    }
  }
  return { texto: antes + novo + depois, cursor: a + novo.length, cortado, inserido: novo };
}

/** Contador do campo: aparece a partir de 80% do limite; `limite` quando chega ao máximo. */
export function estadoContador(n, max) {
  if (!(max > 0)) return { mostrar: false, limite: false, texto: '' };
  return { mostrar: n >= Math.ceil(max * 0.8), limite: n >= max, texto: `${n}/${max}` };
}

const cacheRegistro = new WeakMap();

/** registroCampos(lib) calculado uma vez por biblioteca. */
export function registroDe(lib) {
  if (!lib || typeof lib !== 'object') return registroCampos(lib);
  let r = cacheRegistro.get(lib);
  if (!r) {
    r = registroCampos(lib);
    cacheRegistro.set(lib, r);
  }
  return r;
}

/** Definição do campo de uma chave concreta ("serv.nk3f.t") ou null. */
export function definicaoDaChave(lib, chave) {
  return definicaoCampo(registroDe(lib), chave);
}

/** Limite de caracteres da chave (0 = sem limite conhecido). */
export function limiteDaChave(lib, chave) {
  const max = Number(comoMapa(definicaoDaChave(lib, chave)).max);
  return Number.isInteger(max) && max > 0 ? max : 0;
}

/**
 * Texto durante a digitação (agrupado num passo só): o que está no campo vai para
 * textos[chave] (retokenizado [M4]); campo vazio fica vazio por enquanto (a restauração do
 * padrão é um passo separado, para "Desfazer" poder deixá-lo vazio).
 */
export function textoDigitado(doc, lib, chave, texto) {
  const linha = linhaUnica(texto);
  if (colapsarEspacos(linha) === '') {
    return { ...doc, textos: { ...comoMapa(doc.textos), [chave]: '' } };
  }
  return aplicarEdicaoTexto(doc, lib, chave, linha).doc;
}

/**
 * Confirmação da edição (Enter, Esc ou saída do campo): apara, retokeniza e remove a chave
 * se ficou igual ao padrão; texto apagado volta ao padrão (`restaurado`).
 * → { doc, restaurado, texto (o que o site passa a mostrar) }
 */
export function confirmarTexto(doc, lib, chave, texto) {
  const r = aplicarEdicaoTexto(doc, lib, chave, linhaUnica(texto));
  return { doc: r.doc, restaurado: r.restaurado, texto: textoEfetivo(r.doc, lib, chave) };
}

/** Texto que o site mostra para a chave (com as variáveis trocadas). */
export function textoMostrado(doc, lib, chave) {
  return textoEfetivo(doc, lib, chave);
}

/** Texto padrão (nicho/comum) com as variáveis trocadas. */
export function textoPadraoMostrado(doc, lib, chave) {
  return substituirVariaveis(textoPadrao(doc, lib, chave), contextoVariaveis(doc, lib));
}

/* ================================================================== ícones */

const CATEGORIA_DO_NICHO = { clinicas: 'saude', advocacia: 'juridico', financas: 'financas', empresas: 'empresas' };

/** Categoria de ícones do negócio (especialidade.iconesCategoria → nicho) ou "geral". */
export function categoriaIcones(doc, lib) {
  const esp = comoMapa(especialidade(doc, lib));
  if (typeof esp.iconesCategoria === 'string' && esp.iconesCategoria !== '') return esp.iconesCategoria;
  const nicho = comoMapa(doc).nicho;
  return CATEGORIA_DO_NICHO[nicho] ?? 'geral';
}

/**
 * Ícones para o seletor (PDF §8.3): os da categoria do negócio primeiro, depois os gerais,
 * depois os demais (cada grupo na ordem do arquivo). `busca` filtra por nome, palavras-chave
 * e id (sem acento, sem diferenciar maiúsculas; cada palavra digitada precisa ser o começo
 * de uma palavra do ícone).
 */
export function listarIcones(lib, { categoria = 'geral', busca = '' } = {}) {
  const todos = comoLista(comoMapa(comoMapa(lib).icones).icones).filter((i) => ehMapa(i) && typeof i.id === 'string');
  const termos = normalizar(busca).split(' ').filter(Boolean);
  // Cada termo precisa ser o começo de alguma palavra ("dente" acha "dente", não "acidente").
  const casa = (i) => {
    if (termos.length === 0) return true;
    const palavras = [i.id, i.nome, ...comoLista(i.palavras)].map((p) => normalizar(p)).join(' ').split(/[^a-z0-9]+/);
    return termos.every((t) => palavras.some((p) => p.startsWith(t)));
  };
  const peso = (i) => (i.categoria === categoria ? 0 : i.categoria === 'geral' ? 1 : 2);
  return todos
    .map((i, pos) => ({ i, pos }))
    .filter(({ i }) => casa(i))
    .sort((a, b) => peso(a.i) - peso(b.i) || a.pos - b.pos)
    .map(({ i }) => i);
}

/**
 * Grava (ou, com null, remove → "Automático") a escolha manual do ícone. `extra` = ícone do
 * Iconify ({ nome, svg }) que vai junto para doc.iconesExtras; os que nenhum item usa mais saem.
 */
export function definirIcone(doc, chave, iconeId, extra = null) {
  const icones = { ...comoMapa(comoMapa(doc).icones) };
  const extras = { ...comoMapa(comoMapa(doc).iconesExtras) };
  if (iconeId === null || iconeId === undefined || iconeId === '') {
    if (!(chave in icones)) return doc;
    delete icones[chave];
  } else {
    const comExtra = ehMapa(extra) && idIconeExtraValido(iconeId);
    if (icones[chave] === iconeId && (!comExtra || iconeId in extras)) return doc;
    icones[chave] = iconeId;
    if (comExtra) extras[iconeId] = { nome: typeof extra.nome === 'string' ? extra.nome : iconeId, svg: { ...comoMapa(extra.svg) } };
  }
  const novo = { ...doc, icones };
  if (Object.keys(extras).length > 0 || 'iconesExtras' in comoMapa(doc)) novo.iconesExtras = extrasDeIcones(extras, icones);
  return novo;
}

/* ================================================================== imagens */

/** Liga (ou, com null, desliga) a mídia à chave de imagem. */
export function definirImagem(doc, chave, midiaId) {
  const imagens = { ...comoMapa(comoMapa(doc).imagens) };
  if (midiaId === null || midiaId === undefined || midiaId === '') {
    if (!(chave in imagens)) return doc;
    delete imagens[chave];
  } else {
    if (imagens[chave] === midiaId) return doc;
    imagens[chave] = midiaId;
  }
  return { ...doc, imagens };
}

/** Tamanho final de uma foto reduzida para caber em `lado` px no lado maior (nunca amplia). */
export function dimensoesReduzidas(largura, altura, lado = 2400) {
  const w = Math.max(1, Math.round(Number(largura) || 1));
  const h = Math.max(1, Math.round(Number(altura) || 1));
  const escala = Math.min(1, lado / Math.max(w, h));
  return { largura: Math.max(1, Math.round(w * escala)), altura: Math.max(1, Math.round(h * escala)) };
}

const EXT_FOTO = /\.(jpe?g|png|webp|gif|bmp|avif)$/i;
const EXT_HEIC = /\.(heic|heif)$/i;

/**
 * Problema com o arquivo de foto (mensagem pronta) ou null.
 * HEIC/HEIF (fotos de iPhone) não abrem no navegador: pede JPG/PNG com o caminho para converter.
 */
export function problemaArquivoFoto(arquivo, limiteMb = 15) {
  if (!arquivo) return 'Escolha um arquivo de imagem.';
  const nome = textoDe(arquivo.name);
  const tipo = textoDe(arquivo.type).toLowerCase();
  if (EXT_HEIC.test(nome) || tipo === 'image/heic' || tipo === 'image/heif') {
    return 'Fotos HEIC (padrão do iPhone) ainda não são aceitas. No iPhone, use Ajustes › Câmera › Formatos › "Mais compatível", ou envie a foto em JPG ou PNG.';
  }
  if (!(tipo.startsWith('image/') || EXT_FOTO.test(nome))) return 'Este arquivo não é uma imagem. Use uma foto em JPG, PNG ou WebP.';
  if (tipo === 'image/svg+xml') return 'Para fotos, use JPG, PNG ou WebP (SVG só no logo).';
  if ((arquivo.size ?? 0) === 0) return 'O arquivo está vazio.';
  const limite = limiteMb * 1024 * 1024 * 4; // antes de reduzir no navegador aceitamos fotos grandes
  if ((arquivo.size ?? 0) > limite) return `A foto é grande demais (máximo ${limiteMb * 4} MB).`;
  return null;
}

/* ================================================================== dados */

/** Máscara progressiva do CEP: "13201-000". */
export function mascaraCep(v) {
  const d = soDigitos(v).slice(0, 8);
  return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
}

export function cepCompleto(v) {
  return soDigitos(v).length === 8;
}

/** Resposta do ViaCEP → { logradouro, bairro, cidade, uf } ou null (CEP inexistente/resposta estranha). */
export function enderecoDoViaCep(resposta) {
  const r = comoMapa(resposta);
  if (r.erro === true || r.erro === 'true') return null;
  const uf = textoDe(r.uf).toUpperCase();
  const cidade = colapsarEspacos(r.localidade);
  if (cidade === '' || !/^[A-Z]{2}$/.test(uf)) return null;
  return { logradouro: colapsarEspacos(r.logradouro), bairro: colapsarEspacos(r.bairro), cidade, uf };
}

/** Copia o horário de segunda para terça a sexta. */
export function copiarSegundaParaUteis(horarios) {
  const h = { ...comoMapa(horarios) };
  const seg = Object.prototype.hasOwnProperty.call(h, 'seg') ? h.seg : null;
  for (const dia of ['ter', 'qua', 'qui', 'sex']) h[dia] = Array.isArray(seg) ? seg.slice() : null;
  if (!Object.prototype.hasOwnProperty.call(h, 'seg')) h.seg = null;
  return ordenarHorarios(h);
}

/** Horários na ordem seg…dom (só os dias presentes). */
export function ordenarHorarios(h) {
  const r = {};
  for (const dia of DIAS) if (Object.prototype.hasOwnProperty.call(h, dia)) r[dia] = h[dia];
  return r;
}

const RE_HORA = /^([01]\d|2[0-3]):[0-5]\d$/;

/** Problema num par abre/fecha (mensagem) ou null. */
export function problemaHorario(par) {
  if (par === null) return null;
  if (!Array.isArray(par) || par.length !== 2) return 'Informe a hora de abrir e de fechar.';
  const [abre, fecha] = par;
  if (!RE_HORA.test(textoDe(abre)) || !RE_HORA.test(textoDe(fecha))) return 'Use o formato 08:00.';
  if (fecha <= abre) return 'O horário de fechar precisa ser depois do de abrir.';
  return null;
}

/** Valor de rede social salvo: vazio, ou o endereço https que o site vai usar. */
export function normalizarRede(rede, valor) {
  const v = colapsarEspacos(valor);
  if (v === '') return '';
  return urlRede(rede, v) || v;
}

/** Problema com a rede social (mensagem) ou null. */
export function problemaRede(rede, valor) {
  const v = colapsarEspacos(valor);
  if (v === '') return null;
  if (/^http:\/\//i.test(v)) return null; // vira https ao salvar
  return urlRede(rede, v) === '' ? 'Use o endereço completo do perfil, começando com https://' : null;
}

export function problemaEmail(valor) {
  const v = colapsarEspacos(valor);
  if (v === '') return null;
  return validarEmail(v) ? null : 'Confira o e-mail (ex.: contato@empresa.com.br).';
}

export function problemaTelefone(valor) {
  const d = soDigitos(valor);
  if (d.length === 0) return null;
  return d.length >= 10 ? null : 'Digite o telefone completo com DDD.';
}

/* ================================================================== rastreamento e SEO */

const RE_RASTREAMENTO = {
  gtm: /^GTM-[A-Z0-9]{4,12}$/,
  ga4: /^G-[A-Z0-9]{4,16}$/,
  metaPixel: /^[0-9]{6,20}$/,
};
const MENSAGENS_RASTREAMENTO = {
  gtm: 'O ID do Google Tag Manager tem o formato GTM-XXXXXXX.',
  ga4: 'O ID do Google Analytics 4 tem o formato G-XXXXXXXXXX.',
  metaPixel: 'O ID do Meta Pixel tem só números (ex.: 123456789012345).',
};

/** Como o servidor lê o ID: sem espaços nas pontas e em maiúsculas. */
export function normalizarRastreamento(valor) {
  return textoDe(valor).trim().toUpperCase();
}

/** Problema com o ID de rastreamento (mesma regra do validador do servidor) ou null. */
export function problemaRastreamento(campo, valor) {
  const v = normalizarRastreamento(valor);
  if (v === '' || !RE_RASTREAMENTO[campo]) return null;
  return RE_RASTREAMENTO[campo].test(v) ? null : MENSAGENS_RASTREAMENTO[campo];
}

export const MAX_SEO_TITULO = 60;
export const MAX_SEO_DESCRICAO = 155;

/** Corta sem quebrar palavra (com "…"), igual ao Seo::cortar do servidor. */
export function cortarSemQuebrar(texto, max) {
  const t = colapsarEspacos(texto);
  const chars = [...t];
  if (chars.length <= max) return t;
  let corte = chars.slice(0, max).join('');
  const espaco = corte.lastIndexOf(' ');
  if (espaco !== -1 && [...corte.slice(0, espaco)].length > max * 0.6) corte = corte.slice(0, espaco);
  return corte.replace(/[ \t,;:.\-–—]+$/u, '') + '…';
}

/** Título do Google que o site usa sem SEO próprio: "{nome} · {segmento} em {cidade}". */
export function tituloSeoPadrao(doc, lib) {
  const v = contextoVariaveis(doc, lib);
  const segmento = colapsarEspacos(v.segmento);
  const cidade = v.cidade;
  let complemento = '';
  if (segmento !== '' && cidade !== '') complemento = `${segmento} em ${cidade}`;
  else if (segmento !== '') complemento = segmento;
  else if (cidade !== '') complemento = `em ${cidade}`;
  if (complemento === '') return v.nome;
  return segmento === '' ? `${v.nome} ${complemento}` : `${v.nome} · ${complemento}`;
}

/** Descrição do Google sem SEO próprio: texto do destaque cortado em ~155 caracteres. */
export function descricaoSeoPadrao(doc, lib) {
  let texto = colapsarEspacos(textoEfetivo(doc, lib, 'hero.texto'));
  if (texto === '') texto = colapsarEspacos(textoEfetivo(doc, lib, 'rodape.sobre'));
  if (texto === '') texto = `${tituloSeoPadrao(doc, lib)}. Fale com a gente pelo WhatsApp.`;
  return cortarSemQuebrar(texto, MAX_SEO_DESCRICAO);
}

/** Título e descrição efetivos (os próprios, com as variáveis trocadas, ou os padrões). */
export function seoEfetivo(doc, lib) {
  const seo = comoMapa(comoMapa(doc).seo);
  const vars = contextoVariaveis(doc, lib);
  const titulo = typeof seo.titulo === 'string' && colapsarEspacos(seo.titulo) !== ''
    ? colapsarEspacos(substituirVariaveis(seo.titulo, vars)) : tituloSeoPadrao(doc, lib);
  const descricao = typeof seo.descricao === 'string' && colapsarEspacos(seo.descricao) !== ''
    ? cortarSemQuebrar(substituirVariaveis(seo.descricao, vars), 300) : descricaoSeoPadrao(doc, lib);
  return { titulo, descricao };
}

/* ================================================================== publicação */

export const GRUPOS_NUNCA_CONFIRMAVEIS = ['dep'];

const NOMES_ALEGACAO = {
  dep: 'Depoimentos',
  num: 'Números',
  aval: 'Nota do Google',
  cli: 'Clientes',
};

export function nomeAlegacao(grupo) {
  return NOMES_ALEGACAO[grupo] ?? grupo;
}

const CONFIRMACOES = {
  num: 'Confirmo que os números são verdadeiros',
  aval: 'Confirmo que a nota do Google é verdadeira',
  cli: 'Confirmo que os clientes citados são reais e autorizaram',
};

/** Texto da caixa de confirmação de uma alegação. */
export function textoConfirmacao(grupo) {
  return CONFIRMACOES[grupo] ?? `Confirmo que as informações de ${nomeAlegacao(grupo).toLowerCase()} são verdadeiras`;
}

/**
 * Organiza a resposta do POST /validar para o checklist:
 *   bloqueios   erros que impedem publicar (inclui depoimentos de exemplo)
 *   alegacoes   alegações confirmáveis ("Confirmo que é verdade")
 *   avisos      não impedem
 *   textos      textos padrão alterados desde a última publicação (antes/depois)
 */
export function organizarValidacao(resposta) {
  const r = comoMapa(resposta);
  const bloqueios = [];
  const alegacoes = [];
  for (const e of comoLista(r.erros).filter(ehMapa)) {
    if (e.codigo === 'alegacao_padrao' && e.confirmavel === true && !GRUPOS_NUNCA_CONFIRMAVEIS.includes(e.grupo)) {
      alegacoes.push(e);
    } else {
      bloqueios.push(e);
    }
  }
  return {
    bloqueios,
    alegacoes,
    avisos: comoLista(r.avisos).filter(ehMapa),
    textos: comoLista(r.textosPadraoAlterados).filter(ehMapa),
  };
}

/** Pode publicar com as alegações marcadas em `marcados` (Set/lista de grupos)? */
export function podePublicar(organizado, marcados) {
  const m = new Set(marcados);
  return organizado.bloqueios.length === 0 && organizado.alegacoes.every((a) => m.has(a.grupo));
}

/** Acrescenta os grupos confirmados em doc.confirmados ("dep" nunca entra). */
export function confirmarAlegacoes(doc, grupos) {
  const atuais = comoLista(comoMapa(doc).confirmados).filter((g) => typeof g === 'string');
  const novos = [...new Set(comoLista(grupos))].filter((g) => typeof g === 'string' && !GRUPOS_NUNCA_CONFIRMAVEIS.includes(g) && !atuais.includes(g));
  if (novos.length === 0) return doc;
  return { ...doc, confirmados: [...atuais, ...novos] };
}

/**
 * Para onde "Ir até lá" leva num item do checklist:
 *   { aba: "dados"|"config"|"estilo", campo }   campo do painel
 *   { secao, chave? }                            seção (e texto/foto) na tela
 */
export function destinoDoProblema(item) {
  const i = comoMapa(item);
  const chave = textoDe(i.chave);
  if (chave.startsWith('dados.') || chave === 'nicho') return { aba: 'dados', campo: chave };
  if (chave.startsWith('rastreamento.') || chave.startsWith('seo.')) return { aba: 'config', campo: chave };
  if (chave.startsWith('estilo.')) return { aba: 'estilo', campo: chave };
  if (Number.isInteger(i.secao)) return { secao: i.secao, chave: chave !== '' ? chave : null };
  if (chave !== '') return { secao: null, chave };
  return null;
}

/** Versão publicada carregada no editor: só o documento (migrado pelo servidor). */
export function documentoDaVersao(resposta) {
  const d = comoMapa(resposta).documento;
  return ehMapa(d) ? d : null;
}

/* ================================================================== diversos */

/** Grupos/listas que a seção (tipo) é dona — onde ficam os botões de item. */
export function listasDoTipo(lib, tipo) {
  return Object.entries(registroListas(lib)).filter(([, def]) => def.dono === tipo).map(([nome]) => nome);
}

/** Atalho: é Ctrl/Cmd+Z (desfazer) ou Ctrl/Cmd+Shift+Z / Ctrl+Y (refazer)? → "desfazer" | "refazer" | null */
export function atalhoHistorico(ev) {
  const mod = ev.ctrlKey || ev.metaKey;
  if (!mod || ev.altKey) return null;
  const k = String(ev.key ?? '').toLowerCase();
  if (k === 'z') return ev.shiftKey ? 'refazer' : 'desfazer';
  if (k === 'y' && ev.ctrlKey && !ev.metaKey) return 'refazer';
  return null;
}

export { nichoDe, especialidade, itensLista, limitesLista };

/* ---------------------------------------------------------------- IA */

// Mesmas regras de app/Lib/Ia/GeradorConteudo.php: botões, nota do Google, números,
// depoimentos, clientes e equipe ficam fora do que a IA escreve.
const IA_CAMPOS_FORA = ['cta', 'cta2', 'botao', 'link', 'mapa'];
const IA_LISTAS = { serv: ['t', 'd'], dif: ['t', 'd'], passos: ['t', 'd'], faq: ['q', 'a'], sobrel: ['t'] };

/** A seção tem algum texto que a IA pode reescrever? (mostra o botão "Reescrever com IA"). */
export function secaoTemTextosIa(lib, tipo) {
  for (const [chave, def] of Object.entries(registroCampos(lib))) {
    if (def.dono !== tipo || !['texto', 'texto-longo'].includes(def.tipo ?? 'texto')) continue;
    if (def.grupo) {
      if (!IA_CAMPOS_FORA.includes(def.campo) && def.grupo !== 'aval' && chave !== 'rodape.texto') return true;
    } else if ((IA_LISTAS[def.lista] ?? []).includes(def.campo)) {
      return true;
    }
  }
  return false;
}
