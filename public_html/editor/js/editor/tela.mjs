// Tela do editor: o site em largura real (1280 px computador / 390 px celular) reduzido
// por zoom, com as ferramentas de edição numa camada por cima (contornos, barrinha da
// seção, botões de lista, botão de foto de fundo, contador de caracteres, progresso de envio).
//
// A camada fica FORA do .rk (nenhum estilo do editor entra no site nem o contrário) e dentro
// da área rolável, então rola junto com o site; as posições são recalculadas quando o site
// muda de tamanho, quando rola (cabeçalho fixo) e a cada renderização.
//
//   const tela = criarTela(ed)  → { elemento, alvo, moldura, renderizar(), definirModo(m),
//                                   definirVisualizar(b), rolarPara(i), elementoSecao(i),
//                                   elementosChave(chave), posicionar(), desligar() }

import { el, icone, aviso, anexar } from '../ui.mjs';
import { prepararSite } from '../compartilhado/preparo.mjs';
import { injetarCssSite } from '../biblioteca.mjs';
import { opcoesPreparo, paletaEscopada, escalar, LARGURA_COMPUTADOR, LARGURA_CELULAR } from '../previa.mjs';
import { definicaoDaChave } from './operacoes.mjs';
import * as op from './operacoes.mjs';
import { morfar } from './morfar.mjs';

const CHAVE_AVISO_FECHADO = 'rankly.editor.avisoInicialFechado';
let contadorPrevia = 0;

function lerPreferencia(chave) {
  try {
    return localStorage.getItem(chave);
  } catch {
    return null;
  }
}

function gravarPreferencia(chave, valor) {
  try {
    localStorage.setItem(chave, valor);
  } catch {
    /* navegação privada: tudo bem */
  }
}

/** contenteditable="plaintext-only" quando o navegador suporta (senão "true" + filtros). */
const SUPORTA_PLAINTEXT = (() => {
  if (typeof document === 'undefined') return false;
  const d = document.createElement('div');
  try {
    d.contentEditable = 'plaintext-only';
  } catch {
    return false;
  }
  return d.contentEditable === 'plaintext-only';
})();

export function criarTela(ed) {
  const { lib } = ed;

  /* ---------------------------------------------------------------- estrutura */

  const alvo = el('div', { class: 'previa-moldura__site ed-site ed-editavel', id: 'ed-site' });
  alvo.dataset.previa = `ed${++contadorPrevia}`;
  const camada = el('div', { class: 'ed-camada', role: 'group', 'aria-label': 'Ferramentas da seção' });
  const moldura = el('div', { class: 'previa-moldura ed-moldura' }, alvo, camada);

  const avisoInicial = lerPreferencia(CHAVE_AVISO_FECHADO) === '1' ? null : el('div', { class: 'ed-dica', role: 'note' },
    icone('editar', { classe: 'ed-dica__ico' }),
    el('p', { class: 'ed-dica__texto' },
      'Clique em qualquer texto para editar. Clique numa foto ou num ícone para trocar. Passe o mouse numa seção para ver as opções dela.'),
    el('button', {
      type: 'button',
      class: 'icone-btn icone-btn--pequeno ed-dica__fechar',
      'aria-label': 'Fechar dica',
      onclick: () => {
        gravarPreferencia(CHAVE_AVISO_FECHADO, '1');
        avisoInicial?.remove();
        agendarPosicao();
      },
    }, icone('fechar')));

  const vComputador = el('button', { type: 'button', 'aria-pressed': 'true', 'aria-label': 'Computador', title: 'Computador', onclick: () => ed.definirModo('computador') }, icone('computador'));
  const vCelular = el('button', { type: 'button', 'aria-pressed': 'false', 'aria-label': 'Celular', title: 'Celular', onclick: () => ed.definirModo('celular') }, icone('celular'));
  const barraVisualizar = el('div', { class: 'ed-visualizar-barra', hidden: true, role: 'toolbar', 'aria-label': 'Visualizar' },
    el('span', { class: 'ed-visualizar-barra__texto' }, icone('olho'), 'Visualizando o site'),
    el('div', { class: 'segmentos ed-visualizar-barra__modos', role: 'group', 'aria-label': 'Largura da prévia' }, vComputador, vCelular),
    el('button', { type: 'button', class: 'btn btn--pequeno btn--primario', onclick: () => ed.definirVisualizar(false) },
      icone('editar'), 'Voltar a editar ', el('kbd', { class: 'ed-kbd' }, 'Esc')));

  const elemento = el('section', { class: 'editor__tela ed-tela', 'aria-label': 'Site em edição' },
    avisoInicial, barraVisualizar, moldura);

  let escala = null;
  let renderizado = false;
  let ultimoHtml = '';

  /* ---------------------------------------------------------------- renderização */

  function docDaTela() {
    const doc = ed.estado.doc;
    const temp = ed.imagensTemporarias;
    if (!temp || Object.keys(temp).length === 0) return doc;
    return { ...doc, imagens: { ...(doc.imagens ?? {}), ...temp } };
  }

  /** Renderiza o documento atual (prepararSite + atualização mínima do DOM). */
  function renderizar() {
    injetarCssSite(lib);
    // Visualizar mostra o site como será publicado (espaços de foto vazios sem a etiqueta "enviar").
    const modo = ed.visualizando ? 'publicar' : 'editor';
    const r = prepararSite(docDaTela(), lib, opcoesPreparo({ midia: ed.estado.midia, modo }));
    ed.ultimoPreparo = r;
    for (const c of [...alvo.classList]) {
      if (c === 'rk' || /^k-[a-z]+$/.test(c) || /^f-[a-z]+$/.test(c)) alvo.classList.remove(c);
    }
    alvo.classList.add(...r.classesRaiz.split(/\s+/).filter(Boolean));
    const html = `<style data-rk-paleta>${paletaEscopada(r.cssPaleta, alvo.dataset.previa)}</style>${r.html}`;
    if (!renderizado) {
      alvo.innerHTML = html;
      renderizado = true;
    } else if (html !== ultimoHtml) {
      morfar(alvo, html);
    }
    ultimoHtml = html;
    decorar();
    agendarPosicao();
    return r;
  }

  /** O DOM mudou fora da renderização (digitação): a próxima renderização compara de novo. */
  function invalidar() {
    ultimoHtml = '';
  }

  /** Atributos de edição nos elementos editáveis (ou a retirada deles no modo Visualizar). */
  function decorar() {
    const editar = !ed.visualizando;
    alvo.classList.toggle('ed-editavel', editar);
    for (const e of alvo.querySelectorAll('[data-k]')) {
      if (!editar) {
        e.removeAttribute('contenteditable');
        e.removeAttribute('role');
        e.removeAttribute('aria-label');
        e.removeAttribute('tabindex');
        continue;
      }
      const modo = SUPORTA_PLAINTEXT ? 'plaintext-only' : 'true';
      if (e.getAttribute('contenteditable') !== modo) e.setAttribute('contenteditable', modo);
      if (e.getAttribute('spellcheck') !== 'true') e.setAttribute('spellcheck', 'true');
      if (!e.hasAttribute('role')) {
        const def = definicaoDaChave(lib, e.dataset.k);
        e.setAttribute('role', 'textbox');
        e.setAttribute('aria-multiline', 'false');
        e.setAttribute('aria-label', `Editar texto: ${def?.rotulo ?? 'texto do site'}`);
        e.setAttribute('enterkeyhint', 'done');
      }
      // Contorno visível também em texto vazio (senão não dá para clicar nele).
      e.classList.toggle('ed-vazio', e.textContent.trim() === '');
    }
    const imagens = docDaTela().imagens ?? {};
    for (const e of alvo.querySelectorAll('[data-img]')) {
      // Foto de fundo tem botão próprio ("Enviar foto de fundo"): o bloco em si não é clicável.
      if (!editar || e.hasAttribute('data-fundo')) {
        e.removeAttribute('tabindex');
        e.removeAttribute('role');
        e.removeAttribute('aria-label');
        e.removeAttribute('aria-haspopup');
        continue;
      }
      const def = op.definicaoDaChave(lib, e.dataset.img);
      const tem = Boolean(imagens[e.dataset.img]);
      e.setAttribute('tabindex', '0');
      e.setAttribute('role', 'button');
      e.setAttribute('aria-haspopup', 'dialog');
      e.setAttribute('aria-label', `${tem ? 'Trocar foto' : 'Enviar foto'}: ${def?.rotulo ?? 'foto'}`);
    }
    for (const e of alvo.querySelectorAll('[data-ic]')) {
      if (!editar) {
        e.removeAttribute('tabindex');
        e.removeAttribute('role');
        e.removeAttribute('aria-label');
        continue;
      }
      e.setAttribute('tabindex', '0');
      e.setAttribute('role', 'button');
      e.setAttribute('aria-haspopup', 'dialog');
      const titulo = tituloDoItem(e.dataset.ic);
      e.setAttribute('aria-label', titulo ? `Trocar ícone de "${titulo}"` : 'Trocar ícone');
    }
  }

  function tituloDoItem(chaveItem) {
    const [lista] = String(chaveItem).split('.');
    const campo = ed.campoAutomatico(lista);
    return op.textoMostrado(ed.estado.doc, lib, `${chaveItem}.${campo}`);
  }

  /* ---------------------------------------------------------------- consultas */

  function elementoSecao(indice) {
    if (!Number.isInteger(indice)) return null;
    return alvo.querySelector(`.rk-sec[data-sec="${indice}"]`);
  }

  function indiceDe(elementoOuNo) {
    const sec = elementoOuNo?.closest?.('.rk-sec[data-sec]');
    if (!sec || !alvo.contains(sec)) return null;
    const i = Number(sec.dataset.sec);
    return Number.isInteger(i) ? i : null;
  }

  function elementosChave(chave) {
    return [...alvo.querySelectorAll('[data-k]')].filter((e) => e.dataset.k === chave);
  }

  /** Caixa de um elemento no sistema de coordenadas da camada (conteúdo rolável da moldura). */
  function caixa(e) {
    const r = e.getBoundingClientRect();
    const m = moldura.getBoundingClientRect();
    return {
      top: r.top - m.top + moldura.scrollTop - moldura.clientTop,
      left: r.left - m.left + moldura.scrollLeft - moldura.clientLeft,
      width: r.width,
      height: r.height,
    };
  }

  /** Cabeçalho do site "grudado" no topo (position: sticky) cobre o começo das outras seções. */
  function cabecalhoFixo() {
    const h = alvo.querySelector('.rk-sec--header');
    if (!h || getComputedStyle(h).position !== 'sticky') return null;
    return h;
  }

  /** Primeira linha livre (coordenadas da camada) abaixo do cabeçalho fixo, para a seção `indice`. */
  function topoLivre(indice) {
    const h = cabecalhoFixo();
    if (!h || indice === Number(h.dataset.sec)) return -Infinity;
    const c = caixa(h);
    return c.top + c.height;
  }

  function posicionarEm(no, c, extra = {}) {
    no.style.top = `${Math.round(c.top)}px`;
    no.style.left = `${Math.round(c.left)}px`;
    if (extra.largura !== false) no.style.width = `${Math.round(c.width)}px`;
    if (extra.altura !== false) no.style.height = `${Math.round(c.height)}px`;
  }

  /* ---------------------------------------------------------------- camada */

  const contornoHover = el('div', { class: 'ed-contorno ed-contorno--hover', hidden: true, 'aria-hidden': 'true' });
  const contornoSel = el('div', { class: 'ed-contorno ed-contorno--sel', hidden: true, 'aria-hidden': 'true' });
  const barrinha = el('div', { class: 'ed-barrinha', hidden: true, role: 'toolbar', 'aria-label': 'Seção' });
  const grupoListas = el('div', { class: 'ed-listas' });
  const grupoFundo = el('div', { class: 'ed-fundos' });
  const grupoEnvios = el('div', { class: 'ed-envios' });
  const contador = el('div', { class: 'ed-contador', hidden: true, 'aria-hidden': 'true' });
  camada.append(contornoHover, contornoSel, grupoFundo, grupoListas, barrinha, grupoEnvios, contador);

  let secaoBarrinha = null;
  let assinaturaBarrinha = '';

  function secaoAtiva() {
    if (ed.visualizando) return null;
    if (Number.isInteger(ed.hover) && elementoSecao(ed.hover)) return ed.hover;
    if (Number.isInteger(ed.selecionada) && elementoSecao(ed.selecionada)) return ed.selecionada;
    return null;
  }

  function botaoBarra(rotulo, nomeIcone, fn, { desabilitado = false, classe = '' } = {}) {
    return el('button', {
      type: 'button', class: ['ed-barrinha__btn', classe], 'aria-label': rotulo, title: rotulo, disabled: desabilitado,
      onclick: (ev) => {
        ev.stopPropagation();
        fn();
      },
    }, icone(nomeIcone));
  }

  function montarBarrinha(indice) {
    const doc = ed.estado.doc;
    const s = doc.secoes[indice];
    if (!s) return;
    const { posicao, total } = op.posicaoOpcao(lib, s.tipo, s.opcao);
    const assinatura = `${indice}|${s.tipo}|${s.opcao}|${doc.secoes.length}|${ed.estado.podeDesfazer}|${ed.iaDisponivel}`;
    if (assinatura === assinaturaBarrinha && barrinha.childElementCount > 0) return;
    assinaturaBarrinha = assinatura;
    const nome = op.nomeSecao(lib, s.tipo);
    barrinha.setAttribute('aria-label', `Seção ${nome}`);
    // anexar() pula os null (replaceChildren nativo escreveria "null" na barra).
    barrinha.replaceChildren();
    anexar(barrinha,
      el('span', { class: 'ed-barrinha__nome' }, nome),
      total > 1 ? botaoBarra('Opção anterior', 'esquerda', () => ed.passarOpcao(indice, -1)) : null,
      total > 1 ? el('span', { class: 'ed-barrinha__pos', 'aria-label': `Opção ${posicao} de ${total}` }, `${posicao}/${total}`) : null,
      total > 1 ? botaoBarra('Próxima opção', 'direita', () => ed.passarOpcao(indice, 1)) : null,
      el('span', { class: 'ed-barrinha__sep', 'aria-hidden': 'true' }),
      botaoBarra('Ver todas as opções', 'grade', () => ed.abrirGaleriaOpcoes(indice)),
      ed.iaDisponivel && op.secaoTemTextosIa(lib, s.tipo) ? botaoBarra('Reescrever com IA', 'brilho', () => ed.abrirIa(indice)) : null,
      botaoBarra('Mover para cima', 'setaCima', () => ed.moverSecao(indice, -1), { desabilitado: !op.podeMoverSecao(doc, lib, indice, -1) }),
      botaoBarra('Mover para baixo', 'setaBaixo', () => ed.moverSecao(indice, 1), { desabilitado: !op.podeMoverSecao(doc, lib, indice, 1) }),
      botaoBarra('Remover seção', 'lixeira', () => ed.removerSecao(indice), {
        desabilitado: !op.podeRemoverSecao(doc, lib, indice), classe: 'ed-barrinha__btn--perigo',
      }),
    );
  }

  /** Botões "+ Adicionar {item}" e "Remover" das listas variáveis da seção ativa. */
  function montarListas(indice, secEl) {
    grupoListas.replaceChildren();
    if (indice === null || !secEl) return;
    const doc = ed.estado.doc;
    const s = doc.secoes[indice];
    const donas = new Set(op.listasDoTipo(lib, s?.tipo));
    for (const cont of secEl.querySelectorAll('[data-li]')) {
      const lista = cont.dataset.li;
      if (!donas.has(lista) || !op.listaVariavel(lib, lista)) continue;
      const rotulo = op.rotuloItem(lib, lista);
      const podeRemover = op.podeRemoverItem(doc, lib, lista);
      const itens = [...cont.querySelectorAll(':scope > [data-it], :scope > * > [data-it]')]
        .filter((it) => it.closest('[data-li]') === cont);
      const livre = topoLivre(indice);
      const cs = caixa(secEl);
      const limiteDireita = cs.left + cs.width;
      itens.forEach((it, pos) => {
        const c = caixa(it);
        if (c.width === 0 && c.height === 0) return;
        if (c.top + 6 < livre) return; // escondido sob o cabeçalho fixo
        const titulo = op.textoMostrado(doc, lib, `${lista}.${it.dataset.it}.${ed.campoAutomatico(lista)}`);
        const b = el('button', {
          type: 'button',
          class: 'ed-item-remover',
          'data-lista': lista,
          'data-pos': String(pos),
          'aria-label': `Remover ${rotulo} ${pos + 1}${titulo ? ` (${titulo})` : ''}`,
          title: podeRemover ? `Remover ${rotulo}` : `Mínimo de ${op.limitesLista(lib, lista)[0]} itens`,
          disabled: !podeRemover,
          onclick: (ev) => {
            ev.stopPropagation();
            ed.removerItem(lista, it.dataset.it);
          },
        }, icone('lixeira'), el('span', null, 'Remover'));
        // Fora do item (à direita) quando há espaço — não cobre ícones e setas do próprio item.
        const fora = limiteDireita - (c.left + c.width) >= 44;
        b.classList.toggle('ed-item-remover--fora', fora);
        b.style.top = `${Math.round(c.top + 6)}px`;
        b.style.left = `${Math.round(fora ? c.left + c.width + 8 : c.left + c.width - 6)}px`;
        grupoListas.append(b);
      });
      const c = caixa(cont);
      const pode = op.podeAdicionarItem(doc, lib, lista);
      const [, max] = op.limitesLista(lib, lista);
      const ultimo = itens.at(-1)?.dataset.it ?? null;
      const add = el('button', {
        type: 'button',
        class: 'ed-item-adicionar',
        'data-lista': lista,
        disabled: !pode,
        title: pode ? '' : `Máximo de ${max} itens`,
        onclick: (ev) => {
          ev.stopPropagation();
          ed.adicionarItem(lista, ultimo);
        },
      }, icone('mais'), `Adicionar ${rotulo}`);
      if (c.top + c.height + 10 < livre) continue;
      add.style.top = `${Math.round(c.top + c.height + 10)}px`;
      add.style.left = `${Math.round(c.left + c.width / 2)}px`;
      grupoListas.append(add);
    }
  }

  let docListas = null;
  let indiceListas = null;

  function devolverFocoListas({ lista, pos, adicionar }) {
    const daLista = [...grupoListas.querySelectorAll('button')].filter((b) => b.dataset.lista === lista && !b.disabled);
    const remover = daLista.filter((b) => b.classList.contains('ed-item-remover'));
    const add = daLista.find((b) => b.classList.contains('ed-item-adicionar'));
    let alvoFoco = null;
    if (!adicionar && remover.length > 0) alvoFoco = remover[Math.min(Math.max(0, pos), remover.length - 1)];
    alvoFoco = alvoFoco ?? add ?? remover.at(-1) ?? null;
    alvoFoco?.focus({ preventScroll: true });
  }

  function montarFundos(indice, secEl) {
    grupoFundo.replaceChildren();
    if (indice === null || !secEl) return;
    for (const f of secEl.querySelectorAll('[data-img][data-fundo]')) {
      const chave = f.dataset.img;
      const c = caixa(f);
      const tem = Boolean(docDaTela().imagens?.[chave]);
      const b = el('button', {
        type: 'button',
        class: 'ed-fundo-btn',
        'aria-haspopup': 'dialog',
        onclick: (ev) => {
          ev.stopPropagation();
          ed.abrirFoto(chave);
        },
      }, icone('imagem'), tem ? 'Trocar foto de fundo' : 'Enviar foto de fundo');
      let topo = Math.min(Math.max(c.top + 14, topoLivre(indice) + 14), c.top + Math.max(14, c.height - 48));
      // Seção estreita (celular): o botão desce para não ficar embaixo da barrinha.
      if (c.width < 720) topo += 46;
      b.style.top = `${Math.round(topo)}px`;
      b.style.left = `${Math.round(c.left + 14)}px`;
      grupoFundo.append(b);
    }
  }

  function montarEnvios() {
    grupoEnvios.replaceChildren();
    for (const [chave, envio] of ed.envios) {
      const e = alvo.querySelector(`[data-img="${CSS.escape(chave)}"]`);
      if (!e) continue;
      const c = caixa(e);
      const pct = Math.round((envio.progresso ?? 0) * 100);
      const barra = el('div', {
        class: 'ed-envio',
        role: 'progressbar',
        'aria-label': 'Enviando foto',
        'aria-valuemin': '0',
        'aria-valuemax': '100',
        'aria-valuenow': String(pct),
      }, el('span', { class: 'ed-envio__texto' }, envio.etapa === 'preparando' ? 'Preparando a foto…' : `Enviando foto… ${pct}%`),
      el('span', { class: 'ed-envio__trilho' }, el('span', { class: 'ed-envio__barra', style: { width: `${pct}%` } })));
      const largura = Math.min(260, Math.max(160, c.width - 24));
      barra.style.width = `${Math.round(largura)}px`;
      barra.style.top = `${Math.round(c.top + c.height / 2 - 22)}px`;
      barra.style.left = `${Math.round(c.left + (c.width - largura) / 2)}px`;
      grupoEnvios.append(barra);
    }
  }

  function posicionarContador() {
    const info = ed.contador;
    if (!info || !info.elemento?.isConnected || !info.estado?.mostrar || ed.visualizando) {
      contador.hidden = true;
      return;
    }
    const c = caixa(info.elemento);
    contador.textContent = info.estado.texto;
    contador.classList.toggle('ed-contador--limite', info.estado.limite);
    contador.hidden = false;
    contador.style.top = `${Math.round(c.top + c.height + 4)}px`;
    contador.style.left = `${Math.round(c.left + c.width)}px`;
  }

  /** Recalcula a camada (contornos, barrinha, botões) — sempre num quadro de animação. */
  function posicionar() {
    if (ed.visualizando) {
      for (const n of [contornoHover, contornoSel, barrinha, contador]) n.hidden = true;
      grupoListas.replaceChildren();
      grupoFundo.replaceChildren();
      montarEnvios();
      return;
    }
    const hoverEl = Number.isInteger(ed.hover) ? elementoSecao(ed.hover) : null;
    const selEl = Number.isInteger(ed.selecionada) ? elementoSecao(ed.selecionada) : null;
    // Contornos não passam por cima do cabeçalho fixo do site.
    const recortar = (c, indice) => {
      const livre = topoLivre(indice);
      if (c.top >= livre) return c;
      const fim = c.top + c.height;
      return { ...c, top: livre, height: Math.max(0, fim - livre) };
    };
    if (hoverEl && ed.hover !== ed.selecionada) {
      posicionarEm(contornoHover, recortar(caixa(hoverEl), ed.hover));
      contornoHover.hidden = false;
    } else {
      contornoHover.hidden = true;
    }
    if (selEl) {
      posicionarEm(contornoSel, recortar(caixa(selEl), ed.selecionada));
      contornoSel.hidden = false;
    } else {
      contornoSel.hidden = true;
    }
    const ativa = secaoAtiva();
    const ativaEl = ativa === null ? null : elementoSecao(ativa);
    const focoNaBarrinha = barrinha.contains(document.activeElement);
    if (ativaEl) {
      if (secaoBarrinha !== ativa) assinaturaBarrinha = '';
      secaoBarrinha = ativa;
      montarBarrinha(ativa);
      const c = caixa(ativaEl);
      barrinha.hidden = false;
      const largura = barrinha.offsetWidth || 300;
      const topo = Math.min(Math.max(c.top + 10, topoLivre(ativa) + 10), c.top + Math.max(10, c.height - 46));
      barrinha.style.top = `${Math.round(topo)}px`;
      barrinha.style.left = `${Math.round(Math.max(c.left + 10, c.left + c.width - largura - 10))}px`;
    } else if (!focoNaBarrinha) {
      barrinha.hidden = true;
      secaoBarrinha = null;
    }
    // Com o foco num botão de item, os botões só são refeitos quando o documento mudou (item
    // removido ou adicionado — senão ficariam botões de itens que não existem mais), e o foco
    // volta ao botão equivalente. Passar o mouse em outra seção não tira o foco do teclado.
    const focado = grupoListas.contains(document.activeElement) ? document.activeElement : null;
    if (!focado || docListas !== ed.estado.doc) {
      const foco = focado ? { lista: focado.dataset.lista, pos: Number(focado.dataset.pos), adicionar: focado.classList.contains('ed-item-adicionar') } : null;
      const secaoFoco = foco ? indiceListas : ativa;
      montarListas(secaoFoco, elementoSecao(secaoFoco));
      indiceListas = secaoFoco;
      docListas = ed.estado.doc;
      if (foco) devolverFocoListas(foco);
    }
    montarFundos(ativa, ativaEl);
    montarEnvios();
    posicionarContador();
  }

  let quadro = 0;
  function agendarPosicao() {
    if (quadro) return;
    quadro = requestAnimationFrame(() => {
      quadro = 0;
      posicionar();
    });
  }

  /* ---------------------------------------------------------------- eventos */

  function aoMover(ev) {
    if (ed.visualizando) return;
    if (camada.contains(ev.target)) return;
    const i = indiceDe(ev.target);
    if (i !== ed.hover) {
      ed.hover = i;
      agendarPosicao();
    }
  }

  function aoSair(ev) {
    if (ev.relatedTarget && moldura.contains(ev.relatedTarget)) return;
    if (ed.hover !== null) {
      ed.hover = null;
      agendarPosicao();
    }
  }

  function linkInterno(a) {
    const href = a.getAttribute('href') ?? '';
    return href.startsWith('#') ? href.slice(1) : null;
  }

  function rolarParaAncora(id) {
    if (!id) {
      moldura.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
    const destino = alvo.querySelector(`#${CSS.escape(id)}`);
    if (!destino) return;
    const c = caixa(destino);
    moldura.scrollTo({ top: Math.max(0, c.top - 12), behavior: suave() });
  }

  function suave() {
    return matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
  }

  function aoClicar(ev) {
    const t = ev.target;
    if (!(t instanceof Element) || !alvo.contains(t)) return;
    const link = t.closest('a[href]');
    const botaoForm = t.closest('button[type="submit"], input[type="submit"]');

    if (ed.visualizando) {
      // No Visualizar os links funcionam como no site, mas sem sair do editor.
      if (link) {
        const id = linkInterno(link);
        if (id !== null) {
          ev.preventDefault();
          rolarParaAncora(id === 'rk-conteudo' ? ed.ultimoPreparo?.alvoPular : id);
        } else if (link.target !== '_blank') {
          ev.preventDefault();
          window.open(link.href, '_blank', 'noopener');
        }
      }
      if (botaoForm) {
        ev.preventDefault();
        aviso('O formulário funciona no site publicado. Aqui é só a prévia.');
      }
      return;
    }

    // Modo edição: nada navega nem envia.
    if (link || botaoForm || t.closest('summary')) {
      const editavel = t.closest('[data-k]');
      if (link || botaoForm) ev.preventDefault();
      if (t.closest('summary') && editavel) ev.preventDefault();
      if (editavel) return;
    }
    const icn = t.closest('[data-ic]');
    if (icn) {
      ev.preventDefault();
      ed.abrirSeletorIcone(icn.dataset.ic, icn);
      return;
    }
    const img = t.closest('[data-img]');
    if (img && !img.hasAttribute('data-fundo') && !t.closest('[data-k]')) {
      ev.preventDefault();
      ed.abrirFoto(img.dataset.img, img);
      return;
    }
    const i = indiceDe(t);
    if (i !== null && i !== ed.selecionada) ed.selecionar(i, { rolar: false, origem: 'tela' });
  }

  function aoTecla(ev) {
    if (ed.visualizando) return;
    const t = ev.target;
    if (!(t instanceof Element) || !alvo.contains(t)) return;
    if ((ev.key === 'Enter' || ev.key === ' ') && !t.hasAttribute('data-k')) {
      const icn = t.closest('[data-ic]');
      const img = t.closest('[data-img]');
      if (icn && icn === t) {
        ev.preventDefault();
        ed.abrirSeletorIcone(icn.dataset.ic, icn);
      } else if (img && img === t && !img.hasAttribute('data-fundo')) {
        ev.preventDefault();
        ed.abrirFoto(img.dataset.img, img);
      }
    }
  }

  function aoEnviar(ev) {
    if (alvo.contains(ev.target)) {
      ev.preventDefault();
      if (ed.visualizando) aviso('O formulário funciona no site publicado. Aqui é só a prévia.');
    }
  }

  function aoFocar(ev) {
    const t = ev.target;
    if (!(t instanceof Element) || !alvo.contains(t)) return;
    const i = indiceDe(t);
    if (i !== null && i !== ed.selecionada) ed.selecionar(i, { rolar: false, origem: 'tela' });
    // Pergunta do FAQ: abre o item para a resposta ficar à mão.
    const det = t.closest('details');
    if (det && !ed.visualizando && !det.open && t.closest('summary')) det.open = true;
  }

  alvo.addEventListener('pointermove', aoMover);
  moldura.addEventListener('pointerleave', aoSair);
  alvo.addEventListener('click', aoClicar, true);
  alvo.addEventListener('keydown', aoTecla);
  alvo.addEventListener('submit', aoEnviar, true);
  alvo.addEventListener('focusin', aoFocar);
  alvo.addEventListener('load', agendarPosicao, true); // fotos que terminam de carregar mudam alturas
  moldura.addEventListener('scroll', agendarPosicao, { passive: true });

  const observador = typeof ResizeObserver === 'function' ? new ResizeObserver(agendarPosicao) : null;
  observador?.observe(alvo);
  observador?.observe(moldura);
  document.fonts?.ready?.then(agendarPosicao).catch(() => {});

  /* ---------------------------------------------------------------- API */

  function definirModo(modo) {
    ed.modo = modo === 'celular' ? 'celular' : 'computador';
    moldura.classList.toggle('previa-moldura--celular', ed.modo === 'celular');
    vComputador.setAttribute('aria-pressed', ed.modo === 'computador' ? 'true' : 'false');
    vCelular.setAttribute('aria-pressed', ed.modo === 'celular' ? 'true' : 'false');
    const largura = ed.modo === 'celular' ? LARGURA_CELULAR : LARGURA_COMPUTADOR;
    if (!escala) escala = escalar(moldura, alvo, largura);
    else escala.definirLargura(largura);
    agendarPosicao();
  }

  function definirVisualizar(sim) {
    alvo.classList.toggle('ed-visualizando', sim);
    barraVisualizar.hidden = !sim;
    if (avisoInicial) avisoInicial.hidden = sim;
    renderizar();
  }

  /** Rola a tela até a seção e pisca o contorno. */
  function rolarPara(indice, { destacar = true } = {}) {
    const s = elementoSecao(indice);
    if (!s) return;
    const c = caixa(s);
    const h = cabecalhoFixo();
    const cobre = h && h !== s ? h.getBoundingClientRect().height : 0;
    moldura.scrollTo({ top: Math.max(0, c.top - 16 - cobre), behavior: suave() });
    if (destacar) {
      contornoSel.classList.remove('ed-contorno--piscar');
      void contornoSel.offsetWidth;
      contornoSel.classList.add('ed-contorno--piscar');
    }
  }

  /** Coloca o foco num texto ou numa foto da tela (usado pelo "Ir até lá"). */
  function focarChave(chave) {
    const e = alvo.querySelector(`[data-k="${CSS.escape(chave)}"]`)
      ?? alvo.querySelector(`[data-img="${CSS.escape(chave)}"]`)
      ?? alvo.querySelector(`[data-ic="${CSS.escape(chave)}"]`);
    if (!e) return false;
    e.scrollIntoView({ block: 'center', behavior: suave() });
    e.focus({ preventScroll: true });
    return true;
  }

  function desligar() {
    cancelAnimationFrame(quadro);
    observador?.disconnect();
    escala?.desligar();
    alvo.removeEventListener('pointermove', aoMover);
    moldura.removeEventListener('pointerleave', aoSair);
    alvo.removeEventListener('click', aoClicar, true);
    alvo.removeEventListener('keydown', aoTecla);
    alvo.removeEventListener('submit', aoEnviar, true);
    alvo.removeEventListener('focusin', aoFocar);
    alvo.removeEventListener('load', agendarPosicao, true);
    moldura.removeEventListener('scroll', agendarPosicao);
  }

  return {
    elemento,
    alvo,
    moldura,
    camada,
    renderizar,
    invalidar,
    decorar,
    definirModo,
    definirVisualizar,
    rolarPara,
    focarChave,
    elementoSecao,
    elementosChave,
    indiceDe,
    caixa,
    posicionar: agendarPosicao,
    desligar,
  };
}
