// Barra superior do editor (PDF §7.1): voltar ao painel · nome e status de salvamento ·
// Computador/Celular · desfazer/refazer · histórico · Visualizar · Publicar.

import { el, icone, modal, aviso } from '../ui.mjs';
import { contextoVariaveis } from '../compartilhado/textos.mjs';

const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? navigator.userAgent ?? '');
const MOD = MAC ? '⌘' : 'Ctrl';

export function criarBarra(ed) {
  const voltar = el('a', { href: '#/sites', class: 'icone-btn', 'aria-label': 'Voltar ao painel de sites', title: 'Voltar ao painel' }, icone('voltar'));
  const nome = el('strong', { class: 'ed-barra__nome' });
  const status = el('button', { type: 'button', class: 'editor__status ed-barra__status', disabled: true });
  const statusVivo = el('span', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });

  const computador = el('button', { type: 'button', 'aria-pressed': 'true', onclick: () => ed.definirModo('computador') },
    icone('computador'), el('span', { class: 'ed-barra__rotulo' }, 'Computador'));
  const celular = el('button', { type: 'button', 'aria-pressed': 'false', onclick: () => ed.definirModo('celular') },
    icone('celular'), el('span', { class: 'ed-barra__rotulo' }, 'Celular'));
  const modos = el('div', { class: 'segmentos', role: 'group', 'aria-label': 'Largura da prévia' }, computador, celular);

  const desfazer = el('button', { type: 'button', class: 'icone-btn', onclick: () => ed.desfazer() }, icone('desfazer'));
  const refazer = el('button', { type: 'button', class: 'icone-btn', onclick: () => ed.refazer() }, icone('refazer'));
  const historico = el('button', {
    type: 'button', class: 'icone-btn', 'aria-label': 'Histórico de publicações', title: 'Histórico de publicações',
    'aria-haspopup': 'dialog', onclick: () => ed.abrirVersoes(),
  }, icone('historico'));
  const visualizar = el('button', { type: 'button', class: 'btn btn--fantasma ed-barra__visualizar', onclick: () => ed.definirVisualizar(true) },
    icone('olho'), el('span', { class: 'ed-barra__rotulo' }, 'Visualizar'));
  const publicar = el('button', { type: 'button', class: 'btn btn--primario ed-barra__publicar', 'aria-haspopup': 'dialog' },
    icone('publicar'), el('span', null, 'Publicar'));
  publicar.addEventListener('click', () => ed.publicar(publicar));

  const elemento = el('header', { class: 'editor__barra ed-barra' },
    el('div', { class: 'editor__barra-esq' }, voltar,
      el('div', { class: 'editor__nome' }, nome, status), statusVivo),
    el('div', { class: 'editor__barra-meio' }, modos),
    el('div', { class: 'editor__barra-dir' },
      el('div', { class: 'ed-barra__historia', role: 'group', 'aria-label': 'Desfazer e refazer' }, desfazer, refazer),
      el('span', { class: 'ed-barra__sep', 'aria-hidden': 'true' }),
      historico, visualizar, publicar));

  function janelaConflito() {
    const c = ed.estado.conflito;
    if (!c) return;
    modal({
      titulo: 'Este site foi alterado em outra janela',
      descricao: 'Outra janela (ou outra pessoa) salvou uma versão mais nova enquanto você editava.',
      corpo: el('p', { class: 'modal__texto' },
        '"Carregar a versão mais nova" descarta as suas alterações (você ainda pode usar Desfazer). "Manter a minha" grava a sua versão por cima da outra.'),
      acoes: [
        { rotulo: 'Carregar a versão mais nova', fn: async () => { await ed.estado.carregarVersaoNova(); aviso('Versão mais nova carregada.', { tipo: 'ok' }); } },
        { rotulo: 'Manter a minha', tipo: 'primario', foco: true, fn: async () => { await ed.estado.manterMinhaVersao(); } },
      ],
    });
  }
  status.addEventListener('click', () => {
    if (ed.estado.status === 'conflito') janelaConflito();
    else if (ed.estado.status === 'erro' || ed.estado.status === 'offline') ed.estado.salvar();
  });

  let ultimoStatus = '';
  function atualizarStatus() {
    const s = ed.estado.status;
    const texto = s === 'salvo' ? 'Salvo' : ed.estado.textoStatus;
    status.className = `editor__status editor__status--${s} ed-barra__status`;
    status.textContent = texto;
    const clicavel = s === 'conflito' || s === 'erro' || s === 'offline';
    status.disabled = !clicavel;
    status.title = s === 'conflito' ? 'Escolher qual versão manter' : (clicavel ? 'Tentar salvar de novo' : '');
    // Anuncia só mudanças relevantes (não a cada "Salvando…").
    if (s !== ultimoStatus && s !== 'salvando') statusVivo.textContent = texto;
    ultimoStatus = s;
  }

  function atualizar() {
    const doc = ed.estado.doc;
    nome.textContent = contextoVariaveis(doc, ed.lib).nome || 'Site sem nome';
    nome.title = nome.textContent;
    const rd = ed.estado.rotuloDesfazer;
    const rr = ed.estado.rotuloRefazer;
    desfazer.disabled = !ed.estado.podeDesfazer;
    refazer.disabled = !ed.estado.podeRefazer;
    desfazer.setAttribute('aria-label', rd ? `Desfazer: ${rd}` : 'Desfazer');
    refazer.setAttribute('aria-label', rr ? `Refazer: ${rr}` : 'Refazer');
    desfazer.title = `${rd ? `Desfazer: ${rd}` : 'Desfazer'} (${MOD}+Z)`;
    refazer.title = `${rr ? `Refazer: ${rr}` : 'Refazer'} (${MOD}+Shift+Z)`;
    computador.setAttribute('aria-pressed', ed.modo === 'computador' ? 'true' : 'false');
    celular.setAttribute('aria-pressed', ed.modo === 'celular' ? 'true' : 'false');
    publicar.title = ed.site?.publicadoEm ? 'Publicar as alterações (o site já está no ar)' : 'Colocar o site no ar';
    atualizarStatus();
  }

  return { elemento, atualizar, atualizarStatus, focarPublicar: () => publicar.focus(), focarVisualizar: () => visualizar.focus() };
}
