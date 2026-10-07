// Aplicativo do editor — roteamento por hash (contrato §9 e §11.4).
//
//   #/entrar  #/redefinir?token=…            login.mjs        (públicas)
//   #/sites                                  sites.mjs        painel
//   #/novo  #/novo/modelo  #/novo/dados      assistente.mjs   assistente (3 passos)
//   #/site/{id}/modelo                       assistente.mjs   trocar o modelo de um site existente
//   #/site/{id}                              editor/editor.mjs
//   #/site/{id}/leads                        leads.mjs
//
// Cada tela exporta `montar(alvo, params) → { desmontar?() }`. Antes de montar a próxima, o app
// chama `desmontar()` da anterior. As rotas fechadas conferem a sessão em /api/auth/eu (401 →
// #/entrar, voltando depois para onde a pessoa estava). O cabeçalho (marca, navegação, usuário
// e "Sair") aparece nas telas comuns; login e editor ocupam a tela inteira.
//
// Eventos aceitos de outros módulos:
//   window "rk:sessao"  detail { usuario }   — o login avisa quem entrou (evita outro /auth/eu)

import { api } from './api.mjs';
import { el, anexar, icone, aviso, navegar, carregando, telaErro, fecharJanelas, fecharAvisosComAcao } from './ui.mjs';

const TITULO_APP = 'Construtor Rankly';
const CHAVE_VOLTAR = 'rk:voltar';
const CHAVE_TEMA = 'rk:tema';

/** Tabela de rotas: caminho (sem "#") → módulo, layout e parâmetros. */
export const ROTAS = [
  { re: /^\/entrar$/, modulo: './login.mjs', layout: 'login', publica: true, params: () => ({ modo: 'entrar' }) },
  { re: /^\/esqueci$/, modulo: './login.mjs', layout: 'login', publica: true, params: () => ({ modo: 'esqueci' }) },
  {
    re: /^\/redefinir$/, modulo: './login.mjs', layout: 'login', publica: true, sempre: true,
    params: (_m, q) => ({ modo: 'redefinir', token: q.get('token') ?? '' }),
  },
  { re: /^\/sites$/, modulo: './sites.mjs', layout: 'app', nav: 'sites', params: () => ({}) },
  { re: /^\/novo$/, modulo: './assistente.mjs', layout: 'app', nav: 'novo', params: () => ({ passo: 'nicho' }) },
  { re: /^\/novo\/modelo$/, modulo: './assistente.mjs', layout: 'app', nav: 'novo', params: () => ({ passo: 'modelo' }) },
  { re: /^\/novo\/dados$/, modulo: './assistente.mjs', layout: 'app', nav: 'novo', params: () => ({ passo: 'dados' }) },
  {
    re: /^\/site\/(\d{1,12})\/modelo$/, modulo: './assistente.mjs', layout: 'app', nav: 'sites',
    params: (m) => ({ passo: 'modelo', siteId: Number(m[1]) }),
  },
  { re: /^\/site\/(\d{1,12})\/leads$/, modulo: './leads.mjs', layout: 'app', nav: 'sites', params: (m) => ({ siteId: Number(m[1]) }) },
  { re: /^\/site\/(\d{1,12})$/, modulo: './editor/editor.mjs', layout: 'editor', editor: true, params: (m) => ({ siteId: Number(m[1]) }) },
];

/** Separa "#/novo/modelo?x=1" em { caminho: "/novo/modelo", query: URLSearchParams }. */
export function lerHash(hash) {
  let h = String(hash ?? '').replace(/^#/, '');
  if (h === '' || h === '/') h = '/sites';
  if (!h.startsWith('/')) h = `/${h}`;
  const i = h.indexOf('?');
  const caminho = (i >= 0 ? h.slice(0, i) : h).replace(/\/+$/, '') || '/sites';
  const query = new URLSearchParams(i >= 0 ? h.slice(i + 1) : '');
  return { caminho, query };
}

/** Rota que atende o hash (ou null). Devolve { rota, params }. */
export function resolverRota(hash) {
  const { caminho, query } = lerHash(hash);
  for (const rota of ROTAS) {
    const m = caminho.match(rota.re);
    if (m) return { rota, params: { ...rota.params(m, query), query }, caminho };
  }
  return null;
}

/* ------------------------------------------------------------------ estado do app */

let usuario = null;
let sessaoConferida = false;
let telaAtual = null; // { desmontar? }
let navegacao = 0;
let cabecalho = null;
let conteudo = null;

/** Usuário logado (ou null). */
export function usuarioAtual() {
  return usuario;
}

/** Anuncia uma mensagem aos leitores de tela (região aria-live do index.html). */
export function anunciar(mensagem) {
  const regiao = document.getElementById('anuncio');
  if (!regiao) return;
  regiao.textContent = '';
  // Um pequeno atraso garante que a mudança seja percebida mesmo com o mesmo texto.
  setTimeout(() => {
    regiao.textContent = mensagem;
  }, 60);
}

function lerArmazenado(armazenamento, chave) {
  try {
    return armazenamento.getItem(chave);
  } catch {
    return null;
  }
}

function gravarArmazenado(armazenamento, chave, valor) {
  try {
    if (valor === null) armazenamento.removeItem(chave);
    else armazenamento.setItem(chave, valor);
  } catch {
    /* modo privado ou armazenamento cheio: segue sem guardar */
  }
}

async function conferirSessao({ forcar = false } = {}) {
  if (sessaoConferida && !forcar) return usuario;
  try {
    const r = await api.get('/auth/eu');
    usuario = r?.usuario ?? null;
  } catch (e) {
    if (e?.status === 401) usuario = null;
    else throw e;
  }
  sessaoConferida = true;
  return usuario;
}

/* ------------------------------------------------------------------ tema */

const TEMAS = ['sistema', 'claro', 'escuro'];
const ROTULO_TEMA = { sistema: 'Tema do sistema', claro: 'Tema claro', escuro: 'Tema escuro' };

function temaAtual() {
  const t = lerArmazenado(localStorage, CHAVE_TEMA);
  return TEMAS.includes(t) ? t : 'sistema';
}

function aplicarTema(tema) {
  const raiz = document.documentElement;
  if (tema === 'claro' || tema === 'escuro') raiz.dataset.tema = tema;
  else delete raiz.dataset.tema;
}

/* ------------------------------------------------------------------ cabeçalho */

function iniciais(nome) {
  const partes = String(nome ?? '').trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return '?';
  const a = partes[0][0] ?? '';
  const b = partes.length > 1 ? partes[partes.length - 1][0] : '';
  return (a + b).toUpperCase();
}

function desenharCabecalho(rota) {
  if (!cabecalho) return;
  cabecalho.replaceChildren();
  // aria-label: no celular o texto some e fica só o ícone.
  const link = (hash, rotulo, nomeIcone, ativo) => el('a', {
    class: 'app-cab__link', href: hash, 'aria-current': ativo ? 'page' : null, 'aria-label': rotulo, title: rotulo,
  }, icone(nomeIcone), el('span', { 'aria-hidden': 'true' }, rotulo));

  let tema = temaAtual();
  const botaoTema = el('button', {
    type: 'button', class: 'icone-btn', 'aria-label': `${ROTULO_TEMA[tema]} (mudar)`, title: ROTULO_TEMA[tema],
  }, icone(tema === 'escuro' ? 'lua' : tema === 'claro' ? 'sol' : 'meiaLua'));
  botaoTema.addEventListener('click', () => {
    tema = TEMAS[(TEMAS.indexOf(tema) + 1) % TEMAS.length];
    gravarArmazenado(localStorage, CHAVE_TEMA, tema === 'sistema' ? null : tema);
    aplicarTema(tema);
    botaoTema.replaceChildren(icone(tema === 'escuro' ? 'lua' : tema === 'claro' ? 'sol' : 'meiaLua'));
    botaoTema.setAttribute('aria-label', `${ROTULO_TEMA[tema]} (mudar)`);
    botaoTema.title = ROTULO_TEMA[tema];
    anunciar(ROTULO_TEMA[tema]);
  });

  const sair = el('button', { type: 'button', class: 'btn btn--fantasma btn--pequeno', 'aria-label': 'Sair da conta', title: 'Sair da conta' },
    icone('sair'), el('span', { class: 'app-cab__sair', 'aria-hidden': 'true' }, 'Sair'));
  sair.addEventListener('click', async () => {
    sair.disabled = true;
    try {
      await api.post('/auth/logout');
    } catch {
      /* mesmo com erro, a sessão local é encerrada */
    }
    usuario = null;
    sessaoConferida = true;
    gravarArmazenado(sessionStorage, CHAVE_VOLTAR, null);
    navegar('#/entrar');
    aviso('Você saiu da sua conta.', { tipo: 'ok' });
  });

  anexar(cabecalho,
    el('a', { class: 'app-marca', href: '#/sites', 'aria-label': 'Construtor Rankly — meus sites' },
      el('span', { class: 'app-marca__r', 'aria-hidden': 'true' }, 'R'),
      el('span', { class: 'app-marca__nome' }, 'Construtor Rankly')),
    el('nav', { class: 'app-cab__nav', 'aria-label': 'Principal' },
      link('#/sites', 'Meus sites', 'grade', rota?.nav === 'sites'),
      link('#/novo', 'Novo site', 'mais', rota?.nav === 'novo')),
    el('div', { class: 'app-cab__usuario' },
      botaoTema,
      usuario ? el('span', { class: 'app-cab__avatar', 'aria-hidden': 'true' }, iniciais(usuario.nome)) : null,
      usuario ? el('span', { class: 'app-cab__nome', title: usuario.email }, usuario.nome) : null,
      sair),
  );
}

/* ------------------------------------------------------------------ roteamento */

async function desmontarAtual() {
  const tela = telaAtual;
  telaAtual = null;
  // Janelas e avisos com ação ("Desfazer") pertencem à tela que sai.
  if (tela) {
    fecharJanelas('navegacao');
    fecharAvisosComAcao();
  }
  if (tela && typeof tela.desmontar === 'function') {
    try {
      await tela.desmontar();
    } catch (e) {
      console.error('Erro ao desmontar a tela:', e);
    }
  }
}

function telaEditorIndisponivel(alvo, params, erro) {
  console.error('Editor indisponível:', erro);
  document.body.dataset.layout = 'app';
  cabecalho.hidden = false;
  desenharCabecalho({ nav: 'sites' });
  const tentar = el('button', { type: 'button', class: 'btn btn--primario', onclick: () => navegar(`#/site/${params.siteId}`) }, 'Tentar de novo');
  alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
    el('div', { class: 'vazio' },
      el('div', { class: 'vazio__ico' }, icone('editar')),
      el('h1', { class: 'vazio__titulo' }, 'Estamos preparando o editor'),
      el('p', { class: 'vazio__texto' },
        'Seu site está salvo. O editor ainda está carregando ou ficou indisponível por um instante. '
        + 'Tente de novo em alguns segundos.'),
      el('div', { class: 'continuar__acoes' },
        tentar,
        el('a', { class: 'btn', href: '#/sites' }, 'Voltar aos meus sites')))));
  document.title = `Editor · ${TITULO_APP}`;
  tentar.focus({ preventScroll: true });
}

async function rotear() {
  const minha = ++navegacao;
  const achada = resolverRota(location.hash);
  if (!achada) {
    navegar('#/sites', { substituir: true });
    return;
  }
  const { rota, params } = achada;

  // Sessão: rotas fechadas exigem login; login com sessão ativa vai para o painel.
  let quem = null;
  try {
    quem = await conferirSessao();
  } catch (e) {
    if (minha !== navegacao) return;
    await desmontarAtual();
    document.body.dataset.layout = 'app';
    cabecalho.hidden = true;
    conteudo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
      telaErro(e?.mensagem ?? 'Não foi possível falar com o servidor.', () => rotear())));
    return;
  }
  if (minha !== navegacao) return;
  if (!rota.publica && !quem) {
    gravarArmazenado(sessionStorage, CHAVE_VOLTAR, location.hash);
    navegar('#/entrar', { substituir: true });
    return;
  }
  if (rota.publica && !rota.sempre && quem) {
    navegar('#/sites', { substituir: true });
    return;
  }

  await desmontarAtual();
  if (minha !== navegacao) return;

  document.body.dataset.layout = rota.layout;
  cabecalho.hidden = rota.layout !== 'app';
  if (rota.layout === 'app') desenharCabecalho(rota);
  document.title = TITULO_APP;

  const alvo = el('div', { class: 'app-tela' });
  conteudo.replaceChildren(el('div', { class: 'carregando-tela' }, carregando()));

  let modulo;
  try {
    modulo = await import(rota.modulo);
    if (typeof modulo.montar !== 'function') throw new Error(`O módulo ${rota.modulo} não exporta montar().`);
  } catch (e) {
    if (minha !== navegacao) return;
    if (rota.editor) {
      conteudo.replaceChildren(alvo);
      telaEditorIndisponivel(alvo, params, e);
      return;
    }
    console.error(e);
    conteudo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
      telaErro('Não foi possível carregar esta tela. Verifique a conexão e tente de novo.', () => rotear())));
    return;
  }
  if (minha !== navegacao) return;

  conteudo.replaceChildren(alvo);
  const focoAntes = document.activeElement;
  try {
    const tela = await modulo.montar(alvo, { ...params, usuario: quem });
    if (minha !== navegacao) {
      // Outra navegação começou enquanto esta montava: desfaz.
      try {
        await tela?.desmontar?.();
      } catch (e) {
        console.error(e);
      }
      return;
    }
    telaAtual = tela ?? null;
  } catch (e) {
    if (minha !== navegacao) return;
    if (e?.status === 401) return; // o ouvinte de 401 já levou ao login
    console.error(e);
    if (rota.editor) {
      telaEditorIndisponivel(alvo, params, e);
      return;
    }
    alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
      telaErro(e?.mensagem ?? 'Algo deu errado ao abrir esta tela.', () => rotear())));
    return;
  }

  // Foco: se a tela não escolheu um foco, leva para o conteúdo (leitores de tela leem o título).
  if (document.activeElement === focoAntes || document.activeElement === document.body || !conteudo.contains(document.activeElement)) {
    const titulo = conteudo.querySelector('h1');
    if (titulo && rota.layout !== 'editor') {
      titulo.tabIndex = -1;
      titulo.focus({ preventScroll: true });
    } else if (rota.layout !== 'editor') {
      conteudo.focus({ preventScroll: true });
    }
  }
  if (rota.layout !== 'editor') window.scrollTo({ top: 0 });
  anunciar(document.title);
}

/** Para onde voltar depois do login (e esquece o pedido). */
export function destinoDepoisDoLogin() {
  const voltar = lerArmazenado(sessionStorage, CHAVE_VOLTAR);
  gravarArmazenado(sessionStorage, CHAVE_VOLTAR, null);
  if (voltar && resolverRota(voltar) && !resolverRota(voltar).rota.publica) return voltar;
  return '#/sites';
}

function iniciar() {
  cabecalho = document.getElementById('app-cab');
  conteudo = document.getElementById('conteudo');
  aplicarTema(temaAtual());

  // "Pular para o conteúdo" sem mexer no hash (que é a rota).
  document.getElementById('pular-conteudo')?.addEventListener('click', (ev) => {
    ev.preventDefault();
    const alvo = conteudo.querySelector('h1') ?? conteudo;
    if (alvo !== conteudo) alvo.tabIndex = -1;
    alvo.focus();
  });

  window.addEventListener('rk:sessao', (ev) => {
    usuario = ev.detail?.usuario ?? null;
    sessaoConferida = true;
  });

  api.aoNaoAutenticado(() => {
    if (!usuario && sessaoConferida) return;
    usuario = null;
    sessaoConferida = true;
    if (!resolverRota(location.hash)?.rota.publica) {
      gravarArmazenado(sessionStorage, CHAVE_VOLTAR, location.hash);
      aviso('Sua sessão expirou. Entre de novo para continuar.', { tipo: 'erro' });
      navegar('#/entrar', { substituir: true });
    }
  });

  window.addEventListener('hashchange', () => rotear());
  rotear();
}

if (typeof document !== 'undefined' && typeof window !== 'undefined' && document.getElementById('conteudo')) {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
}
