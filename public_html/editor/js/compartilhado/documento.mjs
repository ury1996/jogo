// Documento do site: criação, troca de modelo, migração e registro de campos.
// PARIDADE OBRIGATÓRIA com app/Preparo/Documento.php (contrato §2, §3).

import { comoLista, comoMapa, ehMapa, pegar, temChave, textoDe } from './texto.mjs';
import { COR_PADRAO, normalizarCor } from './paleta.mjs';

export const VERSAO_ESQUEMA = 2;
export const ACABAMENTOS = ['classico', 'moderno', 'direto', 'elegante', 'suave', 'impacto'];
export const FONTES = ['classica', 'editorial', 'moderna', 'amigavel', 'nobre', 'clara', 'geometrica'];
export const DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
export const REDES = ['instagram', 'facebook', 'linkedin', 'youtube', 'google'];
export const RE_ID_ITEM = /^[a-z0-9]+$/;
const RE_NOME_LISTA = /^[a-z]+$/;

// Ordem das opções do catálogo (§3.2), usada para migrar documentos v1 quando a
// biblioteca não traz o tipo.
const CATALOGO = {
  header: ['simples', 'barra'],
  hero: ['cards-flutuantes', 'fundo-cards', 'formulario', 'centralizado'],
  diferenciais: ['faixa-icones', 'foto-selo'],
  clientes: ['faixa'],
  sobre: ['duas-fotos', 'foto-numeros'],
  servicos: ['cards', 'lista', 'cards-foto', 'blocos'],
  numeros: ['faixa-clara', 'faixa-cor'],
  passos: ['linha-tempo', 'lista-foto'],
  equipe: ['fotos-nome', 'compacta'],
  depoimentos: ['cards-nota', 'destaque-foto'],
  faq: ['centralizada', 'foto-ajuda'],
  cta: ['faixa-cor', 'caixa-clara', 'foto-fundo'],
  contato: ['formulario', 'mapa'],
  rodape: ['completo', 'simples'],
};

/** Pacote do nicho do documento ({} se desconhecido). */
export function nichoDe(doc, lib) {
  return comoMapa(pegar(comoMapa(comoMapa(lib).nichos), textoDe(comoMapa(doc).nicho)));
}

/** Especialidade do documento; ausente/inválida → a primeira do nicho; sem nenhuma → null. */
export function especialidade(doc, lib) {
  const lista = comoLista(nichoDe(doc, lib).especialidades).filter(ehMapa);
  const id = textoDe(comoMapa(doc).especialidade);
  return lista.find((e) => e.id === id) ?? lista[0] ?? null;
}

function idEspecialidade(nicho, id) {
  const lista = comoLista(nicho.especialidades).filter(ehMapa);
  const pedido = textoDe(id);
  if (lista.some((e) => e.id === pedido)) return pedido;
  if (lista.length > 0) return textoDe(lista[0].id);
  return pedido;
}

function paresDeFontes(lib) {
  return comoMapa(comoMapa(comoMapa(lib).fontes).pares);
}

export function fonteValida(lib, fonte) {
  if (typeof fonte !== 'string' || fonte === '') return false;
  const pares = paresDeFontes(lib);
  return Object.keys(pares).length > 0 ? temChave(pares, fonte) : FONTES.includes(fonte);
}

export function fontePadrao(lib) {
  const chaves = Object.keys(paresDeFontes(lib));
  return chaves.length > 0 ? chaves[0] : 'moderna';
}

function estiloPadrao(lib, nicho, modeloId) {
  const modelo = comoMapa(pegar(comoMapa(comoMapa(lib).modelos), modeloId));
  // Cor e fonte: do nicho para este modelo; senão o padrão do próprio modelo (modelos exclusivos de um nicho).
  const doNicho = pegar(comoMapa(nicho.padroesPorModelo), modeloId);
  const padroes = comoMapa(ehMapa(doNicho) ? doNicho : modelo.padrao);
  return {
    cor: normalizarCor(padroes.cor) ?? COR_PADRAO,
    fonte: fonteValida(lib, padroes.fonte) ? padroes.fonte : fontePadrao(lib),
    acabamento: ACABAMENTOS.includes(modelo.acabamento) ? modelo.acabamento : 'moderno',
    whatsappFlutuante: true,
  };
}

function completarEstilo(estilo, lib, nicho, modeloId) {
  const base = estiloPadrao(lib, nicho, modeloId);
  const e = comoMapa(estilo);
  return {
    cor: normalizarCor(e.cor) ?? base.cor,
    fonte: fonteValida(lib, e.fonte) ? e.fonte : base.fonte,
    acabamento: ACABAMENTOS.includes(e.acabamento) ? e.acabamento : base.acabamento,
    whatsappFlutuante: typeof e.whatsappFlutuante === 'boolean' ? e.whatsappFlutuante : base.whatsappFlutuante,
  };
}

function completarHorarios(horarios) {
  const h = comoMapa(horarios);
  const r = {};
  for (const dia of DIAS) {
    if (!temChave(h, dia)) continue;
    const v = h[dia];
    if (v === null || v === false) r[dia] = null;
    else if (Array.isArray(v)) r[dia] = v.map(textoDe);
  }
  return r;
}

/** Dados com TODOS os campos do §2 (strings vazias e estruturas), na ordem canônica. */
export function completarDados(dados) {
  const d = comoMapa(dados);
  const e = comoMapa(d.endereco);
  const r = comoMapa(d.registro);
  const redes = comoMapa(d.redes);
  const redesCompletas = {};
  for (const rede of REDES) redesCompletas[rede] = textoDe(redes[rede]);
  return {
    nome: textoDe(d.nome),
    cidade: textoDe(d.cidade),
    uf: textoDe(d.uf),
    whatsapp: textoDe(d.whatsapp),
    telefone: textoDe(d.telefone),
    email: textoDe(d.email),
    logo: typeof d.logo === 'string' && d.logo !== '' ? d.logo : null,
    endereco: {
      cep: textoDe(e.cep),
      logradouro: textoDe(e.logradouro),
      numero: textoDe(e.numero),
      complemento: textoDe(e.complemento),
      bairro: textoDe(e.bairro),
    },
    horarios: completarHorarios(d.horarios),
    registro: { numero: textoDe(r.numero), uf: textoDe(r.uf), responsavel: textoDe(r.responsavel) },
    redes: redesCompletas,
  };
}

/** A seção e a opção existem na biblioteca? */
export function secaoExiste(lib, tipo, opcao) {
  const sec = comoMapa(pegar(comoMapa(comoMapa(lib).secoes), tipo));
  return comoLista(comoMapa(sec.manifest).opcoes).some((o) => ehMapa(o) && o.id === opcao);
}

/** Receita do modelo filtrada por so/exceto do nicho → [{tipo, opcao}]. */
export function receitaModelo(lib, modelo, nicho) {
  const def = typeof modelo === 'string'
    ? comoMapa(pegar(comoMapa(comoMapa(lib).modelos), modelo))
    : comoMapa(modelo);
  const nichoId = typeof nicho === 'string' ? nicho : textoDe(comoMapa(nicho).id);
  const receita = [];
  for (const entrada of comoLista(def.secoes)) {
    let tipo;
    let opcao;
    let filtro;
    if (Array.isArray(entrada)) {
      tipo = textoDe(entrada[0]);
      opcao = textoDe(entrada[1]);
      filtro = comoMapa(entrada[2]);
    } else if (ehMapa(entrada)) {
      tipo = textoDe(entrada.tipo);
      opcao = textoDe(entrada.opcao);
      filtro = entrada;
    } else {
      continue;
    }
    if (tipo === '' || opcao === '') continue;
    if (Array.isArray(filtro.so) && !filtro.so.includes(nichoId)) continue;
    if (Array.isArray(filtro.exceto) && filtro.exceto.includes(nichoId)) continue;
    receita.push({ tipo, opcao });
  }
  return receita;
}

function secoesDoModelo(lib, modeloId, nichoId) {
  return receitaModelo(lib, modeloId, nichoId).filter((s) => secaoExiste(lib, s.tipo, s.opcao));
}

function exigirModelo(lib, modeloId) {
  const modelo = pegar(comoMapa(comoMapa(lib).modelos), modeloId);
  if (!ehMapa(modelo)) throw new Error(`Modelo desconhecido: "${modeloId}".`);
  return modelo;
}

/**
 * Documento inicial: receita do modelo filtrada pelo nicho, estilo padrão
 * (nicho.padroesPorModelo + acabamento do modelo + WhatsApp flutuante), estilo
 * recebido por cima, especialidade (primeira do nicho se não vier) e dados completos.
 */
export function criarDocumento(entrada, lib) {
  const e = comoMapa(entrada);
  const nichoId = textoDe(e.nicho);
  const modeloId = textoDe(e.modelo);
  const nicho = pegar(comoMapa(comoMapa(lib).nichos), nichoId);
  if (!ehMapa(nicho)) throw new Error(`Nicho desconhecido: "${nichoId}".`);
  exigirModelo(lib, modeloId);
  return {
    versaoEsquema: VERSAO_ESQUEMA,
    nicho: nichoId,
    especialidade: idEspecialidade(nicho, e.especialidade),
    modelo: modeloId,
    estilo: completarEstilo(e.estilo, lib, nicho, modeloId),
    dados: completarDados(e.dados),
    secoes: secoesDoModelo(lib, modeloId, nichoId),
    textos: {},
    listas: {},
    imagens: {},
    icones: {},
    confirmados: [],
    rastreamento: { gtm: '', ga4: '', metaPixel: '' },
    seo: { titulo: null, descricao: null },
  };
}

/**
 * Troca o modelo: mudam seções, acabamento e fonte padrão do modelo; ficam textos,
 * imagens, ícones, dados, cor, listas, confirmados, rastreamento e SEO.
 */
export function aplicarModelo(doc, lib, modelo) {
  const modeloId = textoDe(modelo);
  exigirModelo(lib, modeloId);
  const novo = JSON.parse(JSON.stringify(comoMapa(doc)));
  const nicho = nichoDe(novo, lib);
  const padrao = estiloPadrao(lib, nicho, modeloId);
  const estilo = completarEstilo(novo.estilo, lib, nicho, modeloId);
  estilo.fonte = padrao.fonte;
  estilo.acabamento = padrao.acabamento;
  novo.modelo = modeloId;
  novo.estilo = estilo;
  novo.secoes = secoesDoModelo(lib, modeloId, textoDe(novo.nicho));
  return novo;
}

/** Chave v1 (listas base 0) → chave v2 (ids "1", "2", …). */
export function converterChaveV1(chave) {
  let m;
  if (chave === 'clientes.titulo') return 'cli.titulo';
  if ((m = /^clientes\.(\d{1,4})$/.exec(chave))) return `cli.${Number(m[1]) + 1}.t`;
  if ((m = /^sobre\.l\.(\d{1,4})$/.exec(chave))) return `sobrel.${Number(m[1]) + 1}.t`;
  if ((m = /^([a-z]+)\.(\d{1,4})\.([a-z][a-z0-9]*)$/.exec(chave))) return `${m[1]}.${Number(m[2]) + 1}.${m[3]}`;
  if ((m = /^([a-z]+)\.(\d{1,4})$/.exec(chave))) return `${m[1]}.${Number(m[2]) + 1}`;
  return chave;
}

function mapaDeTextos(mapa, converter) {
  const r = {};
  for (const [chave, valor] of Object.entries(comoMapa(mapa))) {
    if (typeof valor !== 'string') continue;
    const nova = converter(chave);
    if (!temChave(r, nova)) r[nova] = valor;
  }
  return r;
}

/** Ids válidos de uma lista (texto /^[a-z0-9]+$/ ou inteiro ≥ 0), sem repetição. */
export function idsValidos(lista) {
  const ids = [];
  for (const v of comoLista(lista)) {
    const id = typeof v === 'number' && Number.isInteger(v) && v >= 0 ? String(v) : v;
    if (typeof id === 'string' && RE_ID_ITEM.test(id) && !ids.includes(id)) ids.push(id);
  }
  return ids;
}

function migrarListas(listas) {
  const r = {};
  for (const [nome, ids] of Object.entries(comoMapa(listas))) {
    if (RE_NOME_LISTA.test(nome) && Array.isArray(ids)) r[nome] = idsValidos(ids);
  }
  return r;
}

function opcoesDoTipo(lib, tipo) {
  const sec = comoMapa(pegar(comoMapa(comoMapa(lib).secoes), tipo));
  const ids = comoLista(comoMapa(sec.manifest).opcoes).filter(ehMapa).map((o) => textoDe(o.id)).filter((id) => id !== '');
  if (ids.length > 0) return ids;
  return comoLista(pegar(CATALOGO, tipo));
}

function migrarSecoes(secoes, lib) {
  const r = [];
  for (const s of comoLista(secoes)) {
    if (!ehMapa(s) || typeof s.tipo !== 'string' || s.tipo === '') continue;
    let opcao = s.opcao;
    if (typeof opcao === 'number' && Number.isInteger(opcao)) {
      const ids = opcoesDoTipo(lib, s.tipo);
      if (ids.length === 0) continue;
      opcao = opcao >= 0 && opcao < ids.length ? ids[opcao] : ids[0];
    }
    if (typeof opcao !== 'string' || opcao === '') continue;
    r.push({ tipo: s.tipo, opcao });
  }
  return r;
}

/**
 * Migra/normaliza qualquer documento para o esquema 2.
 * v1 → v2: opção numérica → id pela ordem do manifesto; chaves de lista base 0
 * ("serv.0.t") → ids "1"…; ícones e imagens idem; dados completados.
 * Documentos v2 só são normalizados (campos ausentes completados, tipos corrigidos).
 * `lib` é opcional (sem ela, a ordem das opções vem do catálogo §3.2).
 */
export function migrar(doc, lib) {
  const d = comoMapa(doc);
  const v1 = d.versaoEsquema !== VERSAO_ESQUEMA;
  const converter = v1 ? converterChaveV1 : (k) => k;
  const nicho = nichoDe(d, lib);
  const modeloId = textoDe(d.modelo);
  const r = comoMapa(d.rastreamento);
  const seo = comoMapa(d.seo);
  const confirmados = [];
  for (const g of comoLista(d.confirmados)) if (typeof g === 'string' && !confirmados.includes(g)) confirmados.push(g);
  return {
    versaoEsquema: VERSAO_ESQUEMA,
    nicho: textoDe(d.nicho),
    especialidade: idEspecialidade(nicho, d.especialidade),
    modelo: modeloId,
    estilo: completarEstilo(d.estilo, lib, nicho, modeloId),
    dados: completarDados(d.dados),
    secoes: migrarSecoes(d.secoes, lib),
    textos: mapaDeTextos(d.textos, converter),
    listas: migrarListas(d.listas),
    imagens: mapaDeTextos(d.imagens, converter),
    icones: mapaDeTextos(d.icones, converter),
    confirmados,
    rastreamento: { gtm: textoDe(r.gtm), ga4: textoDe(r.ga4), metaPixel: textoDe(r.metaPixel) },
    seo: {
      titulo: typeof seo.titulo === 'string' ? seo.titulo : null,
      descricao: typeof seo.descricao === 'string' ? seo.descricao : null,
    },
  };
}

/**
 * Registro de todos os campos da biblioteca: chave → definição + dono.
 * Escalares: "serv.titulo" → {…def, dono, grupo, campo}.
 * Itens de lista: "serv.*.t" → {…def, dono, lista, campo}.
 * Se dois manifestos definirem a mesma chave, vale o primeiro (ordem do bundle).
 */
export function registroCampos(lib) {
  const registro = {};
  for (const [tipo, sec] of Object.entries(comoMapa(comoMapa(lib).secoes))) {
    const manifest = comoMapa(comoMapa(sec).manifest);
    for (const [chave, def] of Object.entries(comoMapa(manifest.campos))) {
      const partes = chave.split('.');
      if (partes.length !== 2 || temChave(registro, chave)) continue;
      registro[chave] = { ...comoMapa(def), dono: tipo, grupo: partes[0], campo: partes[1] };
    }
    for (const [lista, ldef] of Object.entries(comoMapa(manifest.listas))) {
      for (const [campo, def] of Object.entries(comoMapa(comoMapa(ldef).campos))) {
        const chave = `${lista}.*.${campo}`;
        if (temChave(registro, chave)) continue;
        registro[chave] = { ...comoMapa(def), dono: tipo, lista, campo };
      }
    }
  }
  return registro;
}

/** Definições das listas: nome → {…def (repete, rotuloItem, campos), dono}. */
export function registroListas(lib) {
  const listas = {};
  for (const [tipo, sec] of Object.entries(comoMapa(comoMapa(lib).secoes))) {
    const manifest = comoMapa(comoMapa(sec).manifest);
    for (const [lista, ldef] of Object.entries(comoMapa(manifest.listas))) {
      if (!temChave(listas, lista)) listas[lista] = { ...comoMapa(ldef), dono: tipo };
    }
  }
  return listas;
}

/** Definição de uma chave concreta ("serv.nk3f.t" → registro["serv.*.t"]); null se não houver. */
export function definicaoCampo(registro, chave) {
  const partes = textoDe(chave).split('.');
  if (partes.length === 2) return pegar(registro, chave) ?? null;
  if (partes.length === 3) return pegar(registro, `${partes[0]}.*.${partes[2]}`) ?? null;
  return null;
}

/**
 * O modelo pode ser usado neste nicho? Modelo sem "nichos" vale para todos; com "nichos",
 * só para os listados (modelos exclusivos de um nicho).
 */
export function modeloDoNicho(lib, modeloId, nichoId) {
  const modelo = pegar(comoMapa(comoMapa(lib).modelos), modeloId);
  if (!ehMapa(modelo)) return false;
  const nichos = pegar(modelo, 'nichos');
  return !Array.isArray(nichos) || nichos.includes(nichoId);
}
