// Seletor manual de ícones (PDF §8.3): busca, "Automático" primeiro, ícones da categoria do
// negócio primeiro. A escolha fica em icones["{lista}.{id}"]; "Automático" remove a chave.
// Teclado: Tab chega à busca e à grade; setas/Home/End andam pela grade; Enter/Espaço escolhem.

import { el, modal } from '../ui.mjs';
import { iconeDoItem, escolherIcone, svgDaDefinicao, acharIcone } from '../compartilhado/icones.mjs';
import { itensLista } from '../compartilhado/textos.mjs';
import { comoMapa } from '../compartilhado/texto.mjs';
import * as op from './operacoes.mjs';

function svgSeguro(marcacao) {
  // SVG da biblioteca (normalizado e sem scripts na construção); entra como HTML.
  const s = el('span', { class: 'ed-icones__svg', 'aria-hidden': 'true' });
  s.innerHTML = marcacao;
  return s;
}

export function abrirSeletorIcone(ed, chaveItem) {
  const { lib } = ed;
  const doc = ed.estado.doc;
  const [lista, id] = String(chaveItem).split('.');
  const acabamento = doc.estilo?.acabamento ?? 'moderno';
  const manual = comoMapa(doc.icones)[chaveItem] ?? null;
  const campoTitulo = ed.campoAutomatico(lista);
  const titulo = op.textoMostrado(doc, lib, `${lista}.${id}.${campoTitulo}`);
  // Ícone que o automático escolheria (sem a escolha manual).
  const semManual = op.definirIcone(doc, chaveItem, null);
  const pos = Math.max(0, itensLista(doc, lib, lista).indexOf(id));
  const automatico = iconeDoItem(semManual, lib, lista, id, pos);
  const casouPeloTitulo = escolherIcone(titulo, comoMapa(lib).icones) !== null;
  const categoria = op.categoriaIcones(doc, lib);

  const busca = el('input', {
    type: 'search',
    class: 'campo__entrada ed-icones__busca',
    placeholder: 'Buscar ícone (ex.: dente, contrato, energia)',
    'aria-label': 'Buscar ícone',
    autocomplete: 'off',
    spellcheck: 'false',
    'data-foco-inicial': '',
  });
  const grade = el('div', { class: 'ed-icones__grade', role: 'group', 'aria-label': 'Ícones' });
  const vazio = el('p', { class: 'ed-icones__vazio', hidden: true, role: 'status' });
  let janela = null;

  function escolher(iconeId) {
    const mudou = ed.aplicar((d) => op.definirIcone(d, chaveItem, iconeId), {
      rotulo: iconeId ? 'Trocar ícone' : 'Ícone automático',
    });
    janela?.fechar('escolhido');
    if (mudou) ed.anunciar(iconeId ? 'Ícone trocado.' : 'Ícone automático.');
  }

  function botao({ iconeId, nome, marcacao, ativo, classe = '' }) {
    return el('button', {
      type: 'button',
      class: ['ed-icones__item', classe],
      'aria-pressed': ativo ? 'true' : 'false',
      tabindex: '-1',
      title: nome,
      onclick: () => escolher(iconeId),
    }, svgSeguro(marcacao), el('span', { class: 'ed-icones__nome' }, nome));
  }

  function desenhar() {
    const termo = busca.value;
    const lista = op.listarIcones(lib, { categoria, busca: termo });
    const botoes = [];
    {
      const def = acharIcone(lib, automatico);
      botoes.push(botao({
        iconeId: null,
        nome: 'Automático',
        marcacao: def ? svgDaDefinicao(def, acabamento) : '',
        ativo: manual === null,
        classe: 'ed-icones__item--auto',
      }));
    }
    for (const i of lista) {
      botoes.push(botao({ iconeId: i.id, nome: i.nome ?? i.id, marcacao: svgDaDefinicao(i, acabamento), ativo: manual === i.id }));
    }
    grade.replaceChildren(...botoes);
    vazio.hidden = lista.length > 0;
    vazio.textContent = lista.length > 0 ? '' : `Nenhum ícone encontrado para "${termo.trim()}". Tente outra palavra, como "dente", "contrato" ou "energia".`;
    const ativo = botoes.find((b) => b.getAttribute('aria-pressed') === 'true') ?? botoes[0];
    if (ativo) ativo.tabIndex = 0;
  }

  // Grade com "tabindex móvel": uma parada de Tab, setas para andar.
  grade.addEventListener('keydown', (ev) => {
    const itens = [...grade.querySelectorAll('.ed-icones__item')];
    const i = itens.indexOf(document.activeElement);
    if (i < 0) return;
    const colunas = Math.max(1, Math.round(grade.clientWidth / (itens[0].offsetWidth || 1)));
    let j = i;
    if (ev.key === 'ArrowRight') j = i + 1;
    else if (ev.key === 'ArrowLeft') j = i - 1;
    else if (ev.key === 'ArrowDown') j = i + colunas;
    else if (ev.key === 'ArrowUp') j = i - colunas;
    else if (ev.key === 'Home') j = 0;
    else if (ev.key === 'End') j = itens.length - 1;
    else return;
    ev.preventDefault();
    j = Math.max(0, Math.min(itens.length - 1, j));
    itens[i].tabIndex = -1;
    itens[j].tabIndex = 0;
    itens[j].focus();
  });
  busca.addEventListener('input', desenhar);
  busca.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      grade.querySelector('[tabindex="0"]')?.focus();
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const primeiro = grade.querySelector('.ed-icones__item:not(.ed-icones__item--auto)');
      if (primeiro && busca.value.trim() !== '') primeiro.click();
    }
  });

  desenhar();
  const descricao = titulo
    ? `Para "${titulo}". No automático, o ícone muda sozinho quando o título é editado.`
    : 'No automático, o ícone muda sozinho quando o título é editado.';
  janela = modal({
    titulo: 'Escolher ícone',
    descricao: casouPeloTitulo || !titulo ? descricao : `${descricao} Nenhuma palavra do título casou com um ícone; o automático usa o padrão do seu tipo de negócio.`,
    corpo: el('div', { class: 'ed-icones' }, busca, grade, vazio),
    classe: 'ed-icones-modal',
  });
  return janela;
}
