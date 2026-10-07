// Aba Seções (PDF §7.4): lista com o nome da seção e da opção (abre a galeria), ‹ n/N ›,
// arrastar para reordenar (HTML5, com linha indicadora; ↑ ↓ no celular e no teclado),
// lixeira (aviso com Desfazer por 6 s), "+ Adicionar seção" e "Trocar modelo".
// Cabeçalho é sempre o primeiro e rodapé sempre o último: não arrastam nem saem.

import { el, icone } from '../ui.mjs';
import * as op from './operacoes.mjs';

export function criarPainelSecoes(ed) {
  const { lib } = ed;
  const lista = el('ul', { class: 'lista-arrastavel ed-secoes', 'aria-label': 'Seções do site, na ordem da página' });
  const botaoAdicionar = el('button', { type: 'button', class: 'btn btn--bloco ed-secoes__adicionar', onclick: () => ed.abrirAdicionarSecao() },
    icone('mais'), 'Adicionar seção');
  const botaoModelo = el('button', { type: 'button', class: 'btn btn--fantasma btn--bloco btn--pequeno', onclick: () => ed.abrirTrocarModelo() },
    icone('grade'), 'Trocar modelo');
  const elemento = el('div', { class: 'ed-painel-secoes' },
    el('p', { class: 'ed-painel__ajuda' }, 'Arraste para mudar a ordem. Clique no nome da opção para ver todas as versões da seção.'),
    lista,
    el('div', { class: 'ed-secoes__rodape' }, botaoAdicionar, botaoModelo));

  let arrastando = null; // índice

  function limparSoltura() {
    for (const li of lista.querySelectorAll('.lista-arrastavel__item--soltar-antes, .lista-arrastavel__item--soltar-depois')) {
      li.classList.remove('lista-arrastavel__item--soltar-antes', 'lista-arrastavel__item--soltar-depois');
    }
  }

  function botaoAcao(rotulo, nomeIcone, acao, fn, { desabilitado = false, classe = '' } = {}) {
    return el('button', {
      type: 'button', class: ['icone-btn', 'icone-btn--pequeno', classe], 'aria-label': rotulo, title: rotulo,
      disabled: desabilitado, 'data-acao': acao, onclick: fn,
    }, icone(nomeIcone));
  }

  function linha(s, i, doc) {
    const fixa = op.fixaDe(lib, s.tipo);
    const nome = op.nomeSecao(lib, s.tipo);
    const nomeOp = op.nomeOpcao(lib, s.tipo, s.opcao);
    const { posicao, total } = op.posicaoOpcao(lib, s.tipo, s.opcao);
    const ativo = i === ed.selecionada;
    const li = el('li', {
      class: ['lista-arrastavel__item', 'ed-secao', ativo && 'lista-arrastavel__item--ativo', fixa && 'lista-arrastavel__item--fixo'],
      draggable: fixa ? null : 'true',
      'data-tipo': s.tipo,
      'data-indice': String(i),
    },
    el('span', { class: 'lista-arrastavel__alca', 'aria-hidden': 'true', title: fixa ? '' : 'Arrastar' }, icone('alca')),
    el('div', { class: 'ed-secao__info' },
      el('button', {
        type: 'button', class: 'ed-secao__nome', 'data-acao': 'ir', 'aria-current': ativo ? 'true' : null,
        onclick: () => ed.selecionar(i, { rolar: true }),
      }, nome, fixa ? el('span', { class: 'sr-only' }, fixa === 'inicio' ? ' (sempre a primeira)' : ' (sempre a última)') : null),
      el('button', {
        type: 'button', class: 'ed-secao__opcao', 'data-acao': 'galeria', 'aria-haspopup': 'dialog',
        'aria-label': `${nomeOp} — ver todas as opções de ${nome.toLowerCase()}`,
        onclick: () => {
          ed.selecionar(i, { rolar: true });
          ed.abrirGaleriaOpcoes(i);
        },
      }, nomeOp)),
    total > 1 ? el('div', { class: 'ed-secao__setas', role: 'group', 'aria-label': `Opção de ${nome.toLowerCase()}` },
      botaoAcao('Opção anterior', 'esquerda', 'anterior', () => ed.passarOpcao(i, -1)),
      el('span', { class: 'ed-secao__pos', 'aria-label': `Opção ${posicao} de ${total}` }, `${posicao}/${total}`),
      botaoAcao('Próxima opção', 'direita', 'proxima', () => ed.passarOpcao(i, 1))) : null,
    fixa && op.podeRemoverSecao(doc, lib, i) ? el('div', { class: 'ed-secao__acoes' },
      botaoAcao(`Remover ${nome.toLowerCase()} repetido`, 'lixeira', 'remover', () => ed.removerSecao(i), { classe: 'ed-secao__remover' })) : null,
    fixa ? null : el('div', { class: 'ed-secao__acoes' },
      botaoAcao(`Mover ${nome.toLowerCase()} para cima`, 'setaCima', 'cima', () => ed.moverSecao(i, -1), { desabilitado: !op.podeMoverSecao(doc, lib, i, -1) }),
      botaoAcao(`Mover ${nome.toLowerCase()} para baixo`, 'setaBaixo', 'baixo', () => ed.moverSecao(i, 1), { desabilitado: !op.podeMoverSecao(doc, lib, i, 1) }),
      botaoAcao(`Remover ${nome.toLowerCase()}`, 'lixeira', 'remover', () => ed.removerSecao(i), { classe: 'ed-secao__remover' })));

    // Passar o mouse na lista mostra a seção na tela.
    li.addEventListener('mouseenter', () => {
      ed.hover = i;
      ed.tela.posicionar();
    });
    li.addEventListener('mouseleave', () => {
      if (ed.hover === i) {
        ed.hover = null;
        ed.tela.posicionar();
      }
    });
    li.addEventListener('focusout', (ev) => {
      if (!li.contains(ev.relatedTarget)) li.classList.remove('ed-secao--focada');
    });
    if (!fixa) {
      li.addEventListener('dragstart', (ev) => {
        arrastando = i;
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', String(i));
        requestAnimationFrame(() => li.classList.add('lista-arrastavel__item--arrastando'));
      });
      li.addEventListener('dragend', () => {
        arrastando = null;
        li.classList.remove('lista-arrastavel__item--arrastando');
        limparSoltura();
      });
    }
    li.addEventListener('dragover', (ev) => {
      if (arrastando === null) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      const r = li.getBoundingClientRect();
      let antes = ev.clientY < r.top + r.height / 2;
      if (fixa === 'inicio') antes = false;
      if (fixa === 'fim') antes = true;
      limparSoltura();
      const para = op.posicaoSoltura(arrastando, i, antes);
      if (para !== arrastando) li.classList.add(antes ? 'lista-arrastavel__item--soltar-antes' : 'lista-arrastavel__item--soltar-depois');
    });
    li.addEventListener('dragleave', (ev) => {
      if (!li.contains(ev.relatedTarget)) {
        li.classList.remove('lista-arrastavel__item--soltar-antes', 'lista-arrastavel__item--soltar-depois');
      }
    });
    li.addEventListener('drop', (ev) => {
      if (arrastando === null) return;
      ev.preventDefault();
      const antes = li.classList.contains('lista-arrastavel__item--soltar-antes');
      const depois = li.classList.contains('lista-arrastavel__item--soltar-depois');
      limparSoltura();
      if (!antes && !depois) return;
      const de = arrastando;
      arrastando = null;
      ed.moverSecaoPara(de, op.posicaoSoltura(de, i, antes));
    });
    return li;
  }

  /** Redesenha a lista mantendo o foco no mesmo botão da mesma seção. */
  function atualizar({ focarTipo = null, focarAcao = null } = {}) {
    const doc = ed.estado.doc;
    const ativo = document.activeElement;
    const dentro = lista.contains(ativo);
    const tipoFoco = focarTipo ?? (dentro ? ativo.closest('[data-tipo]')?.dataset.tipo : null);
    const acaoFoco = focarAcao ?? (dentro ? ativo.dataset.acao : null);
    lista.replaceChildren(...doc.secoes.map((s, i) => linha(s, i, doc)));
    botaoAdicionar.disabled = op.tiposAusentes(doc, lib).length === 0;
    botaoAdicionar.title = botaoAdicionar.disabled ? 'Todas as seções disponíveis já estão no site' : '';
    if (tipoFoco && (dentro || focarTipo)) {
      const li = lista.querySelector(`[data-tipo="${CSS.escape(tipoFoco)}"]`);
      // As ações (↑ ↓ lixeira) só aparecem com foco/hover: mostra antes de focar.
      li?.classList.add('ed-secao--focada');
      let b = li?.querySelector(`[data-acao="${CSS.escape(acaoFoco ?? 'ir')}"]`);
      if (!b || b.disabled) b = li?.querySelector('[data-acao="ir"]');
      b?.focus({ preventScroll: false });
    }
  }

  /** Mostra a linha selecionada na lista (sem roubar o foco). */
  function revelar(indice) {
    const li = lista.querySelector(`[data-indice="${indice}"]`);
    li?.scrollIntoView({ block: 'nearest' });
  }

  return { elemento, atualizar, revelar };
}
