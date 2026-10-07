// Edição de texto no próprio site (PDF §7.2 + [M3][M4]).
//
// - Cada [data-k] vira contenteditable="plaintext-only" (a tela decora); leitura SEMPRE por
//   textContent — nunca innerText, que devolveria a caixa alta do CSS (text-transform) e
//   quebras de linha de layout.
// - Enter (e Esc) encerram; colar é sempre texto puro, numa linha, cortado no limite com aviso.
// - Contador a partir de 80% do limite do manifesto; no limite, novas letras são bloqueadas.
// - Durante a digitação o documento é atualizado num passo de histórico agrupado por campo,
//   SEM re-renderizar a seção (só o ícone automático do item e cópias do mesmo texto em outras
//   partes da página mudam). Ao confirmar: retokeniza [M4], remove a chave se igual ao padrão e
//   re-renderiza. Texto apagado volta ao padrão com aviso (Desfazer deixa vazio).

import { aviso } from '../ui.mjs';
import { iconeDoItem, svg } from '../compartilhado/icones.mjs';
import { itensLista } from '../compartilhado/textos.mjs';
import * as op from './operacoes.mjs';

function offsetsSelecao(elemento) {
  const sel = window.getSelection();
  const total = elemento.textContent.length;
  if (!sel || sel.rangeCount === 0) return [total, total];
  const r = sel.getRangeAt(0);
  if (!elemento.contains(r.startContainer) || !elemento.contains(r.endContainer)) return [total, total];
  const antes = document.createRange();
  antes.selectNodeContents(elemento);
  antes.setEnd(r.startContainer, r.startOffset);
  const ini = antes.toString().length;
  return [ini, ini + r.toString().length];
}

function colocarCursor(elemento, posicao) {
  const sel = window.getSelection();
  if (!sel) return;
  const texto = elemento.firstChild;
  const r = document.createRange();
  if (texto && texto.nodeType === 3) {
    r.setStart(texto, Math.min(posicao, texto.length));
  } else {
    r.selectNodeContents(elemento);
    r.collapse(false);
  }
  r.collapse(true);
  sel.removeAllRanges();
  sel.addRange(r);
}

export function ligarEdicaoTexto(ed) {
  const { lib } = ed;
  const alvo = ed.tela.alvo;
  /** Campo em edição: { elemento, chave, max, alterado, avisouLimite } */
  let atual = null;
  let compondo = false;

  function campoDe(t) {
    if (!(t instanceof Element) || ed.visualizando) return null;
    const e = t.closest('[data-k]');
    return e && alvo.contains(e) ? e : null;
  }

  function atualizarContador() {
    if (!atual) {
      ed.contador = null;
      ed.tela.posicionar();
      return;
    }
    const n = op.contarCaracteres(atual.elemento.textContent);
    const estado = op.estadoContador(n, atual.max);
    ed.contador = { elemento: atual.elemento, estado };
    if (estado.limite && !atual.avisouLimite) {
      atual.avisouLimite = true;
      ed.anunciar(`Limite de ${atual.max} caracteres atingido.`);
    } else if (!estado.limite) {
      atual.avisouLimite = false;
    }
    ed.tela.posicionar();
  }

  /** Ícone automático do item (enquanto digita o título) — só o <span data-ic>. */
  function atualizarIcone(chave) {
    const partes = chave.split('.');
    if (partes.length !== 3) return;
    const [lista, id, campo] = partes;
    if (campo !== ed.campoAutomatico(lista)) return;
    const doc = ed.estado.doc;
    const pos = itensLista(doc, lib, lista).indexOf(id);
    const iconeId = iconeDoItem(doc, lib, lista, id, pos < 0 ? 0 : pos);
    const marcado = svg(lib, iconeId, doc.estilo?.acabamento);
    for (const span of alvo.querySelectorAll('[data-ic]')) {
      if (span.dataset.ic !== `${lista}.${id}` || span.dataset.edIcone === iconeId) continue;
      span.innerHTML = marcado; // SVG da biblioteca (confiável, já normalizado)
      span.dataset.edIcone = iconeId;
    }
  }

  /** Lê o campo (textContent), aplica o limite e grava no documento sem re-renderizar. */
  function sincronizar() {
    if (!atual) return;
    const { elemento, chave, max } = atual;
    let texto = elemento.textContent;
    const linha = op.linhaUnica(texto);
    if (linha !== texto) {
      const [ini] = offsetsSelecao(elemento);
      elemento.textContent = linha;
      colocarCursor(elemento, ini);
      texto = linha;
    }
    if (max > 0 && op.contarCaracteres(texto) > max) {
      const c = op.cortarNoLimite(texto, max);
      elemento.textContent = c.texto;
      colocarCursor(elemento, c.texto.length);
      texto = c.texto;
      aviso(`Este campo aceita até ${max} caracteres.`, { duracao: 3500 });
    }
    atual.alterado = true;
    ed.tela.invalidar();
    ed.suprimirRender += 1;
    try {
      ed.aplicar((d) => op.textoDigitado(d, lib, chave, texto), { rotulo: 'Editar texto', agrupar: `texto:${chave}` });
    } finally {
      ed.suprimirRender -= 1;
    }
    for (const outro of ed.tela.elementosChave(chave)) {
      if (outro !== elemento && outro.textContent !== texto) outro.textContent = texto;
    }
    elemento.classList.toggle('ed-vazio', texto.trim() === '');
    atualizarIcone(chave);
    atualizarContador();
  }

  /** Insere texto (colagem ou digitação) respeitando o limite; mantém o desfazer nativo. */
  function inserir(texto, { colagem = false } = {}) {
    if (!atual) return;
    const { elemento, max } = atual;
    const [ini, fim] = offsetsSelecao(elemento);
    const r = op.inserirNoLimite(elemento.textContent, ini, fim, texto, max);
    if (r.inserido !== '' || ini !== fim) {
      let ok = false;
      try {
        ok = document.execCommand('insertText', false, r.inserido);
      } catch {
        ok = false;
      }
      if (!ok) {
        elemento.textContent = r.texto;
        colocarCursor(elemento, r.cursor);
        sincronizar();
      }
    }
    if (r.cortado) {
      if (colagem) aviso(`O texto colado foi cortado: este campo aceita até ${max} caracteres.`, { duracao: 5000 });
      atual.avisouLimite = false;
      atualizarContador();
    }
  }

  /** Encerra a edição: retokeniza, volta ao padrão se vazio, re-renderiza. */
  function confirmar() {
    if (!atual) return;
    const { elemento, chave, alterado } = atual;
    atual = null;
    elemento.classList.remove('ed-editando');
    ed.contador = null;
    if (alterado) {
      const r = op.confirmarTexto(ed.estado.doc, lib, chave, elemento.textContent);
      if (r.restaurado) {
        ed.estado.encerrarGrupo();
        ed.aplicar(() => r.doc, { rotulo: 'Restaurar texto padrão' });
        aviso('Texto restaurado ao padrão. Use Desfazer para deixar vazio.', {
          acao: { rotulo: 'Desfazer', fn: () => ed.desfazer() },
        });
      } else {
        ed.aplicar(() => r.doc, { rotulo: 'Editar texto', agrupar: `texto:${chave}` });
      }
      ed.estado.encerrarGrupo();
    }
    ed.tela.renderizar();
  }

  /* ---------------------------------------------------------------- eventos */

  function aoFocar(ev) {
    const e = campoDe(ev.target);
    if (!e || e !== ev.target) return;
    if (atual && atual.elemento !== e) confirmar();
    atual = { elemento: e, chave: e.dataset.k, max: op.limiteDaChave(lib, e.dataset.k), alterado: false, avisouLimite: false };
    e.classList.add('ed-editando');
    ed.estado.encerrarGrupo();
    atualizarContador();
  }

  function aoDesfocar(ev) {
    if (atual && ev.target === atual.elemento) confirmar();
  }

  function aoAntesDeInserir(ev) {
    if (!atual || ev.target !== atual.elemento) return;
    const tipo = ev.inputType ?? '';
    if (tipo === 'insertParagraph' || tipo === 'insertLineBreak') {
      ev.preventDefault();
      return;
    }
    if (tipo.startsWith('format')) {
      ev.preventDefault();
      return;
    }
    if (tipo === 'insertFromPaste' || tipo === 'insertFromDrop' || tipo === 'insertFromYank') {
      const texto = ev.dataTransfer?.getData('text/plain') ?? ev.data ?? '';
      ev.preventDefault();
      inserir(texto, { colagem: true });
      return;
    }
    if ((tipo === 'insertText' || tipo === 'insertReplacementText') && atual.max > 0 && !compondo) {
      const dados = ev.data ?? ev.dataTransfer?.getData('text/plain') ?? '';
      const [ini, fim] = offsetsSelecao(atual.elemento);
      const r = op.inserirNoLimite(atual.elemento.textContent, ini, fim, dados, atual.max);
      if (r.cortado) {
        ev.preventDefault();
        if (r.inserido !== '') inserir(r.inserido);
        atualizarContador();
        atual.elemento.classList.remove('ed-limite');
        void atual.elemento.offsetWidth;
        atual.elemento.classList.add('ed-limite');
      }
    }
  }

  function aoColar(ev) {
    if (!atual || !campoDe(ev.target)) return;
    ev.preventDefault();
    inserir(ev.clipboardData?.getData('text/plain') ?? '', { colagem: true });
  }

  function aoEntrada(ev) {
    if (!atual || ev.target !== atual.elemento || compondo) return;
    sincronizar();
  }

  function aoTecla(ev) {
    if (!atual || ev.target !== atual.elemento) return;
    if (ev.key === 'Enter' && !ev.isComposing) {
      ev.preventDefault();
      atual.elemento.blur();
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      ev.stopPropagation();
      atual.elemento.blur();
    }
  }

  function aoArrastar(ev) {
    // Nada de soltar HTML/arquivos dentro do texto (fotos têm o próprio caminho).
    if (campoDe(ev.target) && !ev.dataTransfer?.types?.includes('Files')) {
      ev.preventDefault();
      if (ev.type === 'drop' && atual && campoDe(ev.target) === atual.elemento) {
        inserir(ev.dataTransfer.getData('text/plain') ?? '', { colagem: true });
      }
    }
  }

  const aoComecarComposicao = () => { compondo = true; };
  const aoTerminarComposicao = () => {
    compondo = false;
    sincronizar();
  };

  alvo.addEventListener('focusin', aoFocar);
  alvo.addEventListener('focusout', aoDesfocar);
  alvo.addEventListener('beforeinput', aoAntesDeInserir);
  alvo.addEventListener('paste', aoColar);
  alvo.addEventListener('input', aoEntrada);
  alvo.addEventListener('keydown', aoTecla);
  alvo.addEventListener('drop', aoArrastar);
  alvo.addEventListener('compositionstart', aoComecarComposicao);
  alvo.addEventListener('compositionend', aoTerminarComposicao);

  return {
    get chaveEmEdicao() {
      return atual?.chave ?? null;
    },
    /** Confirma o campo em edição (antes de publicar, trocar de modo etc.). */
    confirmar() {
      if (atual) atual.elemento.blur();
    },
    desligar() {
      alvo.removeEventListener('focusin', aoFocar);
      alvo.removeEventListener('focusout', aoDesfocar);
      alvo.removeEventListener('beforeinput', aoAntesDeInserir);
      alvo.removeEventListener('paste', aoColar);
      alvo.removeEventListener('input', aoEntrada);
      alvo.removeEventListener('keydown', aoTecla);
      alvo.removeEventListener('drop', aoArrastar);
      alvo.removeEventListener('compositionstart', aoComecarComposicao);
      alvo.removeEventListener('compositionend', aoTerminarComposicao);
    },
  };
}
