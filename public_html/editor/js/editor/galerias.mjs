// Galerias com miniaturas REAIS (o site do cliente renderizado de verdade, reduzido):
//   - opções de uma seção (PDF §7.4 "Ver todas as opções")
//   - adicionar seção (tipos que ainda não estão no site)
//   - trocar modelo (os 3 modelos, como passo desfazível)

import { el, icone, modal, aviso } from '../ui.mjs';
import { miniatura } from '../previa.mjs';
import { modelosOrdenados } from '../biblioteca.mjs';
import * as op from './operacoes.mjs';

function cartao({ mini, titulo, meta = '', descricao = '', ativo = false, rotulo, onclick }) {
  return el('button', {
    type: 'button',
    class: ['card-opcao', 'ed-galeria__card', ativo && 'card-opcao--ativo'],
    'aria-pressed': ativo ? 'true' : 'false',
    'aria-label': rotulo,
    onclick,
  },
  el('div', { class: 'card-opcao__mini' }, mini.elemento),
  el('div', { class: 'card-opcao__corpo ed-galeria__corpo' },
    el('span', { class: 'card-opcao__titulo' }, titulo,
      ativo ? el('span', { class: 'selo selo--pri ed-galeria__uso' }, icone('check'), 'Em uso') : null),
    descricao ? el('span', { class: 'card-opcao__desc' }, descricao) : null,
    meta ? el('span', { class: 'card-opcao__meta' }, meta) : null));
}

function abrirGaleria({ titulo, descricao, cartoes, minis, classe = '' }) {
  const grade = el('div', { class: ['ed-galeria', classe] }, cartoes);
  return modal({
    titulo,
    descricao,
    corpo: grade,
    tamanho: 'largo',
    aoFechar: () => {
      for (const m of minis) m.desligar();
    },
  });
}

/** Galeria de opções da seção `indice` (troca a opção ao clicar). */
export function abrirGaleriaOpcoes(ed, indice) {
  const { lib } = ed;
  const doc = ed.estado.doc;
  const s = doc.secoes[indice];
  if (!s) return null;
  const nome = op.nomeSecao(lib, s.tipo);
  const minis = [];
  let janela = null;
  const cartoes = op.opcoesDe(lib, s.tipo).map((o) => {
    const mini = miniatura(op.docSoComSecao(doc, s.tipo, o.id), lib, { midia: ed.estado.midia });
    minis.push(mini);
    const ativo = o.id === s.opcao;
    return cartao({
      mini,
      titulo: o.nome ?? o.id,
      descricao: o.descricao ?? '',
      ativo,
      rotulo: `${o.nome ?? o.id}${ativo ? ' (em uso)' : ''}`,
      onclick: () => {
        janela?.fechar('escolhido');
        if (!ativo) ed.trocarOpcao(indice, o.id);
      },
    });
  });
  janela = abrirGaleria({
    titulo: `Opções de ${nome.toLowerCase()}`,
    descricao: 'Os textos e as fotos continuam os mesmos em todas as opções.',
    cartoes,
    minis,
  });
  return janela;
}

/** Galeria "Adicionar seção" (tipos ausentes; entra depois da selecionada ou antes do rodapé). */
export function abrirGaleriaAdicionar(ed) {
  const { lib } = ed;
  const doc = ed.estado.doc;
  const ausentes = op.tiposAusentes(doc, lib);
  if (ausentes.length === 0) {
    aviso('Todas as seções disponíveis já estão no site.');
    return null;
  }
  const sel = Number.isInteger(ed.selecionada) ? doc.secoes[ed.selecionada] : null;
  const pos = op.posicaoNovaSecao(doc, lib, ed.selecionada);
  const anterior = doc.secoes[pos - 1];
  const descricao = sel && op.fixaDe(lib, sel.tipo) !== 'fim' && anterior
    ? `Entra depois de "${op.nomeSecao(lib, anterior.tipo)}".`
    : 'Entra antes do rodapé.';
  const minis = [];
  let janela = null;
  const cartoes = ausentes.map((tipo) => {
    const opcoes = op.opcoesDe(lib, tipo);
    const mini = miniatura(op.docSoComSecao(doc, tipo, opcoes[0].id), lib, { midia: ed.estado.midia });
    minis.push(mini);
    const nome = op.nomeSecao(lib, tipo);
    return cartao({
      mini,
      titulo: nome,
      meta: opcoes.length === 1 ? '1 opção' : `${opcoes.length} opções`,
      rotulo: `Adicionar ${nome}`,
      onclick: () => {
        janela?.fechar('escolhido');
        ed.adicionarSecao(tipo);
      },
    });
  });
  janela = abrirGaleria({ titulo: 'Adicionar seção', descricao, cartoes, minis, classe: 'ed-galeria--tres' });
  return janela;
}

/** Trocar modelo: miniatura do site do cliente em cada modelo. */
export function abrirTrocarModelo(ed) {
  const { lib } = ed;
  const doc = ed.estado.doc;
  const minis = [];
  let janela = null;
  const cartoes = modelosOrdenados(lib).map((m) => {
    const ativo = m.id === doc.modelo;
    const mini = miniatura(ativo ? doc : op.trocarModelo(doc, lib, m.id), lib, { midia: ed.estado.midia });
    minis.push(mini);
    return cartao({
      mini,
      titulo: m.nome ?? m.id,
      descricao: m.descricao ?? '',
      ativo,
      rotulo: `Modelo ${m.nome ?? m.id}${ativo ? ' (em uso)' : ''}`,
      onclick: () => {
        janela?.fechar('escolhido');
        if (!ativo) ed.trocarModelo(m.id);
      },
    });
  });
  janela = abrirGaleria({
    titulo: 'Trocar modelo',
    descricao: 'Mudam as seções, o acabamento e as fontes. Textos, fotos, cor e dados continuam. Dá para desfazer.',
    cartoes,
    minis,
    classe: 'ed-galeria--tres',
  });
  return janela;
}
