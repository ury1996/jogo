// Aba Estilo (PDF §7.5): cor principal (9 sugestões + livre, faixa da paleta gerada, aviso de
// cor clara), acabamento (3 cards), fontes (4 cards com "Aa" na fonte real) e o botão
// flutuante de WhatsApp. A cor entra no histórico quando a escolha termina (change), não a
// cada movimento do seletor — mas a prévia acompanha ao vivo.

import { el, icone, CORES_SUGERIDAS } from '../ui.mjs';
import { gerarPaleta, normalizarCor, textoSobre } from '../compartilhado/paleta.mjs';
import { comoMapa } from '../compartilhado/texto.mjs';

const ACABAMENTOS = [
  { id: 'classico', nome: 'Clássico', descricao: 'Cantos retos e linhas finas' },
  { id: 'moderno', nome: 'Moderno', descricao: 'Cantos arredondados e blocos suaves' },
  { id: 'direto', nome: 'Direto', descricao: 'Blocos de cor e botões fortes' },
];
const TOKENS_FAIXA = ['--p', '--p-dark', '--p-soft2', '--p-soft', '--p-tint', '--deep'];

function titulo(texto, id) {
  return el('h3', { class: 'ed-painel__titulo', id }, texto);
}

export function criarPainelEstilo(ed) {
  const { lib } = ed;

  /* ---------------------------------------------------------------- cor */

  const bolinhas = CORES_SUGERIDAS.map(({ cor, nome }) => el('button', {
    type: 'button', class: 'cor-bolinha', style: { '--cor': cor, '--cor-check': textoSobre(cor) }, 'aria-label': nome, title: nome, 'data-cor': cor,
    onclick: () => definirCor(cor, { final: true }),
  }));
  const entradaLivre = el('input', { type: 'color', 'aria-label': 'Escolher outra cor', value: '#c23b6e' });
  const livre = el('label', { class: 'cor-bolinha cor-bolinha--livre', title: 'Outra cor' }, entradaLivre);
  const faixa = el('div', { class: 'cor-faixa', 'aria-hidden': 'true' });
  const hex = el('span', { class: 'ed-estilo__hex' });
  const avisoClara = el('p', { class: 'aviso-inline aviso-inline--alerta', role: 'status', hidden: true },
    icone('alerta'),
    el('span', null, 'Esta cor é bem clara. Para manter a leitura, os textos e detalhes finos usam um tom mais escuro dela. Prefira um tom mais forte para botões que chamam atenção.'));

  function definirCor(cor, { final }) {
    const c = normalizarCor(cor);
    if (!c) return;
    if (final) ed.estado.encerrarGrupo();
    ed.aplicar((d) => ({ ...d, estilo: { ...comoMapa(d.estilo), cor: c } }), { rotulo: 'Trocar cor', agrupar: 'estilo.cor' });
    if (final) ed.estado.encerrarGrupo();
  }
  entradaLivre.addEventListener('input', () => definirCor(entradaLivre.value, { final: false }));
  entradaLivre.addEventListener('change', () => {
    definirCor(entradaLivre.value, { final: false });
    ed.estado.encerrarGrupo();
  });

  /* ---------------------------------------------------------------- acabamento */

  const cardsAcabamento = ACABAMENTOS.map((a) => el('button', {
    type: 'button', class: 'ed-escolha ed-escolha--acabamento', 'data-valor': a.id, 'aria-pressed': 'false',
    onclick: () => definirEstilo('acabamento', a.id, 'Trocar acabamento'),
  },
  el('span', { class: `ed-escolha__amostra ed-amostra--${a.id}`, 'aria-hidden': 'true' }, el('span'), el('span')),
  el('span', { class: 'ed-escolha__textos' },
    el('span', { class: 'ed-escolha__nome' }, a.nome),
    el('span', { class: 'ed-escolha__desc' }, a.descricao))));

  /* ---------------------------------------------------------------- fontes */

  const pares = comoMapa(comoMapa(lib.fontes).pares);
  const cardsFonte = Object.entries(pares).map(([id, par]) => el('button', {
    type: 'button', class: 'ed-escolha ed-escolha--fonte', 'data-valor': id, 'aria-pressed': 'false',
    onclick: () => definirEstilo('fonte', id, 'Trocar fontes'),
  },
  el('span', { class: 'ed-escolha__aa', style: { fontFamily: `"${par.titulos}", Georgia, serif` }, 'aria-hidden': 'true' }, 'Aa'),
  el('span', { class: 'ed-escolha__nome' }, par.nome ?? id),
  el('span', { class: 'ed-escolha__desc' }, par.descricao ?? '')));

  /* ---------------------------------------------------------------- WhatsApp */

  const alternarWa = el('input', {
    type: 'checkbox', role: 'switch',
    onchange: () => definirEstilo('whatsappFlutuante', alternarWa.checked, alternarWa.checked ? 'Mostrar botão de WhatsApp' : 'Esconder botão de WhatsApp'),
  });

  function definirEstilo(campo, valor, rotulo) {
    ed.estado.encerrarGrupo();
    ed.aplicar((d) => ({ ...d, estilo: { ...comoMapa(d.estilo), [campo]: valor } }), { rotulo });
  }

  const elemento = el('div', { class: 'ed-painel-estilo' },
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-cor' },
      titulo('Cor principal', 'ed-t-cor'),
      el('div', { class: 'cor-bolinhas', role: 'group', 'aria-labelledby': 'ed-t-cor' }, bolinhas, livre),
      faixa,
      el('p', { class: 'ed-painel__ajuda' }, 'Paleta gerada automaticamente a partir da cor escolhida. ', hex),
      avisoClara),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-acab' },
      titulo('Acabamento', 'ed-t-acab'),
      el('div', { class: 'ed-escolhas', role: 'group', 'aria-labelledby': 'ed-t-acab' }, cardsAcabamento),
      el('p', { class: 'ed-painel__ajuda' }, 'Muda cantos, sombras, rótulos, o estilo dos ícones e os detalhes atrás das fotos.')),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-fontes' },
      titulo('Fontes', 'ed-t-fontes'),
      el('div', { class: 'ed-escolhas ed-escolhas--duas', role: 'group', 'aria-labelledby': 'ed-t-fontes' }, cardsFonte)),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-wa' },
      titulo('Botão de WhatsApp', 'ed-t-wa'),
      el('label', { class: 'alternar' }, alternarWa, 'Mostrar o botão flutuante'),
      el('p', { class: 'ed-painel__ajuda' }, 'Fica no canto da tela, em todas as páginas, quando há um WhatsApp válido.')));

  function atualizar() {
    const estilo = comoMapa(ed.estado.doc.estilo);
    const cor = normalizarCor(estilo.cor) ?? '#c23b6e';
    let sugerida = false;
    for (const b of bolinhas) {
      const sim = b.dataset.cor === cor;
      sugerida ||= sim;
      b.setAttribute('aria-pressed', sim ? 'true' : 'false');
    }
    livre.setAttribute('aria-pressed', sugerida ? 'false' : 'true');
    livre.style.setProperty('--cor', cor);
    livre.style.setProperty('--cor-check', textoSobre(cor));
    if (document.activeElement !== entradaLivre) entradaLivre.value = cor;
    const { tokens, claraDemais } = gerarPaleta(cor);
    faixa.replaceChildren(...TOKENS_FAIXA.map((t) => el('span', { style: { background: tokens[t] } })));
    hex.textContent = cor.toUpperCase();
    avisoClara.hidden = !claraDemais;
    for (const c of cardsAcabamento) c.setAttribute('aria-pressed', c.dataset.valor === estilo.acabamento ? 'true' : 'false');
    for (const c of cardsFonte) c.setAttribute('aria-pressed', c.dataset.valor === estilo.fonte ? 'true' : 'false');
    alternarWa.checked = estilo.whatsappFlutuante !== false;
  }

  return { elemento, atualizar };
}
