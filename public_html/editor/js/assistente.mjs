// Assistente de criação (PDF cap. 6) e troca de modelo de um site existente.
//
//   #/novo           passo 1 · tipo de negócio (cards com miniatura real do modelo Moderno)
//   #/novo/modelo    passo 2 · modelo (3 cards, "Ver prévia" em tela cheia, "Usar este modelo")
//   #/novo/dados     passo 3 · dados do negócio com prévia ao vivo → "Gerar meu site"
//   #/site/{id}/modelo  troca de modelo de um site já criado ("Em uso"; entra no desfazer)
//
// montar(alvo, { passo: "nicho" | "modelo" | "dados", siteId? })
//
// As escolhas do assistente ficam na memória (e no sessionStorage, menos o arquivo do logo),
// então "Voltar" e recarregar a página não perdem o que já foi preenchido.

import { api } from './api.mjs';
import {
  el, icone, aviso, modal, navegar, carregando, telaErro, campo, tempoRelativo,
  mascaraTelefone, problemaWhatsapp, UFS, CORES_SUGERIDAS, corClaraDemais, corDominanteDaImagem,
  problemaArquivoLogo, ACEITA_LOGO,
} from './ui.mjs';
import {
  carregarBiblioteca, nichosOrdenados, modelosOrdenados, nomeNicho, nomeModelo, nomeFonte,
} from './biblioteca.mjs';
import {
  miniatura, renderizarPrevia, escalar, LARGURA_COMPUTADOR, LARGURA_CELULAR,
} from './previa.mjs';
import { criarDocumento, aplicarModelo, receitaModelo } from './compartilhado/documento.mjs';
import { normalizarCor } from './compartilhado/paleta.mjs';
import { comFotosDeExemplo } from './compartilhado/fotos.mjs';
import { EstadoEditor } from './estado.mjs';
import {
  iaDisponivel, pedirConteudo, aplicarPatchIa, guardarDescricao, guardarTom, seletorTom, tomValido, resumoResultado, MIN_DESCRICAO, MAX_DESCRICAO,
  chipsExemplos, linhaDica, rotuloGerar, placeholderDescricao,
} from './ia.mjs';

export const MAX_NOME = 60;
export const MAX_CIDADE = 40;
const CHAVE_SESSAO = 'rk:assistente';
const ID_LOGO_LOCAL = 'logo-local';
const MODELO_MINIATURA_NICHO = 'moderno';

/* ================================================================== funções puras */

/** Estado vazio do assistente. */
export function estadoInicial() {
  return { nicho: null, especialidade: null, modelo: null, dados: { nome: '', cidade: '', uf: '', whatsapp: '' }, cor: null, descricao: '', tom: '' };
}

/** Dados de exemplo do nicho (nome, cidade, UF, WhatsApp + exemplo.dados: telefone, e-mail, endereço…). */
export function dadosExemplo(nicho) {
  const ex = nicho?.exemplo && typeof nicho.exemplo === 'object' ? nicho.exemplo : {};
  const extra = ex.dados && typeof ex.dados === 'object' ? ex.dados : {};
  return {
    ...JSON.parse(JSON.stringify(extra)),
    nome: String(ex.nome ?? ''),
    cidade: String(ex.cidade ?? ''),
    uf: String(ex.uf ?? ''),
    whatsapp: String(ex.whatsapp ?? ''),
  };
}

/** Documento da miniatura do nicho: modelo Moderno com todos os dados de exemplo. */
export function documentoExemplo(lib, nichoId, modelo = MODELO_MINIATURA_NICHO) {
  const nicho = lib?.nichos?.[nichoId];
  const modeloId = lib?.modelos?.[modelo] ? modelo : Object.keys(lib?.modelos ?? {})[0];
  return criarDocumento({ nicho: nichoId, modelo: modeloId, dados: dadosExemplo(nicho) }, lib);
}

/** Cor padrão do nicho naquele modelo (a que o site recebe se a pessoa não escolher outra). */
export function corPadrao(lib, nichoId, modeloId) {
  return normalizarCor(lib?.nichos?.[nichoId]?.padroesPorModelo?.[modeloId]?.cor) ?? '#c23b6e';
}

/** Dados do negócio limpos, prontos para o documento (espaços colapsados, limites). */
export function dadosLimpos(dados = {}) {
  const t = (v, max) => [...String(v ?? '').replace(/\s+/g, ' ').trim()].slice(0, max).join('');
  const uf = String(dados.uf ?? '').toUpperCase();
  return {
    nome: t(dados.nome, MAX_NOME),
    cidade: t(dados.cidade, MAX_CIDADE),
    uf: UFS.includes(uf) ? uf : '',
    whatsapp: mascaraTelefone(dados.whatsapp ?? ''),
  };
}

/**
 * Documento que o assistente mostra (e que o servidor vai criar) para o estado atual.
 * Na prévia, um WhatsApp ainda incompleto ou inválido dá lugar ao do exemplo (os botões
 * do site continuam aparecendo enquanto a pessoa digita).
 */
export function documentoDoAssistente(lib, estado, { modelo, previa = false } = {}) {
  const modeloId = modelo ?? estado.modelo ?? MODELO_MINIATURA_NICHO;
  const estilo = estado.cor ? { cor: estado.cor } : {};
  const dados = dadosLimpos(estado.dados);
  if (previa && problemaWhatsapp(dados.whatsapp)) dados.whatsapp = '';
  return criarDocumento({
    nicho: estado.nicho, especialidade: estado.especialidade ?? undefined, modelo: modeloId, dados, estilo,
  }, lib);
}

/** Corpo do POST /api/sites. */
export function corpoCriacao(lib, estado) {
  const corpo = {
    nicho: estado.nicho,
    modelo: estado.modelo,
    dados: dadosLimpos(estado.dados),
    estilo: { cor: estado.cor ?? corPadrao(lib, estado.nicho, estado.modelo) },
  };
  if (estado.especialidade) corpo.especialidade = estado.especialidade;
  return corpo;
}

/** Erros do passo 3 por campo ({ nome?, cidade?, whatsapp? }) — vazio = tudo certo. */
export function errosDadosNegocio(dados = {}) {
  const erros = {};
  if ([...String(dados.nome ?? '').trim()].length > MAX_NOME) erros.nome = `O nome pode ter até ${MAX_NOME} caracteres.`;
  if ([...String(dados.cidade ?? '').trim()].length > MAX_CIDADE) erros.cidade = `A cidade pode ter até ${MAX_CIDADE} caracteres.`;
  const w = problemaWhatsapp(dados.whatsapp ?? '');
  if (w) erros.whatsapp = w;
  return erros;
}

/**
 * Bolinhas de cor do passo 3: cor do logo (se houver) primeiro, depois a cor padrão do modelo
 * (se não estiver entre as sugestões) e as 9 sugestões. Sem repetições.
 */
export function coresParaEscolher({ corLogo = null, padrao = null } = {}) {
  const lista = [];
  const vistas = new Set();
  const por = (cor, nome, origem) => {
    const c = normalizarCor(cor);
    if (!c || vistas.has(c)) return;
    vistas.add(c);
    lista.push({ cor: c, nome, origem });
  };
  por(corLogo, 'Cor do seu logo', 'logo');
  if (padrao && !CORES_SUGERIDAS.some((s) => s.cor === normalizarCor(padrao))) por(padrao, 'Cor do modelo', 'modelo');
  for (const s of CORES_SUGERIDAS) por(s.cor, s.nome, 'sugestao');
  return lista;
}

/** "11 seções · fonte editorial" */
export function metaModelo(lib, modeloId, nichoId) {
  const n = receitaModelo(lib, modeloId, nichoId).length;
  const fonte = nomeFonte(lib, lib?.nichos?.[nichoId]?.padroesPorModelo?.[modeloId]?.fonte).toLowerCase();
  return `${n} ${n === 1 ? 'seção' : 'seções'}${fonte ? ` · fonte ${fonte}` : ''}`;
}

/** Nome do nicho em minúsculas para o título ("Escolha um modelo de clínicas"). */
export function nichoNoTitulo(lib, nichoId) {
  return nomeNicho(lib, nichoId).toLocaleLowerCase('pt-BR');
}

/* ================================================================== estado do assistente */

function lerSessao() {
  try {
    const bruto = sessionStorage.getItem(CHAVE_SESSAO);
    if (!bruto) return estadoInicial();
    const e = JSON.parse(bruto);
    const base = estadoInicial();
    return {
      nicho: typeof e.nicho === 'string' ? e.nicho : null,
      especialidade: typeof e.especialidade === 'string' ? e.especialidade : null,
      modelo: typeof e.modelo === 'string' ? e.modelo : null,
      dados: { ...base.dados, ...(e.dados && typeof e.dados === 'object' ? e.dados : {}) },
      cor: normalizarCor(e.cor),
      descricao: typeof e.descricao === 'string' ? e.descricao.slice(0, MAX_DESCRICAO) : '',
      tom: tomValido(e.tom),
    };
  } catch {
    return estadoInicial();
  }
}

let assistente = null; // estado do assistente (novo site)
let logo = null; // { arquivo, url, cor, largura, altura } — só em memória

function estadoNovo() {
  if (!assistente) assistente = typeof sessionStorage !== 'undefined' ? lerSessao() : estadoInicial();
  return assistente;
}

function guardar() {
  try {
    sessionStorage.setItem(CHAVE_SESSAO, JSON.stringify(assistente));
  } catch {
    /* sem armazenamento: segue só na memória */
  }
}

function recomecar() {
  assistente = estadoInicial();
  definirLogo(null);
  try {
    sessionStorage.removeItem(CHAVE_SESSAO);
  } catch {
    /* nada */
  }
}

function definirLogo(novo) {
  if (logo?.url) URL.revokeObjectURL(logo.url);
  logo = novo;
}

/** Mídia da prévia com o logo local (antes de existir no servidor). */
function midiaComLogo() {
  if (!logo) return {};
  return { [ID_LOGO_LOCAL]: { largura: logo.largura || 320, altura: logo.altura || 120, variantes: [], tipo: 'logo', formato: 'webp', local: logo.url } };
}

/** URL das variantes das fotos de exemplo da biblioteca (prévias antes de o site existir). */
const URL_FOTOS_EXEMPLO = '/api/fotos-exemplo/';

/** Documento e mídia da prévia com as fotos de exemplo nos espaços vazios (o site nasce assim). */
function previaComFotos(doc, lib, midia = {}) {
  const r = comFotosDeExemplo(doc, lib, URL_FOTOS_EXEMPLO);
  return { doc: r.doc, midia: { ...midia, ...r.midia } };
}

function comLogoLocal(doc) {
  if (logo) doc.dados.logo = ID_LOGO_LOCAL;
  return doc;
}

/* ================================================================== peças de tela */

const PASSOS = [
  { id: 'nicho', rotulo: 'Tipo de negócio', hash: '#/novo' },
  { id: 'modelo', rotulo: 'Modelo', hash: '#/novo/modelo' },
  { id: 'dados', rotulo: 'Seus dados', hash: '#/novo/dados' },
  { id: 'editar', rotulo: 'Editar', hash: null },
];

function indicadorPassos(atual) {
  const iAtual = PASSOS.findIndex((p) => p.id === atual);
  return el('nav', { 'aria-label': 'Etapas da criação do site' },
    el('ol', { class: 'passos' }, PASSOS.map((p, i) => {
      const feito = i < iAtual;
      const conteudo = [
        el('span', { class: 'passo__num', 'aria-hidden': 'true' }, feito ? icone('check') : String(i + 1)),
        el('span', null, p.rotulo),
        feito ? el('span', { class: 'sr-only' }, ' (concluído)') : null,
      ];
      return el('li', { class: ['passo', feito && 'passo--feito', i === iAtual && 'passo--atual'], 'aria-current': i === iAtual ? 'step' : null },
        feito && p.hash ? el('a', { href: p.hash }, ...conteudo) : conteudo);
    })));
}

/** Liga miniaturas e escalas à tela para desligar tudo no desmontar. */
function coletor() {
  const itens = [];
  return {
    add(x) {
      if (x) itens.push(x);
      return x;
    },
    desligar() {
      for (const x of itens.splice(0)) {
        try {
          (x.desligar ?? x)();
        } catch {
          /* nada */
        }
      }
    },
  };
}

/* ================================================================== prévia em tela cheia */

function abrirPreviaCheia({ lib, doc, midia, titulo, sub, acaoPrincipal }) {
  let largura = LARGURA_COMPUTADOR;
  const site = el('div', { class: 'previa-moldura__site' });
  const moldura = el('div', { class: 'previa-moldura', tabindex: '0', role: 'region', 'aria-label': `Prévia do ${titulo}` }, site);
  const botoes = {};
  const segmentos = el('div', { class: 'segmentos', role: 'group', 'aria-label': 'Tamanho da tela' },
    botoes.computador = el('button', { type: 'button', 'aria-pressed': 'true' }, icone('computador'), 'Computador'),
    botoes.celular = el('button', { type: 'button', 'aria-pressed': 'false' }, icone('celular'), 'Celular'));
  let janela;
  const voltar = el('button', { type: 'button', class: 'icone-btn', 'aria-label': 'Fechar a prévia', onclick: () => janela.fechar() }, icone('voltar'));
  const idTitulo = `previa-cheia-t-${Date.now()}`;
  const principal = acaoPrincipal
    ? el('button', { type: 'button', class: ['btn', acaoPrincipal.tipo === 'secundario' ? null : 'btn--primario'] }, acaoPrincipal.rotulo)
    : null;
  const barra = el('div', { class: 'previa-cheia__barra' },
    el('div', { class: 'previa-cheia__titulos' }, voltar,
      el('div', null, el('h2', { class: 'previa-cheia__titulo', id: idTitulo }, titulo), el('p', { class: 'previa-cheia__sub' }, sub))),
    segmentos,
    el('div', { class: 'previa-cheia__dir' }, principal));

  renderizarPrevia(site, doc, lib, { midia });
  let escala = null;
  janela = modal({
    tamanho: 'tela-cheia',
    rotulo: titulo,
    cabecalho: barra,
    corpo: el('div', { class: 'previa-cheia' }, moldura),
    aoFechar: () => escala?.desligar(),
  });
  janela.elemento.setAttribute('aria-labelledby', idTitulo);
  janela.elemento.removeAttribute('aria-label');
  escala = escalar(moldura, site, largura);

  const definir = (l) => {
    largura = l;
    botoes.computador.setAttribute('aria-pressed', String(l === LARGURA_COMPUTADOR));
    botoes.celular.setAttribute('aria-pressed', String(l === LARGURA_CELULAR));
    moldura.classList.toggle('previa-moldura--celular', l === LARGURA_CELULAR);
    escala.definirLargura(l);
    moldura.scrollTop = 0;
  };
  botoes.computador.addEventListener('click', () => definir(LARGURA_COMPUTADOR));
  botoes.celular.addEventListener('click', () => definir(LARGURA_CELULAR));
  principal?.addEventListener('click', async () => {
    principal.disabled = true;
    principal.setAttribute('aria-busy', 'true');
    try {
      const r = await acaoPrincipal.fn();
      if (r !== false) janela.fechar();
    } finally {
      principal.disabled = false;
      principal.removeAttribute('aria-busy');
    }
  });
  voltar.focus();
  return janela;
}

/* ================================================================== passo 1 · nicho */

async function passoNicho(alvo, lib, limpeza) {
  document.title = 'Novo site · Tipo de negócio · Construtor Rankly';
  const estado = estadoNovo();
  const continuar = el('div', { class: 'continuar-espaco' });
  const grade = el('div', { class: 'grade-nichos', role: 'list' });

  alvo.replaceChildren(el('div', { class: 'pagina assistente' },
    indicadorPassos('nicho'),
    continuar,
    el('div', { class: 'cab-assistente' },
      el('h1', { class: 'titulo-pagina titulo-assistente' }, 'Qual é o tipo do seu negócio?'),
      el('p', { class: 'subtitulo' }, 'Cada tipo tem modelos prontos, com seções e textos pensados para ele. Depois é só trocar o que quiser.')),
    grade));

  for (const nicho of nichosOrdenados(lib)) {
    const card = el('button', {
      type: 'button', class: 'card-opcao', 'aria-pressed': String(estado.nicho === nicho.id),
      'aria-label': `${nicho.nome}: ${nicho.descricao ?? ''}`,
    });
    const mini = el('div', { class: 'card-opcao__mini' });
    card.append(mini, el('div', { class: 'card-opcao__corpo' },
      el('span', { class: 'card-opcao__titulo' }, nicho.nome),
      el('span', { class: 'card-opcao__desc' }, nicho.descricao ?? '')));
    card.addEventListener('click', () => {
      if (estado.nicho !== nicho.id) {
        estado.nicho = nicho.id;
        estado.especialidade = null;
        estado.modelo = null;
      }
      guardar();
      navegar('#/novo/modelo');
    });
    grade.append(el('div', { role: 'listitem', class: 'grade-item' }, card));
    try {
      const p = previaComFotos(documentoExemplo(lib, nicho.id), lib);
      const m = limpeza.add(miniatura(p.doc, lib, { midia: p.midia }));
      mini.append(m.elemento);
    } catch (e) {
      console.error(e);
      mini.append(el('div', { class: 'previa-mini' }));
    }
  }

  // "Continuar de onde parou": o site mais recente (a lista vem ordenada por atualização).
  try {
    const r = await api.get('/sites');
    const recente = (r?.sites ?? [])[0];
    if (recente && alvo.isConnected) {
      const fechar = el('button', { type: 'button', class: 'btn' }, 'Começar do zero');
      const bloco = el('section', { class: 'continuar', 'aria-labelledby': 'continuar-titulo' },
        el('div', { class: 'continuar__ico', 'aria-hidden': 'true' }, icone('editar')),
        el('div', { class: 'continuar__texto' },
          el('h2', { class: 'continuar__titulo', id: 'continuar-titulo' }, 'Continuar de onde parou'),
          el('p', { class: 'continuar__meta' },
            `${recente.nome} · ${nomeNicho(lib, recente.nicho)} · modelo ${nomeModelo(lib, recente.modelo)} · salvo ${tempoRelativo(recente.atualizadoEm)}`)),
        el('div', { class: 'continuar__acoes' },
          fechar,
          el('a', { class: 'btn btn--primario', href: `#/site/${recente.id}` }, 'Continuar editando')));
      fechar.addEventListener('click', () => {
        recomecar();
        bloco.remove();
        for (const c of grade.querySelectorAll('.card-opcao')) c.setAttribute('aria-pressed', 'false');
        grade.querySelector('.card-opcao')?.focus();
      });
      continuar.replaceChildren(bloco);
    }
  } catch (e) {
    if (e?.status === 401) throw e;
  }
}

/* ================================================================== passo 2 · modelo */

async function passoModelo(alvo, lib, limpeza, { siteId = null } = {}) {
  const novo = siteId === null;
  let site = null;
  let docBase = null;
  let midia = {};
  let estado = null;

  if (novo) {
    estado = estadoNovo();
    if (!estado.nicho || !lib.nichos?.[estado.nicho]) {
      navegar('#/novo', { substituir: true });
      return;
    }
    midia = midiaComLogo();
  } else {
    const r = await api.get(`/sites/${siteId}`);
    site = r.site;
    docBase = site.documento;
    midia = r.midia ?? {};
    if (site.status === 'arquivado') {
      alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
        telaErro('Este site está arquivado e não pode ser editado.')));
      return;
    }
  }
  const nichoId = novo ? estado.nicho : docBase.nicho;
  const emUso = novo ? null : docBase.modelo;
  document.title = novo ? 'Novo site · Modelo · Construtor Rankly' : `Trocar modelo · ${site.nome} · Construtor Rankly`;

  const docDoModelo = (modeloId) => (novo
    ? comLogoLocal(documentoDoAssistente(lib, estado, { modelo: modeloId, previa: true }))
    : aplicarModelo(docBase, lib, modeloId));

  async function usar(modeloId) {
    if (novo) {
      estado.modelo = modeloId;
      guardar();
      navegar('#/novo/dados');
      return true;
    }
    if (modeloId === emUso) {
      navegar(`#/site/${siteId}`);
      return true;
    }
    return trocarModeloDoSite(modeloId);
  }

  async function trocarModeloDoSite(modeloId) {
    const rotulo = `Trocar para o modelo ${nomeModelo(lib, modeloId)}`;
    try {
      const vivo = EstadoEditor.ativo(siteId);
      if (vivo) {
        vivo.aplicar((d) => aplicarModelo(d, lib, modeloId), { rotulo });
        await vivo.salvar();
      } else {
        let antes = docBase;
        let revisao = site.revisao;
        let r;
        try {
          r = await api.put(`/sites/${siteId}`, { revisao, documento: aplicarModelo(antes, lib, modeloId) });
        } catch (e) {
          if (e?.status !== 409) throw e;
          // O site mudou desde que a tela abriu (ex.: o editor terminou de salvar): aplica sobre o mais novo.
          antes = e.dados?.documento ?? (await api.get(`/sites/${siteId}`)).site.documento;
          revisao = e.dados?.revisaoAtual ?? revisao;
          r = await api.put(`/sites/${siteId}`, { revisao, documento: aplicarModelo(antes, lib, modeloId) });
        }
        EstadoEditor.semearHistorico(siteId, { antes, revisao: r.revisao, rotulo });
        // Espaços de foto que o modelo novo mostra e o site ainda não tinha: fotos de exemplo.
        try {
          await api.post(`/sites/${siteId}/fotos-exemplo`, { revisao: r.revisao });
        } catch (e) {
          console.error(e);
        }
      }
      aviso(`Modelo trocado para ${nomeModelo(lib, modeloId)}. Use Desfazer no editor para voltar.`, { tipo: 'ok', duracao: 7000 });
      navegar(`#/site/${siteId}`);
      return true;
    } catch (e) {
      if (e?.status === 401) return false;
      aviso(e?.mensagem ?? 'Não foi possível trocar o modelo. Tente de novo.', { tipo: 'erro' });
      return false;
    }
  }

  const grade = el('div', { class: 'grade-modelos', role: 'list' });
  const cab = novo
    ? [
      indicadorPassos('modelo'),
      el('a', { class: 'link-voltar', href: '#/novo' }, icone('voltar'), 'Trocar tipo de negócio'),
      el('div', { class: 'cab-assistente' },
        el('h1', { class: 'titulo-pagina titulo-assistente' }, `Escolha um modelo de ${nichoNoTitulo(lib, nichoId)}`),
        el('p', { class: 'subtitulo' }, 'Todos já vêm com seções e textos para o seu tipo de negócio. Muda a estrutura e o estilo; dá para trocar depois.')),
    ]
    : [
      el('a', { class: 'link-voltar', href: `#/site/${siteId}` }, icone('voltar'), 'Voltar ao editor'),
      el('div', { class: 'cab-assistente' },
        el('h1', { class: 'titulo-pagina titulo-assistente' }, `Escolha um modelo de ${nichoNoTitulo(lib, nichoId)}`),
        el('p', { class: 'subtitulo' }, `Seus textos, fotos e dados continuam. Só a estrutura e o estilo do site ${site.nome} mudam.`)),
    ];
  alvo.replaceChildren(el('div', { class: 'pagina assistente' }, ...cab, grade));

  for (const modelo of modelosOrdenados(lib, nichoId)) {
    const ativo = novo ? estado.modelo === modelo.id : emUso === modelo.id;
    const ehEmUso = !novo && emUso === modelo.id;
    const { doc, midia: midiaModelo } = previaComFotos(docDoModelo(modelo.id), lib, midia);
    const idTitulo = `modelo-${modelo.id}-t`;
    const mini = el('div', { class: 'card-opcao__mini' });
    const verPrevia = el('button', { type: 'button', class: 'btn', 'aria-describedby': idTitulo }, icone('olho'), 'Ver prévia');
    const principal = el('button', { type: 'button', class: 'btn btn--primario', 'aria-describedby': idTitulo },
      ehEmUso ? 'Voltar ao editor' : 'Usar este modelo');
    const card = el('article', { class: ['card-opcao', ativo && 'card-opcao--ativo'], 'aria-labelledby': idTitulo, role: 'listitem' },
      mini,
      el('div', { class: 'card-opcao__corpo' },
        el('h2', { class: 'card-opcao__titulo', id: idTitulo }, modelo.nome,
          ehEmUso ? el('span', { class: 'selo selo--pri' }, 'Em uso') : null),
        el('p', { class: 'card-opcao__desc' }, modelo.descricao ?? ''),
        el('p', { class: 'card-opcao__meta' }, metaModelo(lib, modelo.id, nichoId)),
        el('div', { class: 'card-opcao__acoes' }, verPrevia, principal)));
    grade.append(card);
    try {
      const m = limpeza.add(miniatura(doc, lib, { midia: midiaModelo, classe: 'previa-mini--alta' }));
      mini.append(m.elemento);
    } catch (e) {
      console.error(e);
    }
    verPrevia.addEventListener('click', () => abrirPreviaCheia({
      lib, doc, midia: midiaModelo,
      titulo: `Modelo ${modelo.nome}`,
      sub: `${nomeNicho(lib, nichoId)} · ${metaModelo(lib, modelo.id, nichoId)}`,
      acaoPrincipal: { rotulo: ehEmUso ? 'Voltar ao editor' : 'Usar este modelo', fn: () => usar(modelo.id) },
    }));
    principal.addEventListener('click', async () => {
      principal.disabled = true;
      principal.setAttribute('aria-busy', 'true');
      const ok = await usar(modelo.id);
      if (!ok && principal.isConnected) {
        principal.disabled = false;
        principal.removeAttribute('aria-busy');
      }
    });
  }
}

/* ================================================================== passo 3 · dados */

function passoDados(alvo, lib, limpeza) {
  const estado = estadoNovo();
  if (!estado.nicho || !lib.nichos?.[estado.nicho]) {
    navegar('#/novo', { substituir: true });
    return;
  }
  if (!estado.modelo || !lib.modelos?.[estado.modelo]) {
    navegar('#/novo/modelo', { substituir: true });
    return;
  }
  document.title = 'Novo site · Seus dados · Construtor Rankly';
  const nicho = lib.nichos[estado.nicho];
  const exemplo = dadosExemplo(nicho);
  const padrao = corPadrao(lib, estado.nicho, estado.modelo);
  let tocouWhatsapp = estado.dados.whatsapp !== '';

  /* ---------- campos */
  const nome = el('input', { type: 'text', name: 'nome', autocomplete: 'organization', value: estado.dados.nome, placeholder: exemplo.nome });
  const cNome = campo({ rotulo: 'Nome da empresa', entrada: nome, max: MAX_NOME, ajuda: 'Vazio usa o exemplo. Aparece no topo, no rodapé e no título da página.' });

  const cidade = el('input', { type: 'text', name: 'cidade', autocomplete: 'address-level2', value: estado.dados.cidade, placeholder: exemplo.cidade });
  const cCidade = campo({ rotulo: 'Cidade', entrada: cidade, max: MAX_CIDADE });
  const uf = el('select', { name: 'uf', autocomplete: 'address-level1' },
    el('option', { value: '' }, 'UF'), UFS.map((u) => el('option', { value: u, selected: estado.dados.uf === u }, u)));
  const cUf = campo({ rotulo: 'Estado', entrada: uf });

  const especialidades = Array.isArray(nicho.especialidades) ? nicho.especialidades : [];
  let cEspecialidade = null;
  if (especialidades.length > 1) {
    const sel = el('select', { name: 'especialidade' }, especialidades.map((e) => el('option', {
      value: e.id, selected: (estado.especialidade ?? especialidades[0].id) === e.id,
    }, e.nome)));
    cEspecialidade = campo({ rotulo: 'Especialidade', entrada: sel, ajuda: 'Ajusta os textos e o tipo de negócio informado ao Google.' });
  }

  const whatsapp = el('input', {
    type: 'tel', name: 'whatsapp', autocomplete: 'tel-national', inputmode: 'tel', value: mascaraTelefone(estado.dados.whatsapp), placeholder: exemplo.whatsapp,
  });
  const cWhats = campo({ rotulo: 'WhatsApp', entrada: whatsapp, ajuda: 'Com DDD. Os botões do site abrem uma conversa com este número.' });

  /* ---------- descrição para a IA: o centro do passo (é dela que saem os textos do site) */
  let iaLigada = true; // otimista até o GET /api/ia responder (evita o cartão "pular")
  const descricao = el('textarea', {
    id: 'descricao-negocio', name: 'descricao', rows: 7, maxlength: MAX_DESCRICAO, class: 'campo__entrada ia-cartao__descricao',
    'aria-labelledby': 'ia-cartao-titulo', 'aria-describedby': 'ia-cartao-ajuda ia-cartao-dica',
    placeholder: placeholderDescricao(estado.nicho, estado.especialidade ?? nicho.especialidades?.[0]?.id ?? null),
  });
  descricao.value = estado.descricao ?? '';
  const contadorDescricao = el('span', { class: 'ia-janela__contador', 'aria-hidden': 'true' });
  const dica = linhaDica('ia-cartao-dica');
  const exemplosVaga = el('div');
  const desenharExemplos = () => exemplosVaga.replaceChildren(chipsExemplos(descricao, {
    nicho: estado.nicho, especialidade: estado.especialidade ?? especialidades[0]?.id ?? null, aoMudar: aoDescrever, id: 'ia-cartao-exemplos',
  }));
  const cidadeNoTexto = () => dadosLimpos(estado.dados).cidade || exemplo.cidade || 'sua cidade';
  const seoCidade = el('span');
  const atualizarSeoCidade = () => {
    seoCidade.textContent = `SEO com palavras-chave de ${cidadeNoTexto()}`;
  };

  const cartaoIa = el('section', { class: 'ia-cartao', 'aria-labelledby': 'ia-cartao-titulo' },
    el('div', { class: 'ia-cartao__cab' },
      el('span', { class: 'ia-cartao__ico', 'aria-hidden': 'true' }, icone('brilho')),
      el('div', null,
        el('h2', { class: 'ia-cartao__titulo', id: 'ia-cartao-titulo' }, 'Conte sobre o seu negócio — a IA escreve o site'),
        el('p', { class: 'ia-cartao__sub', id: 'ia-cartao-ajuda' },
          'O que vocês fazem, os serviços principais, para quem e os diferenciais. Quanto mais detalhes verdadeiros, melhores os textos.'))),
    exemplosVaga,
    descricao,
    el('div', { class: 'ia-janela__rodape' }, dica.elemento, contadorDescricao),
    seletorTom({ valor: estado.tom, id: 'ia-cartao-tom', aoMudar: (t) => { estado.tom = t; guardar(); } }),
    el('div', { class: 'ia-cartao__listas' },
      el('div', null,
        el('p', { class: 'ia-cartao__lista-titulo' }, 'A IA escreve'),
        el('ul', { class: 'ia-cartao__lista ia-cartao__lista--sim' },
          el('li', null, icone('check'), 'Títulos e textos de cada seção'),
          el('li', null, icone('check'), 'Serviços e perguntas frequentes'),
          el('li', null, icone('check'), seoCidade))),
      el('div', null,
        el('p', { class: 'ia-cartao__lista-titulo' }, 'Ela não inventa'),
        el('ul', { class: 'ia-cartao__lista ia-cartao__lista--nao' },
          el('li', null, icone('fechar'), 'Números e resultados'),
          el('li', null, icone('fechar'), 'Depoimentos'),
          el('li', null, icone('fechar'), 'Nomes de pessoas')))));
  const cartaoSemIa = el('section', { class: 'ia-cartao ia-cartao--info', 'aria-labelledby': 'ia-info-titulo', hidden: true },
    el('div', { class: 'ia-cartao__cab' },
      el('span', { class: 'ia-cartao__ico', 'aria-hidden': 'true' }, icone('info')),
      el('div', null,
        el('h2', { class: 'ia-cartao__titulo', id: 'ia-info-titulo' }, 'Os textos virão de exemplo'),
        el('p', { class: 'ia-cartao__sub' },
          'A IA que escreve os textos não está ligada neste servidor. O site é criado com os textos de exemplo do seu tipo de negócio, e você troca o que quiser no editor.'))),
    el('p', { class: 'ia-cartao__nota' },
      'Para ligar: quem administra o servidor configura a chave do Gemini (', el('code', null, 'ia.chave'), ' no config.php ou a variável ',
      el('code', null, 'GEMINI_API_KEY'), '). Depois é só usar "Escrever com IA" no editor.'));

  function aoDescrever() {
    estado.descricao = descricao.value;
    guardar();
    contadorDescricao.textContent = `${[...descricao.value].length}/${MAX_DESCRICAO}`;
    dica.atualizar(descricao.value);
    atualizarBotaoGerar();
  }
  descricao.addEventListener('input', aoDescrever);
  iaDisponivel().then((sim) => {
    iaLigada = sim;
    cartaoIa.hidden = !sim;
    cartaoSemIa.hidden = sim;
    atualizarBotaoGerar();
  });

  /* ---------- cor */
  const cores = el('div', { class: 'cor-bolinhas', role: 'group', 'aria-labelledby': 'cor-rotulo' });
  const avisoCor = el('p', { class: 'aviso-inline aviso-inline--alerta', role: 'status', hidden: true },
    icone('alerta'), el('span', null, 'Cores muito claras podem perder leitura. Ajustamos os detalhes para manter o contraste.'));
  const corAtual = () => estado.cor ?? padrao;
  const livre = el('input', { type: 'color', value: corAtual(), 'aria-label': 'Escolher outra cor' });

  const rotuloLivre = el('label', { class: 'cor-bolinha cor-bolinha--livre', title: 'Outra cor' }, livre);

  /** Recria as bolinhas (muda quando entra/sai o logo). O seletor livre é sempre o mesmo elemento. */
  function desenharCores() {
    const lista = coresParaEscolher({ corLogo: logo?.cor ?? null, padrao });
    cores.replaceChildren(...lista.map((c) => {
      const b = el('button', {
        type: 'button', class: ['cor-bolinha', c.origem === 'logo' && 'cor-bolinha--logo'], style: { '--cor': c.cor, '--cor-check': corClaraDemais(c.cor) ? '#14161a' : '#fff' },
        'aria-pressed': 'false', 'aria-label': `${c.nome} (${c.cor})`, title: c.nome, dataset: { cor: c.cor },
      });
      b.addEventListener('click', () => escolherCor(c.cor));
      return b;
    }), rotuloLivre);
    marcarCor();
  }
  /** Marca a cor atual sem recriar nada (o seletor nativo continua aberto enquanto arrasta). */
  function marcarCor({ deLivre = false } = {}) {
    const atual = corAtual();
    let achou = false;
    for (const b of cores.querySelectorAll('button.cor-bolinha')) {
      const ativa = b.dataset.cor === atual;
      achou ||= ativa;
      b.setAttribute('aria-pressed', String(ativa));
    }
    rotuloLivre.dataset.ativa = String(!achou);
    rotuloLivre.style.setProperty('--cor', atual);
    rotuloLivre.style.setProperty('--cor-check', corClaraDemais(atual) ? '#14161a' : '#fff');
    if (!deLivre) livre.value = atual;
    livre.setAttribute('aria-label', achou ? 'Escolher outra cor' : `Outra cor escolhida (${atual}). Escolher outra cor`);
    avisoCor.hidden = !corClaraDemais(atual);
  }
  function escolherCor(cor, { focar = true, deLivre = false } = {}) {
    const c = normalizarCor(cor);
    if (!c) return;
    estado.cor = c;
    guardar();
    marcarCor({ deLivre });
    if (focar) cores.querySelector('[aria-pressed="true"]')?.focus?.();
    atualizarPrevia();
  }
  livre.addEventListener('input', () => escolherCor(livre.value, { focar: false, deLivre: true }));

  /* ---------- logo */
  const arquivo = el('input', { type: 'file', accept: ACEITA_LOGO, class: 'sr-only', id: 'logo-arquivo', tabindex: '-1' });
  const botaoLogo = el('button', { type: 'button', class: 'btn btn--pequeno' }, icone('enviar'), 'Enviar logo');
  const infoLogo = el('div', { class: 'logo-escolha__info' });
  const erroLogo = el('p', { class: 'campo__erro', role: 'alert', hidden: true });
  botaoLogo.addEventListener('click', () => arquivo.click());

  function desenharLogo() {
    if (logo) {
      const remover = el('button', { type: 'button', class: 'btn btn--pequeno btn--fantasma btn--perigo-suave' }, icone('lixeira'), 'Remover');
      remover.addEventListener('click', () => {
        definirLogo(null);
        desenharLogo();
        desenharCores();
        atualizarPrevia();
        botaoLogo.focus();
      });
      botaoLogo.replaceChildren(icone('enviar'), 'Trocar logo');
      infoLogo.replaceChildren(
        el('img', { class: 'logo-escolha__img', src: logo.url, alt: 'Logo escolhido' }),
        el('span', { class: 'logo-escolha__nome', title: logo.arquivo.name }, logo.arquivo.name),
        remover);
    } else {
      botaoLogo.replaceChildren(icone('enviar'), 'Enviar logo');
      infoLogo.replaceChildren(el('span', { class: 'campo__ajuda' }, 'Sem logo, o site usa a inicial num quadrado na cor principal e o nome.'));
    }
  }

  arquivo.addEventListener('change', async () => {
    const f = arquivo.files?.[0];
    arquivo.value = '';
    if (!f) return;
    const problema = problemaArquivoLogo(f);
    erroLogo.textContent = problema ?? '';
    erroLogo.hidden = !problema;
    if (problema) return;
    const url = URL.createObjectURL(f);
    const dims = await new Promise((resolver) => {
      const img = new Image();
      img.onload = () => resolver({ largura: img.naturalWidth, altura: img.naturalHeight });
      img.onerror = () => resolver(null);
      img.src = url;
    });
    if (!dims) {
      URL.revokeObjectURL(url);
      erroLogo.textContent = 'Não foi possível abrir esta imagem. Tente outro arquivo.';
      erroLogo.hidden = false;
      return;
    }
    const cor = await corDominanteDaImagem(f);
    definirLogo({ arquivo: f, url, cor, ...dims });
    desenharLogo();
    desenharCores();
    atualizarPrevia();
    if (cor) aviso('Sugerimos a cor do seu logo: ela é a primeira bolinha das cores.', { duracao: 5000 });
  });

  /* ---------- prévia ao vivo */
  const siteVivo = el('div', { class: 'previa-moldura__site' });
  const moldura = el('div', { class: 'previa-moldura previa-viva', tabindex: '0', role: 'region', 'aria-label': 'Prévia ao vivo do site (role para ver o site inteiro)' }, siteVivo);
  let quadro = 0;
  function atualizarPrevia() {
    if (quadro) return;
    quadro = requestAnimationFrame(() => {
      quadro = 0;
      if (!siteVivo.isConnected) return;
      try {
        {
          const p = previaComFotos(comLogoLocal(documentoDoAssistente(lib, estado, { previa: true })), lib, midiaComLogo());
          renderizarPrevia(siteVivo, p.doc, lib, { midia: p.midia });
        }
      } catch (e) {
        console.error(e);
      }
    });
  }

  /* ---------- eventos dos campos */
  const ligar = (entrada, campoEstado, transformar = (v) => v) => {
    entrada.addEventListener('input', () => {
      estado.dados[campoEstado] = transformar(entrada.value);
      guardar();
      atualizarPrevia();
    });
  };
  ligar(nome, 'nome');
  ligar(cidade, 'cidade');
  cidade.addEventListener('input', atualizarSeoCidade);
  uf.addEventListener('change', () => {
    estado.dados.uf = uf.value;
    guardar();
    atualizarPrevia();
  });
  cEspecialidade?.entrada.addEventListener('change', () => {
    estado.especialidade = cEspecialidade.entrada.value;
    guardar();
    desenharExemplos();
    descricao.placeholder = placeholderDescricao(estado.nicho, estado.especialidade);
    atualizarPrevia();
  });
  whatsapp.addEventListener('input', () => {
    const antes = whatsapp.value;
    const posicaoDoFim = antes.length - (whatsapp.selectionEnd ?? antes.length);
    const mascarado = mascaraTelefone(antes);
    if (mascarado !== antes) {
      whatsapp.value = mascarado;
      const pos = Math.max(0, mascarado.length - posicaoDoFim);
      try {
        whatsapp.setSelectionRange(pos, pos);
      } catch {
        /* alguns tipos não aceitam seleção */
      }
    }
    estado.dados.whatsapp = mascarado;
    guardar();
    if (tocouWhatsapp) cWhats.definirErro(problemaWhatsapp(mascarado));
    atualizarPrevia();
  });
  whatsapp.addEventListener('blur', () => {
    tocouWhatsapp = whatsapp.value !== '' || tocouWhatsapp;
    if (tocouWhatsapp) cWhats.definirErro(problemaWhatsapp(whatsapp.value));
  });

  /* ---------- gerar */
  const gerar = el('button', { type: 'submit', class: 'btn btn--primario btn--grande dados-negocio__gerar' });
  function atualizarBotaoGerar() {
    const rotulo = rotuloGerar({ ia: iaLigada, descricao: descricao.value });
    const comIa = rotulo !== rotuloGerar();
    if (gerar.dataset.rotulo === rotulo) return;
    gerar.dataset.rotulo = rotulo;
    gerar.classList.toggle('btn--ia', comIa);
    gerar.replaceChildren(...[comIa ? icone('brilho') : null, rotulo, icone('avancar')].filter(Boolean));
  }
  const progresso = el('p', { class: 'campo__ajuda', role: 'status', 'aria-live': 'polite' });
  const form = el('form', { class: 'formulario dados-negocio__form', novalidate: true, 'aria-labelledby': 'dados-titulo' },
    cartaoIa,
    cartaoSemIa,
    el('h2', { class: 'dados-negocio__secao' }, 'Contato e visual'),
    cNome.elemento,
    el('div', { class: 'campo__linha' }, cCidade.elemento, cUf.elemento),
    cEspecialidade?.elemento,
    cWhats.elemento,
    el('div', { class: 'campo' },
      el('span', { class: 'campo__rotulo', id: 'cor-rotulo' }, 'Cor principal'),
      cores,
      el('p', { class: 'campo__ajuda' }, 'Os tons claros, escuros e a cor do texto dos botões são gerados a partir dela.'),
      avisoCor),
    el('div', { class: 'campo' },
      el('span', { class: 'campo__rotulo', id: 'logo-rotulo' }, 'Logo', el('span', { class: 'campo__opcional' }, ' (opcional)')),
      el('div', { class: 'logo-escolha', role: 'group', 'aria-labelledby': 'logo-rotulo' }, botaoLogo, infoLogo, arquivo),
      el('p', { class: 'campo__ajuda' }, 'PNG, JPG, SVG ou WebP, até 5 MB.'),
      erroLogo),
    el('div', { class: 'dados-negocio__acoes' },
      el('a', { class: 'btn btn--grande', href: '#/novo/modelo' }, 'Voltar'),
      gerar),
    progresso);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const erros = errosDadosNegocio({ nome: nome.value, cidade: cidade.value, whatsapp: whatsapp.value });
    cNome.definirErro(erros.nome ?? null);
    cCidade.definirErro(erros.cidade ?? null);
    cWhats.definirErro(erros.whatsapp ?? null);
    tocouWhatsapp = true;
    const primeiro = erros.nome ? nome : erros.cidade ? cidade : erros.whatsapp ? whatsapp : null;
    if (primeiro) {
      primeiro.focus();
      return;
    }
    gerar.disabled = true;
    gerar.setAttribute('aria-busy', 'true');
    progresso.textContent = 'Criando o seu site…';
    let criado = null;
    try {
      const r = await api.post('/sites', corpoCriacao(lib, estado));
      criado = r.site;
    } catch (e) {
      gerar.disabled = false;
      gerar.removeAttribute('aria-busy');
      progresso.textContent = '';
      if (e?.status === 401) return;
      aviso(e?.mensagem ?? 'Não foi possível criar o site. Tente de novo.', { tipo: 'erro' });
      return;
    }
    let falhaLogo = null;
    if (logo) {
      try {
        progresso.textContent = 'Enviando o logo…';
        const fd = new FormData();
        fd.append('arquivo', logo.arquivo, logo.arquivo.name);
        fd.append('site_id', String(criado.id));
        fd.append('tipo', 'logo');
        const m = await api.enviarArquivo('/media', fd, (fracao) => {
          progresso.textContent = `Enviando o logo… ${Math.round(fracao * 100)}%`;
        });
        const documento = { ...criado.documento, dados: { ...criado.documento.dados, logo: m.midia.id } };
        await api.put(`/sites/${criado.id}`, { revisao: criado.revisao, documento });
      } catch (e) {
        if (e?.status === 401) return;
        falhaLogo = e?.mensagem ?? 'Não foi possível enviar o logo.';
      }
    }
    let resultadoIa = null;
    let falhaIa = null;
    const textoIa = iaLigada ? descricao.value.trim() : '';
    if (textoIa.length >= MIN_DESCRICAO) {
      try {
        guardarDescricao(criado.id, textoIa);
        guardarTom(criado.id, estado.tom);
        progresso.textContent = 'A IA está escrevendo e revisando os textos do seu site… isso leva de 15 a 60 segundos.';
        const patch = await pedirConteudo(criado.id, textoIa, 'site', estado.tom);
        const atual = (await api.get(`/sites/${criado.id}`)).site;
        await api.put(`/sites/${criado.id}`, { revisao: atual.revisao, documento: aplicarPatchIa(atual.documento, patch) });
        resultadoIa = patch;
      } catch (e) {
        if (e?.status === 401) return;
        falhaIa = e?.mensagem ?? 'A IA não respondeu.';
      }
    }
    recomecar();
    navegar(`#/site/${criado.id}`);
    aviso('Seu site está pronto. Clique em qualquer texto para editar.', { tipo: 'ok', duracao: 8000 });
    if (resultadoIa) {
      aviso(resumoResultado(resultadoIa), { tipo: 'ok', duracao: 10000 });
      for (const a of resultadoIa.avisos ?? []) aviso(a, { duracao: 10000 });
    }
    if (falhaIa) aviso(`O site foi criado com os textos prontos do nicho, mas a IA falhou: ${falhaIa} Tente de novo pelo botão "Escrever com IA" no editor.`, { tipo: 'erro', duracao: 12000 });
    if (falhaLogo) aviso(`O site foi criado, mas o logo não foi enviado: ${falhaLogo} Envie de novo na aba Dados.`, { tipo: 'erro', duracao: 10000 });
  });

  alvo.replaceChildren(el('div', { class: 'pagina assistente' },
    indicadorPassos('dados'),
    el('a', { class: 'link-voltar', href: '#/novo/modelo' }, icone('voltar'), 'Trocar modelo'),
    el('div', { class: 'cab-assistente' },
      el('h1', { class: 'titulo-pagina titulo-assistente', id: 'dados-titulo' }, 'Quase pronto: o seu negócio'),
      el('p', { class: 'subtitulo' }, 'Com estas respostas o site fica pronto. Campo vazio usa o exemplo, e tudo pode ser mudado depois.')),
    el('div', { class: 'dados-negocio' },
      form,
      el('div', { class: 'dados-negocio__previa' },
        el('p', { class: 'dados-negocio__previa-rotulo', 'aria-hidden': 'true' }, 'Prévia ao vivo ', el('span', null, '· role para ver o site inteiro')),
        moldura))));

  desenharCores();
  desenharLogo();
  desenharExemplos();
  atualizarSeoCidade();
  aoDescrever();
  limpeza.add(escalar(moldura, siteVivo, LARGURA_COMPUTADOR));
  {
          const p = previaComFotos(comLogoLocal(documentoDoAssistente(lib, estado, { previa: true })), lib, midiaComLogo());
          renderizarPrevia(siteVivo, p.doc, lib, { midia: p.midia });
        }
  limpeza.add(() => cancelAnimationFrame(quadro));
  if (estado.dados.whatsapp) cWhats.definirErro(problemaWhatsapp(estado.dados.whatsapp));
}

/* ================================================================== montar */

export async function montar(alvo, params = {}) {
  const limpeza = coletor();
  alvo.replaceChildren(el('div', { class: 'carregando-tela' }, carregando('Preparando os modelos…')));
  let lib;
  try {
    lib = await carregarBiblioteca();
  } catch (e) {
    if (e?.status === 401) throw e;
    alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
      telaErro(e?.mensagem ?? 'Não foi possível carregar os modelos.', () => navegar(location.hash))));
    return { desmontar: () => limpeza.desligar() };
  }
  const siteId = params.siteId ?? null;
  if (siteId !== null) await passoModelo(alvo, lib, limpeza, { siteId });
  else if (params.passo === 'modelo') await passoModelo(alvo, lib, limpeza);
  else if (params.passo === 'dados') passoDados(alvo, lib, limpeza);
  else await passoNicho(alvo, lib, limpeza);
  return { desmontar: () => limpeza.desligar() };
}
