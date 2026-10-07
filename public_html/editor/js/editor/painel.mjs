// Painel lateral (320 px; no celular vai para cima, até 40% da altura) com as abas
// Seções, Estilo, Dados e Config. — padrão de abas WAI-ARIA (setas/Home/End trocam de aba).

import { el, icone } from '../ui.mjs';
import { criarPainelSecoes } from './painel-secoes.mjs';
import { criarPainelEstilo } from './painel-estilo.mjs';
import { criarPainelDados } from './painel-dados.mjs';
import { criarPainelConfig } from './painel-config.mjs';

export function criarPainel(ed) {
  const definicoes = [
    { id: 'secoes', rotulo: 'Seções', icone: 'secoes', criar: criarPainelSecoes },
    { id: 'estilo', rotulo: 'Estilo', icone: 'pincel', criar: criarPainelEstilo },
    { id: 'dados', rotulo: 'Dados', icone: 'dados', criar: criarPainelDados },
    { id: 'config', rotulo: 'Config.', icone: 'config', criar: criarPainelConfig, nomeAcessivel: 'Configurações' },
  ];
  const abas = el('div', { class: 'abas ed-abas', role: 'tablist', 'aria-label': 'Painel do editor' });
  const corpo = el('div', { class: 'editor__painel-corpo ed-painel__corpo' });
  const paineis = {};
  let atual = 'secoes';

  for (const d of definicoes) {
    const modulo = d.criar(ed);
    const botao = el('button', {
      type: 'button', class: 'aba', role: 'tab', id: `ed-aba-${d.id}`, 'aria-controls': `ed-painel-${d.id}`,
      'aria-selected': d.id === atual ? 'true' : 'false', tabindex: d.id === atual ? '0' : '-1',
      'aria-label': d.nomeAcessivel ?? null,
      onclick: () => abrirAba(d.id),
    }, icone(d.icone), d.rotulo);
    const painel = el('div', {
      role: 'tabpanel', id: `ed-painel-${d.id}`, 'aria-labelledby': `ed-aba-${d.id}`, tabindex: '0',
      class: 'ed-painel__aba', hidden: d.id !== atual,
    }, modulo.elemento);
    abas.append(botao);
    corpo.append(painel);
    paineis[d.id] = { botao, painel, modulo, sujo: true };
  }

  abas.addEventListener('keydown', (ev) => {
    const ids = definicoes.map((d) => d.id);
    const i = ids.indexOf(atual);
    let j = null;
    if (ev.key === 'ArrowRight') j = (i + 1) % ids.length;
    else if (ev.key === 'ArrowLeft') j = (i - 1 + ids.length) % ids.length;
    else if (ev.key === 'Home') j = 0;
    else if (ev.key === 'End') j = ids.length - 1;
    if (j === null) return;
    ev.preventDefault();
    abrirAba(ids[j]);
    paineis[ids[j]].botao.focus();
  });

  const elemento = el('aside', { class: 'editor__painel ed-painel', 'aria-label': 'Painel do editor' }, abas, corpo);

  function abrirAba(id, { campo = null } = {}) {
    if (!paineis[id]) return;
    if (id !== atual) {
      for (const [k, p] of Object.entries(paineis)) {
        const sim = k === id;
        p.botao.setAttribute('aria-selected', sim ? 'true' : 'false');
        p.botao.tabIndex = sim ? 0 : -1;
        p.painel.hidden = !sim;
      }
      atual = id;
      corpo.scrollTop = 0;
    }
    const p = paineis[id];
    if (p.sujo) {
      p.modulo.atualizar();
      p.sujo = false;
    }
    if (campo) requestAnimationFrame(() => p.modulo.focarCampo?.(campo));
  }

  /** Documento mudou: atualiza a aba visível agora e as outras quando forem abertas. */
  function atualizar() {
    for (const [k, p] of Object.entries(paineis)) {
      if (k === atual) {
        p.modulo.atualizar();
        p.sujo = false;
      } else {
        p.sujo = true;
      }
    }
  }

  return {
    elemento,
    atualizar,
    abrirAba,
    get abaAtual() {
      return atual;
    },
    secoes: paineis.secoes.modulo,
  };
}
