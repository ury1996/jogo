// Publicar ([M5][M6], PDF cap. 9): salvar → POST /validar → checklist (erros que bloqueiam
// com "Ir até lá", alegações com caixa de confirmação → doc.confirmados — depoimento nunca —,
// avisos, textos padrão alterados antes/depois) → POST /publicar → janela com o link
// (copiar/abrir) e "Voltar à publicação anterior" (POST /reverter, com confirmação).

import { api } from '../api.mjs';
import { el, icone, modal, aviso, confirmar } from '../ui.mjs';
import * as op from './operacoes.mjs';

function item(texto, { tipo = 'erro', acao = null, extra = null } = {}) {
  const icones = { erro: 'alerta', aviso: 'info', ok: 'ok' };
  return el('li', { class: ['ed-check__item', `ed-check__item--${tipo}`] },
    icone(icones[tipo] ?? 'info', { classe: 'ed-check__ico' }),
    el('div', { class: 'ed-check__texto' }, el('p', null, texto), extra),
    acao);
}

function botaoIr(ed, janela, problema) {
  const destino = op.destinoDoProblema(problema);
  if (!destino) return null;
  return el('button', {
    type: 'button',
    class: 'btn btn--pequeno ed-check__ir',
    onclick: () => {
      janela.fechar('ir');
      // depois que a janela devolve o foco, leva até o lugar
      setTimeout(() => ed.irPara(destino), 0);
    },
  }, 'Ir até lá', icone('direita'));
}

/** Rótulo legível de uma chave de texto ("hero.titulo" → "Destaque · Título principal"). */
function rotuloChave(ed, chave) {
  const def = op.definicaoDaChave(ed.lib, chave);
  const dono = def?.dono ? op.nomeSecao(ed.lib, def.dono) : '';
  const campo = def?.rotulo ?? chave;
  return dono ? `${dono} · ${campo}` : campo;
}

/** Janela de sucesso com o link do site. */
function janelaPublicado(ed, { url, versao }) {
  const link = el('a', { href: url, target: '_blank', rel: 'noopener', class: 'ed-publicado__link' }, url.replace(/^https?:\/\//, ''));
  const copiar = el('button', {
    type: 'button', class: 'btn btn--pequeno',
    onclick: async () => {
      try {
        await navigator.clipboard.writeText(url);
        aviso('Link copiado.', { tipo: 'ok' });
      } catch {
        aviso(`Copie o link: ${url}`);
      }
    },
  }, icone('duplicar'), 'Copiar link');
  const abrir = el('a', { href: url, target: '_blank', rel: 'noopener', class: 'btn btn--pequeno btn--primario' }, icone('abrir'), 'Abrir site');
  const corpo = el('div', { class: 'ed-publicado' },
    el('div', { class: 'ed-publicado__selo', 'aria-hidden': 'true' }, icone('ok')),
    el('p', { class: 'modal__texto' }, versao > 1
      ? `A versão ${versao} já está no ar. Quem abrir o link vê o site novo.`
      : 'Seu site já está no ar. Compartilhe o link com seus clientes.'),
    el('div', { class: 'ed-publicado__caixa' }, icone('globo'), link),
    el('div', { class: 'ed-publicado__acoes' }, copiar, abrir));
  const acoes = [];
  if (versao > 1) {
    acoes.push({
      rotulo: 'Voltar à publicação anterior',
      tipo: 'fantasma',
      icone: 'historico',
      fn: async () => {
        const ok = await reverterPublicacao(ed);
        return ok ? undefined : false;
      },
    });
  }
  acoes.push({ rotulo: 'Continuar editando', tipo: 'primario', foco: true });
  return modal({ titulo: 'Site publicado!', corpo, acoes, tamanho: 'pequeno', classe: 'ed-publicado-modal' });
}

/** POST /reverter (com confirmação). → true se voltou. */
export async function reverterPublicacao(ed) {
  const ok = await confirmar(
    'O link volta a mostrar a publicação anterior. O que você está editando não muda e pode ser publicado de novo quando quiser.',
    { titulo: 'Voltar à publicação anterior?', confirmar: 'Voltar à anterior' },
  );
  if (!ok) return false;
  try {
    const r = await api.post(`/sites/${encodeURIComponent(ed.siteId)}/reverter`);
    ed.site.publicadoVersao = r?.versao ?? ed.site.publicadoVersao;
    aviso(`Pronto: o site voltou para a versão ${r?.versao ?? 'anterior'}.`, { tipo: 'ok' });
    ed.emitir('site');
    return true;
  } catch (e) {
    aviso(e?.mensagem ?? 'Não foi possível voltar à publicação anterior.', { tipo: 'erro' });
    return false;
  }
}

/** Monta o corpo do checklist. → { corpo, marcados, atualizarBotao } */
function corpoChecklist(ed, janelaRef, organizado, aoMudar) {
  const marcados = new Set();
  const partes = [];
  const { bloqueios, alegacoes, avisos, textos } = organizado;

  if (bloqueios.length === 0 && alegacoes.length === 0 && avisos.length === 0 && textos.length === 0) {
    partes.push(el('div', { class: 'aviso-inline aviso-inline--ok' }, icone('ok'),
      el('span', null, 'Tudo certo. O site está pronto para ir ao ar.')));
  }
  if (bloqueios.length > 0) {
    partes.push(el('section', { class: 'ed-check__grupo' },
      el('h3', { class: 'ed-check__titulo' }, `Resolva antes de publicar (${bloqueios.length})`),
      el('ul', { class: 'ed-check__lista' }, bloqueios.map((b) => item(b.mensagem, {
        tipo: 'erro',
        acao: botaoIr(ed, janelaRef, b),
      })))));
  }
  if (alegacoes.length > 0) {
    partes.push(el('section', { class: 'ed-check__grupo' },
      el('h3', { class: 'ed-check__titulo' }, 'Confirme que é verdade'),
      el('p', { class: 'ed-check__ajuda' }, 'Estes textos ainda são os de exemplo. Publique só o que for verdadeiro sobre o seu negócio — ou edite antes.'),
      el('ul', { class: 'ed-check__lista' }, alegacoes.map((a) => {
        const caixa = el('input', {
          type: 'checkbox',
          onchange: () => {
            if (caixa.checked) marcados.add(a.grupo);
            else marcados.delete(a.grupo);
            aoMudar();
          },
        });
        return item(a.mensagem, {
          tipo: 'aviso',
          extra: el('label', { class: 'campo__check ed-check__confirmo' }, caixa,
            el('span', null, op.textoConfirmacao(a.grupo))),
          acao: botaoIr(ed, janelaRef, a),
        });
      }))));
  }
  if (avisos.length > 0) {
    partes.push(el('section', { class: 'ed-check__grupo' },
      el('h3', { class: 'ed-check__titulo' }, 'Vale conferir'),
      el('ul', { class: 'ed-check__lista' }, avisos.map((a) => item(a.mensagem, { tipo: 'aviso', acao: botaoIr(ed, janelaRef, a) })))));
  }
  if (textos.length > 0) {
    partes.push(el('section', { class: 'ed-check__grupo' },
      el('h3', { class: 'ed-check__titulo' }, 'Textos do modelo que mudaram desde a última publicação'),
      el('p', { class: 'ed-check__ajuda' }, 'Você não editou estes textos; eles vêm do modelo, que foi atualizado.'),
      el('ul', { class: 'ed-check__lista ed-check__lista--textos' }, textos.map((t) => el('li', { class: 'ed-check__texto-alterado' },
        el('p', { class: 'ed-check__chave' }, rotuloChave(ed, t.chave)),
        el('p', { class: 'ed-check__antes' }, el('span', { class: 'sr-only' }, 'Antes: '), t.antes ?? ''),
        el('p', { class: 'ed-check__depois' }, el('span', { class: 'sr-only' }, 'Depois: '), t.depois ?? ''))))));
  }
  return { corpo: el('div', { class: 'ed-check' }, partes), marcados };
}

/** Fluxo completo do botão Publicar. */
export async function fluxoPublicar(ed, botao = null) {
  ed.confirmarEdicao();
  botao?.setAttribute('aria-busy', 'true');
  let resposta;
  try {
    const salvo = await ed.estado.salvar();
    if (!salvo && ed.estado.status === 'conflito') {
      aviso('Resolva o conflito de versões antes de publicar.', { tipo: 'erro' });
      return;
    }
    if (!salvo) {
      aviso('Não foi possível salvar as últimas alterações. Verifique a conexão e tente de novo.', { tipo: 'erro' });
      return;
    }
    resposta = await api.post(`/sites/${encodeURIComponent(ed.siteId)}/validar`);
  } catch (e) {
    aviso(e?.mensagem ?? 'Não foi possível conferir o site agora.', { tipo: 'erro' });
    return;
  } finally {
    botao?.removeAttribute('aria-busy');
  }
  abrirChecklist(ed, op.organizarValidacao(resposta));
}

function abrirChecklist(ed, organizado) {
  const janelaRef = { fechar: (r) => janela?.fechar(r) };
  let janela = null;
  let botaoPublicar = null;
  const { corpo, marcados } = corpoChecklist(ed, janelaRef, organizado, () => atualizarBotao());
  const resumo = el('p', { class: 'ed-check__resumo', role: 'status' });

  function atualizarBotao() {
    const pode = op.podePublicar(organizado, marcados);
    if (botaoPublicar) {
      botaoPublicar.disabled = !pode;
      botaoPublicar.setAttribute('aria-disabled', pode ? 'false' : 'true');
    }
    if (organizado.bloqueios.length > 0) resumo.textContent = 'Resolva os itens marcados para liberar a publicação.';
    else if (!pode) resumo.textContent = 'Marque as confirmações para publicar.';
    else resumo.textContent = '';
  }

  janela = modal({
    titulo: 'Publicar o site',
    descricao: 'Conferimos o site antes de ir ao ar.',
    corpo: el('div', null, corpo, resumo),
    tamanho: 'normal',
    classe: 'ed-check-modal',
    acoes: [
      { rotulo: 'Cancelar', tipo: 'fantasma' },
      {
        rotulo: 'Publicar agora',
        tipo: 'primario',
        icone: 'publicar',
        foco: op.podePublicar(organizado, marcados),
        fn: async () => {
          const r = await publicar(ed, [...marcados]);
          if (r === null) return false;
          if (r.erros) {
            // O servidor encontrou pendências (ex.: alteração feita em outra janela).
            setTimeout(() => abrirChecklist(ed, op.organizarValidacao({ erros: r.erros })), 0);
            return undefined;
          }
          setTimeout(() => janelaPublicado(ed, r), 0);
          return undefined;
        },
      },
    ],
  });
  botaoPublicar = janela.elemento.querySelector('.modal__acoes .btn--primario');
  atualizarBotao();
}

/** Confirma as alegações marcadas, salva e publica. → {url, versao} | {erros} | null */
async function publicar(ed, grupos) {
  if (grupos.length > 0) {
    ed.aplicar((d) => op.confirmarAlegacoes(d, grupos), { rotulo: 'Confirmar informações do site' });
  }
  const salvo = await ed.estado.salvar();
  if (!salvo) {
    aviso('Não foi possível salvar antes de publicar. Tente de novo.', { tipo: 'erro' });
    return null;
  }
  try {
    const r = await api.post(`/sites/${encodeURIComponent(ed.siteId)}/publicar`);
    ed.site.url = r?.url ?? ed.site.url;
    ed.site.publicadoVersao = r?.versao ?? ed.site.publicadoVersao;
    ed.site.publicadoEm = new Date().toISOString();
    ed.site.status = 'publicado';
    ed.emitir('site');
    return { url: r?.url ?? ed.site.url, versao: r?.versao ?? 1 };
  } catch (e) {
    if (e?.status === 422 && Array.isArray(e.dados?.erros)) return { erros: e.dados.erros };
    aviso(e?.mensagem ?? 'Não foi possível publicar agora. Tente de novo em instantes.', { tipo: 'erro' });
    return null;
  }
}

