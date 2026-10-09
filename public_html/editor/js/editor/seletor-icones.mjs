// Seletor manual de ícones (PDF §8.3): busca, "Automático" primeiro, ícones da categoria do
// negócio primeiro. A escolha fica em icones["{lista}.{id}"]; "Automático" remove a chave.
// Embaixo, a mesma busca no Iconify (GET /api/icones/buscar): mais de 200 mil ícones de coleções
// abertas; o escolhido vai com o desenho para iconesExtras (o site não depende do Iconify).
// Teclado: Tab chega à busca e às grades; setas/Home/End andam pela grade; Enter/Espaço escolhem.

import api from '../api.mjs';
import { el, modal } from '../ui.mjs';
import { iconeDoItem, escolherIcone, svgDaDefinicao, acharIcone, iconeExtra } from '../compartilhado/icones.mjs';
import { idIconeExtraValido } from '../compartilhado/documento.mjs';
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

  function escolher(iconeId, extra = null) {
    const mudou = ed.aplicar((d) => op.definirIcone(d, chaveItem, iconeId, extra), {
      rotulo: iconeId ? 'Trocar ícone' : 'Ícone automático',
    });
    janela?.fechar('escolhido');
    if (mudou) ed.anunciar(iconeId ? 'Ícone trocado.' : 'Ícone automático.');
  }

  function botao({ iconeId, nome, marcacao, ativo, classe = '', extra = null, titulo = nome, detalhe = '' }) {
    return el('button', {
      type: 'button',
      class: ['ed-icones__item', classe],
      'aria-pressed': ativo ? 'true' : 'false',
      tabindex: '-1',
      title: titulo,
      onclick: () => escolher(iconeId, extra),
    }, svgSeguro(marcacao), el('span', { class: 'ed-icones__nome' }, nome), detalhe ? el('span', { class: 'ed-icones__colecao' }, detalhe) : null);
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
    // Ícone do Iconify escolhido antes: aparece logo depois do "Automático".
    const escolhidoExtra = idIconeExtraValido(manual) ? iconeExtra(doc, manual) : null;
    if (escolhidoExtra && termo.trim() === '') {
      botoes.push(botao({ iconeId: manual, nome: escolhidoExtra.nome ?? manual, marcacao: svgDaDefinicao(escolhidoExtra, acabamento), ativo: true, detalhe: 'Iconify' }));
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

  /* ---------- Iconify: a mesma busca em mais de 200 mil ícones (servidor filtra e limpa) */
  const gradeIconify = el('div', { class: 'ed-icones__grade ed-icones__grade--iconify', role: 'group', 'aria-label': 'Ícones do Iconify', hidden: true });
  const estadoIconify = el('p', { class: 'ed-icones__estado', role: 'status' }, 'Digite acima para buscar também entre mais de 200 mil ícones gratuitos.');
  const secaoIconify = el('section', { class: 'ed-icones__iconify', 'aria-labelledby': 'ed-icones-iconify-t' },
    el('div', { class: 'ed-icones__iconify-cab' },
      el('h3', { class: 'ed-icones__iconify-titulo', id: 'ed-icones-iconify-t' }, 'Mais ícones'),
      el('span', { class: 'ed-icones__iconify-fonte' }, 'via Iconify · coleções abertas (Phosphor, Material, Tabler…)')),
    estadoIconify,
    gradeIconify);
  let pedidoIconify = 0;
  let esperaIconify = 0;
  let fechada = false;

  function desenharIconify(icones, colecoes) {
    const botoes = icones.map((i) => botao({
      iconeId: i.id,
      nome: i.nome,
      titulo: `${i.nome} · ${colecoes[i.colecao] ?? i.colecao}`,
      detalhe: colecoes[i.colecao] ?? i.colecao,
      marcacao: svgDaDefinicao(i, acabamento),
      ativo: manual === i.id,
      extra: { nome: i.nome, svg: i.svg },
    }));
    gradeIconify.replaceChildren(...botoes);
    gradeIconify.hidden = botoes.length === 0;
    const ativo = botoes.find((b) => b.getAttribute('aria-pressed') === 'true') ?? botoes[0];
    if (ativo) ativo.tabIndex = 0;
  }

  async function buscarIconify() {
    const termo = busca.value.trim();
    const meu = ++pedidoIconify;
    if (termo.length < 2) {
      desenharIconify([], {});
      estadoIconify.textContent = 'Digite acima para buscar também entre mais de 200 mil ícones gratuitos.';
      return;
    }
    estadoIconify.textContent = `Buscando "${termo}" no Iconify…`;
    // Dicas em inglês: nomes dos ícones da biblioteca que casaram com o termo (ex.: dente → tooth).
    const dicas = op.listarIcones(lib, { categoria, busca: termo }).slice(0, 3).map((i) => i.id);
    try {
      const r = await api.get(`/icones/buscar?q=${encodeURIComponent(termo)}${dicas.length ? `&dicas=${encodeURIComponent(dicas.join(','))}` : ''}`);
      if (meu !== pedidoIconify || fechada) return;
      const icones = Array.isArray(r?.icones) ? r.icones : [];
      desenharIconify(icones, r?.colecoes ?? {});
      estadoIconify.textContent = icones.length > 0
        ? `${icones.length} ícones encontrados. O escolhido fica guardado no site e segue a cor dele.`
        : `Nada encontrado para "${termo}". Tente outra palavra (em inglês costuma achar mais: "tooth", "house", "car").`;
    } catch (e) {
      if (meu !== pedidoIconify) return;
      desenharIconify([], {});
      if (e?.status === 503 && e?.codigo === 'iconify_desligado') {
        secaoIconify.hidden = true;
        return;
      }
      estadoIconify.textContent = e?.mensagem ?? 'Não foi possível buscar no Iconify agora. Tente de novo.';
    }
  }

  function agendarIconify() {
    clearTimeout(esperaIconify);
    esperaIconify = setTimeout(buscarIconify, 450);
  }

  // Grade com "tabindex móvel": uma parada de Tab, setas para andar.
  for (const g of [grade, gradeIconify]) g.addEventListener('keydown', (ev) => navegarGrade(g, ev));
  function navegarGrade(grade, ev) {
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
  }
  busca.addEventListener('input', () => {
    desenhar();
    agendarIconify();
  });
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
    corpo: el('div', { class: 'ed-icones' }, busca, grade, vazio, secaoIconify),
    classe: 'ed-icones-modal',
  });
  janela.promessa?.then(() => {
    fechada = true;
    clearTimeout(esperaIconify);
  });
  return janela;
}
