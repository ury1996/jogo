// Editor do site (#/site/{id}) — contrato §9 e §11.4; PDF cap. 7 + melhorias.
//
//   export async function montar(alvo, { siteId }) → { desmontar() }
//
// Peças (todas recebem o mesmo contexto `ed`):
//   barra.mjs           barra superior
//   painel.mjs          abas Seções / Estilo / Dados / Config.
//   tela.mjs            site em escala + camada de ferramentas
//   edicao-texto.mjs    textos editáveis no próprio site
//   fotos.mjs           envio de fotos, remover, texto alternativo
//   seletor-icones.mjs  escolha manual de ícones
//   galerias.mjs        opções da seção, adicionar seção, trocar modelo
//   publicar.mjs        checklist, publicação e reversão
//   versoes.mjs         histórico de publicações
//   operacoes.mjs       lógica pura (testada no Node)
//
// O documento só muda por `ed.aplicar(fn)` (→ EstadoEditor.aplicar: histórico de 80 passos,
// salvamento automático, conflito). Cada mudança re-renderiza a tela (atualização mínima do
// DOM) e o painel — menos durante a digitação num texto do site, que só atualiza o próprio campo.

import { el, aviso, telaErro, carregando, navegar } from '../ui.mjs';
import { EstadoEditor } from '../estado.mjs';
import { carregarBiblioteca, injetarCssSite } from '../biblioteca.mjs';
import { campoAutomatico } from '../compartilhado/icones.mjs';
import * as op from './operacoes.mjs';
import { criarTela } from './tela.mjs';
import { criarBarra, criarFaixaIa } from './barra.mjs';
import { criarPainel } from './painel.mjs';
import { ligarEdicaoTexto } from './edicao-texto.mjs';
import { ligarFotos } from './fotos.mjs';
import { abrirSeletorIcone } from './seletor-icones.mjs';
import { abrirGaleriaOpcoes, abrirGaleriaAdicionar, abrirTrocarModelo } from './galerias.mjs';
import { fluxoPublicar } from './publicar.mjs';
import { abrirVersoes } from './versoes.mjs';
import { iaDisponivel, abrirJanelaIa } from '../ia.mjs';

const ID_CSS_TELA = 'ed-css-tela';

/** Carrega css/tela.css uma vez (o index.html pode já trazer; aqui é garantia). */
function garantirCssTela() {
  if (document.getElementById(ID_CSS_TELA) || document.querySelector('link[href$="css/tela.css"]')) return;
  const url = new URL('../../css/tela.css', import.meta.url);
  document.head.append(el('link', { rel: 'stylesheet', href: url.pathname, id: ID_CSS_TELA }));
}

function ehCampoDeTexto(t) {
  if (!(t instanceof Element)) return false;
  if (t.isContentEditable) return true;
  const tag = t.tagName;
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (tag === 'INPUT') return !['checkbox', 'radio', 'button', 'submit', 'reset', 'color', 'range', 'file'].includes(t.type);
  return false;
}

export async function montar(alvo, { siteId } = {}) {
  garantirCssTela();
  alvo.replaceChildren(el('div', { class: 'carregando-tela' }, carregando('Abrindo o editor…')));

  let lib;
  let estado;
  try {
    [lib, estado] = await Promise.all([carregarBiblioteca(), EstadoEditor.carregar(siteId)]);
  } catch (e) {
    if (e?.status === 401) throw e; // o app leva ao login
    const mensagem = e?.status === 404
      ? 'Este site não existe ou você não tem acesso a ele.'
      : (e?.mensagem ?? 'Não foi possível abrir o site.');
    // "Tentar de novo" refaz a rota (o app desmonta esta tela e monta outra).
    alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' }, telaErro(mensagem, () => navegar(location.hash))));
    return { desmontar() { alvo.replaceChildren(); } };
  }
  injetarCssSite(lib);

  /* ---------------------------------------------------------------- contexto */

  const ouvintes = new Map();
  const camposAutomaticos = new Map();
  const ed = {
    lib,
    estado,
    siteId: estado.siteId,
    site: { ...(estado.site ?? {}) },
    selecionada: null,
    hover: null,
    modo: 'computador',
    visualizando: false,
    imagensTemporarias: {},
    envios: new Map(),
    suprimirRender: 0,
    contador: null,
    ultimoPreparo: null,

    /** Aplica fn(doc) → novo doc como um passo do histórico. → true se mudou. */
    aplicar(fn, opcoes = {}) {
      const atual = estado.doc;
      return estado.aplicar(() => fn(atual), opcoes);
    },
    ao(evento, fn) {
      if (!ouvintes.has(evento)) ouvintes.set(evento, new Set());
      ouvintes.get(evento).add(fn);
      return () => ouvintes.get(evento)?.delete(fn);
    },
    emitir(evento, dados) {
      for (const fn of [...(ouvintes.get(evento) ?? [])]) fn(dados);
      if (evento === 'site') {
        barra.atualizar();
        painel.atualizar();
      }
    },
    campoAutomatico(lista) {
      if (!camposAutomaticos.has(lista)) camposAutomaticos.set(lista, campoAutomatico(lib, lista));
      return camposAutomaticos.get(lista);
    },
    anunciar(msg) {
      vivo.textContent = '';
      requestAnimationFrame(() => { vivo.textContent = msg; });
    },
    renderizar() {
      tela.renderizar();
    },
    confirmarEdicao() {
      texto?.confirmar();
    },
  };

  /* ---------------------------------------------------------------- peças */

  const vivo = el('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
  const tela = criarTela(ed);
  ed.tela = tela;
  const barra = criarBarra(ed);
  const painel = criarPainel(ed);
  ed.painel = painel;
  const faixaIa = criarFaixaIa(ed);
  const raiz = el('div', { class: 'editor ed-editor' }, barra.elemento, painel.elemento, faixaIa.elemento, tela.elemento, vivo);
  alvo.replaceChildren(raiz);
  const texto = ligarEdicaoTexto(ed);
  const fotos = ligarFotos(ed);

  /* ---------------------------------------------------------------- ações */

  function selecionar(indice, { rolar = false } = {}) {
    const valido = Number.isInteger(indice) && indice >= 0 && indice < estado.doc.secoes.length ? indice : null;
    const mudou = valido !== ed.selecionada;
    ed.selecionada = valido;
    if (mudou) {
      painel.secoes.atualizar();
      if (valido !== null) painel.secoes.revelar(valido);
    }
    if (rolar && valido !== null) tela.rolarPara(valido);
    tela.posicionar();
  }

  function desfazer() {
    texto.confirmar();
    const r = estado.desfazer();
    if (r) aviso(r.rotulo ? `Desfeito: ${r.rotulo.toLowerCase()}.` : 'Alteração desfeita.', { duracao: 2500 });
    else ed.anunciar('Nada para desfazer.');
  }

  function refazer() {
    texto.confirmar();
    const r = estado.refazer();
    if (r) aviso(r.rotulo ? `Refeito: ${r.rotulo.toLowerCase()}.` : 'Alteração refeita.', { duracao: 2500 });
    else ed.anunciar('Nada para refazer.');
  }

  function nomeDe(indice) {
    const s = estado.doc.secoes[indice];
    return s ? op.nomeSecao(lib, s.tipo) : 'seção';
  }

  Object.assign(ed, {
    selecionar,
    desfazer,
    refazer,
    abrirFoto: (chave) => fotos.abrirFoto(chave),
    abrirSeletorIcone: (chave) => abrirSeletorIcone(ed, chave),
    abrirGaleriaOpcoes: (indice) => abrirGaleriaOpcoes(ed, indice),
    abrirAdicionarSecao: () => abrirGaleriaAdicionar(ed),
    abrirTrocarModelo: () => abrirTrocarModelo(ed),
    abrirVersoes: () => abrirVersoes(ed),
    publicar: (botao) => fluxoPublicar(ed, botao),
    abrirIa(indice = null) {
      const s = Number.isInteger(indice) ? estado.doc.secoes[indice] : null;
      abrirJanelaIa(ed, s ? { escopo: s.tipo, nomeSecao: op.nomeSecao(lib, s.tipo) } : {});
    },

    passarOpcao(indice, delta) {
      const s = estado.doc.secoes[indice];
      const viz = s && op.opcaoVizinha(lib, s.tipo, s.opcao, delta);
      if (!viz) return;
      ed.trocarOpcao(indice, viz.id);
    },
    trocarOpcao(indice, opcao) {
      const s = estado.doc.secoes[indice];
      if (!s) return;
      if (ed.aplicar((d) => op.trocarOpcao(d, lib, indice, opcao), { rotulo: `Trocar opção de ${nomeDe(indice).toLowerCase()}` })) {
        const { posicao, total } = op.posicaoOpcao(lib, s.tipo, opcao);
        ed.anunciar(`${nomeDe(indice)}: ${op.nomeOpcao(lib, s.tipo, opcao)} (${posicao} de ${total}).`);
        selecionar(indice);
      }
    },
    moverSecao(indice, delta) {
      ed.moverSecaoPara(indice, indice + delta, { foco: delta < 0 ? 'cima' : 'baixo' });
    },
    moverSecaoPara(de, para, { foco = null } = {}) {
      const tipo = estado.doc.secoes[de]?.tipo;
      if (!tipo) return;
      const novo = op.moverSecao(estado.doc, lib, de, para);
      if (novo === estado.doc) return;
      const nome = nomeDe(de);
      ed.aplicar(() => novo, { rotulo: `Mover ${nome.toLowerCase()}` });
      const novoIndice = estado.doc.secoes.findIndex((s) => s.tipo === tipo);
      ed.selecionada = novoIndice;
      painel.secoes.atualizar(foco ? { focarTipo: tipo, focarAcao: foco } : {});
      tela.rolarPara(novoIndice, { destacar: true });
      ed.anunciar(`${nome} agora é a seção ${novoIndice + 1} de ${estado.doc.secoes.length}.`);
    },
    removerSecao(indice) {
      if (!op.podeRemoverSecao(estado.doc, lib, indice)) {
        aviso('Cabeçalho e rodapé fazem parte de todo site e não podem ser removidos.');
        return;
      }
      const nome = nomeDe(indice);
      // O botão que tinha o foco (lixeira da lista ou da barrinha) some com a seção: o foco vai
      // para a seção que ocupa o lugar dela (ou a anterior), senão o teclado cai no <body>.
      const ativo = document.activeElement;
      const focoNoPainel = painel.elemento.contains(ativo);
      const focoNaBarrinha = tela.camada.contains(ativo);
      ed.aplicar((d) => op.removerSecao(d, lib, indice), { rotulo: `Remover ${nome.toLowerCase()}` });
      ed.selecionada = null;
      const secoes = estado.doc.secoes;
      const vizinha = secoes[indice] && !op.fixaDe(lib, secoes[indice].tipo) ? indice : Math.max(0, indice - 1);
      if (focoNoPainel && secoes[vizinha]) {
        painel.secoes.atualizar({ focarTipo: secoes[vizinha].tipo, focarAcao: 'remover' });
      } else {
        painel.secoes.atualizar();
        if (focoNaBarrinha && secoes[vizinha]) {
          selecionar(vizinha);
          requestAnimationFrame(() => requestAnimationFrame(() => {
            const alvoFoco = tela.camada.querySelector('.ed-barrinha:not([hidden]) .ed-barrinha__btn--perigo:not([disabled])')
              ?? tela.camada.querySelector('.ed-barrinha:not([hidden]) button');
            alvoFoco?.focus({ preventScroll: true });
          }));
        }
      }
      aviso(`Seção "${nome}" removida. Os textos ficam guardados.`, {
        duracao: 6000,
        acao: { rotulo: 'Desfazer', fn: () => { desfazer(); selecionar(indice, { rolar: true }); } },
      });
    },
    adicionarSecao(tipo) {
      const r = op.adicionarSecao(estado.doc, lib, tipo, null, ed.selecionada);
      if (r.indice === null) return;
      ed.aplicar(() => r.doc, { rotulo: `Adicionar ${op.nomeSecao(lib, tipo).toLowerCase()}` });
      selecionar(r.indice);
      requestAnimationFrame(() => tela.rolarPara(r.indice, { destacar: true }));
      aviso(`Seção "${op.nomeSecao(lib, tipo)}" adicionada.`, { acao: { rotulo: 'Desfazer', fn: desfazer } });
    },
    trocarModelo(modelo) {
      const nomeModelo = lib.modelos?.[modelo]?.nome ?? modelo;
      if (ed.aplicar((d) => op.trocarModelo(d, lib, modelo), { rotulo: `Trocar para o modelo ${nomeModelo}` })) {
        ed.selecionada = null;
        aviso(`Modelo ${nomeModelo} aplicado. Textos, fotos e dados continuam.`, { acao: { rotulo: 'Desfazer', fn: desfazer } });
      }
    },
    adicionarItem(lista, aposId) {
      const r = op.adicionarItem(estado.doc, lib, lista, aposId);
      if (!r.id) {
        aviso(`Já são ${op.limitesLista(lib, lista)[1]} itens, o máximo desta lista.`);
        return;
      }
      ed.aplicar(() => r.doc, { rotulo: `Adicionar ${op.rotuloItem(lib, lista)}` });
      // Foco no texto principal do item novo, pronto para digitar.
      requestAnimationFrame(() => {
        const chave = `${lista}.${r.id}.${ed.campoAutomatico(lista)}`;
        if (!tela.focarChave(chave)) tela.focarChave(`${lista}.${r.id}.q`);
        const sel = window.getSelection();
        const foco = document.activeElement;
        if (sel && foco?.isContentEditable) {
          sel.selectAllChildren(foco);
        }
      });
    },
    removerItem(lista, id) {
      if (!op.podeRemoverItem(estado.doc, lib, lista)) {
        aviso(`Esta lista precisa de pelo menos ${op.limitesLista(lib, lista)[0]} itens.`);
        return;
      }
      const rotulo = op.rotuloItem(lib, lista);
      ed.aplicar((d) => op.removerItem(d, lib, lista, id), { rotulo: `Remover ${rotulo}` });
      aviso(`${rotulo.charAt(0).toUpperCase()}${rotulo.slice(1)} removido.`, { duracao: 6000, acao: { rotulo: 'Desfazer', fn: desfazer } });
    },

    definirModo(modo) {
      tela.definirModo(modo);
      barra.atualizar();
    },
    definirVisualizar(sim) {
      if (sim) texto.confirmar();
      ed.visualizando = Boolean(sim);
      raiz.classList.toggle('editor--visualizar', ed.visualizando);
      tela.definirVisualizar(ed.visualizando);
      if (ed.visualizando) {
        ed.hover = null;
        tela.elemento.querySelector('.ed-visualizar-barra button')?.focus();
      } else {
        requestAnimationFrame(() => barra.focarVisualizar());
      }
    },
    /** "Ir até lá" do checklist. */
    irPara(destino) {
      if (!destino) return;
      if (ed.visualizando) ed.definirVisualizar(false);
      if (destino.aba) {
        painel.abrirAba(destino.aba, { campo: destino.campo });
        return;
      }
      let indice = Number.isInteger(destino.secao) ? destino.secao : null;
      if (indice === null && destino.chave) {
        const e = tela.alvo.querySelector(`[data-k="${CSS.escape(destino.chave)}"], [data-img="${CSS.escape(destino.chave)}"]`);
        indice = tela.indiceDe(e);
      }
      painel.abrirAba('secoes');
      if (indice !== null) selecionar(indice, { rolar: !destino.chave });
      if (destino.chave) requestAnimationFrame(() => tela.focarChave(destino.chave));
    },
  });

  /* ---------------------------------------------------------------- reações ao estado */

  let quadroPainel = 0;
  const cancelarEstado = estado.assinar((evento) => {
    if (evento.tipo === 'doc') {
      if (Number.isInteger(ed.selecionada) && ed.selecionada >= estado.doc.secoes.length) ed.selecionada = null;
      if (ed.suprimirRender > 0) {
        // Digitação: o campo já mostra o texto; tela e painel esperam a confirmação.
        barra.atualizar();
        return;
      }
      tela.renderizar();
      barra.atualizar();
      if (faixaIa.atualizar()) tela.posicionar?.();
      cancelAnimationFrame(quadroPainel);
      quadroPainel = requestAnimationFrame(() => painel.atualizar());
    } else if (evento.tipo === 'midia') {
      tela.renderizar();
    } else if (evento.tipo === 'status' || evento.tipo === 'revisao') {
      barra.atualizarStatus();
    }
  });

  /* ---------------------------------------------------------------- teclado */

  function aoTecla(ev) {
    if (ev.defaultPrevented) return;
    if (document.querySelector('.modal-fundo')) return; // janelas cuidam do próprio teclado
    const acao = op.atalhoHistorico(ev);
    if (acao) {
      // Dentro de um campo de texto vale o desfazer nativo do navegador.
      if (ehCampoDeTexto(ev.target)) return;
      ev.preventDefault();
      if (acao === 'desfazer') desfazer();
      else refazer();
      return;
    }
    if (ev.key === 'Escape' && ed.visualizando) {
      ev.preventDefault();
      ed.definirVisualizar(false);
    }
  }
  document.addEventListener('keydown', aoTecla);

  /* ---------------------------------------------------------------- primeira pintura */

  // No celular a prévia já abre na largura de celular (1280 px reduzidos ficariam ilegíveis).
  tela.definirModo(window.matchMedia?.('(max-width: 759px)').matches ? 'celular' : 'computador');
  tela.renderizar();
  barra.atualizar();
  painel.atualizar();
  iaDisponivel().then((sim) => {
    if (ed.desmontado) return;
    ed.iaDisponivel = sim;
    barra.mostrarIa(sim);
    faixaIa.atualizar();
    tela.posicionar?.();
  });
  estado.pronto?.then?.((r) => {
    if (r?.recuperado && !r.conflito) aviso('Recuperamos alterações que não tinham chegado ao servidor.', { acao: { rotulo: 'Desfazer', fn: desfazer } });
  }).catch(() => {});

  return {
    /** Fecha o editor: salva o que estiver pendente e solta os ouvintes. */
    desmontar() {
      ed.desmontado = true;
      texto.confirmar();
      document.removeEventListener('keydown', aoTecla);
      cancelarEstado();
      cancelAnimationFrame(quadroPainel);
      texto.desligar();
      fotos.desligar();
      tela.desligar();
      estado.destruir();
      alvo.replaceChildren();
    },
    /** Para testes e depuração. */
    ed,
  };
}
