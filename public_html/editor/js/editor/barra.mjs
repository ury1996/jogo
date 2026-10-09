// Barra superior do editor (PDF §7.1): voltar ao painel · nome e status de salvamento ·
// Computador/Celular · desfazer/refazer · histórico · Visualizar · Publicar.

import { el, icone, modal, aviso } from '../ui.mjs';
import { contextoVariaveis } from '../compartilhado/textos.mjs';
import { mostrarFaixaIa, faixaDispensada, dispensarFaixa } from '../ia.mjs';

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
  const ia = el('button', {
    type: 'button', class: 'btn btn--ia ed-barra__ia', hidden: true, 'aria-haspopup': 'dialog',
    title: 'Escrever os textos do site com IA', onclick: () => ed.abrirIa(),
  }, icone('brilho'), el('span', { class: 'ed-barra__rotulo' }, 'Escrever com IA'));
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
      historico, ia, visualizar, publicar));

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

  return { elemento, mostrarIa: (sim) => { ia.hidden = !sim; }, atualizar, atualizarStatus, focarPublicar: () => publicar.focus(), focarVisualizar: () => visualizar.focus() };
}

/**
 * Faixa acima da tela num site cujos textos ainda são todos de exemplo:
 * "Os textos ainda são de exemplo…" + botão que abre a janela da IA. Dispensável (lembrada por site).
 */
export function criarFaixaIa(ed) {
  let dispensada = faixaDispensada(ed.siteId);
  const abrir = el('button', { type: 'button', class: 'btn btn--pequeno btn--ia', 'aria-haspopup': 'dialog', onclick: () => ed.abrirIa() },
    icone('brilho'), 'Escrever com IA');
  const fechar = el('button', {
    type: 'button', class: 'icone-btn icone-btn--pequeno ed-faixa-ia__fechar', 'aria-label': 'Dispensar este aviso', title: 'Dispensar',
  }, icone('fechar'));
  const elemento = el('div', { class: 'ed-faixa-ia', role: 'region', 'aria-label': 'Sugestão: escrever os textos com IA', hidden: true },
    el('span', { class: 'ed-faixa-ia__ico', 'aria-hidden': 'true' }, icone('brilho')),
    el('p', { class: 'ed-faixa-ia__texto' },
      el('strong', null, 'Os textos ainda são de exemplo.'), ' Conte sobre o negócio e a IA escreve o site para você.'),
    el('div', { class: 'ed-faixa-ia__acoes' }, abrir, fechar));

  /** Mostra ou esconde; → true se mudou (a tela precisa se reposicionar). */
  function atualizar() {
    const mostrar = mostrarFaixaIa({ doc: ed.estado.doc, ia: ed.iaDisponivel, dispensada });
    if (elemento.hidden === !mostrar) return false;
    // Se o foco estava dentro da faixa que vai sumir, ele não pode se perder.
    const tinhaFoco = elemento.contains(document.activeElement);
    elemento.hidden = !mostrar;
    if (tinhaFoco && !mostrar) (document.querySelector('.ed-barra__ia:not([hidden])') ?? ed.painel?.elemento?.querySelector('button'))?.focus();
    return true;
  }
  fechar.addEventListener('click', () => {
    dispensada = true;
    dispensarFaixa(ed.siteId);
    atualizar();
    ed.tela?.posicionar?.();
    ed.anunciar?.('Aviso dispensado. O botão "Escrever com IA" continua na barra de cima.');
  });
  return { elemento, atualizar };
}
