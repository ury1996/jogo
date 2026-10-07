// Histórico de publicações: GET /api/sites/{id}/versoes → lista; "Carregar no editor"
// (GET /versoes/{n}) aplica o documento daquela versão como um passo desfazível.

import { api } from '../api.mjs';
import { el, icone, modal, aviso, carregando, formatarDataHora, tempoRelativo } from '../ui.mjs';
import { migrar } from '../compartilhado/documento.mjs';
import * as op from './operacoes.mjs';
import { reverterPublicacao } from './publicar.mjs';

export function abrirVersoes(ed) {
  const lista = el('div', { class: 'ed-versoes' }, carregando('Carregando publicações…'));
  let janela = null;

  async function carregarVersao(numero, botao) {
    botao.setAttribute('aria-busy', 'true');
    try {
      const r = await api.get(`/sites/${encodeURIComponent(ed.siteId)}/versoes/${numero}`);
      const documento = op.documentoDaVersao(r);
      if (!documento) throw new Error('Versão sem documento.');
      const novo = migrar(documento, ed.lib);
      janela?.fechar('carregado');
      const mudou = ed.aplicar(() => novo, { rotulo: `Carregar a versão ${numero}` });
      if (mudou) {
        aviso(`Versão ${numero} carregada no editor. Publique para colocá-la no ar.`, {
          acao: { rotulo: 'Desfazer', fn: () => ed.desfazer() },
        });
      } else {
        aviso(`O editor já está igual à versão ${numero}.`);
      }
    } catch (e) {
      aviso(e?.mensagem ?? e?.message ?? 'Não foi possível carregar esta versão.', { tipo: 'erro' });
    } finally {
      botao.removeAttribute('aria-busy');
    }
  }

  async function carregar() {
    try {
      const r = await api.get(`/sites/${encodeURIComponent(ed.siteId)}/versoes`);
      const versoes = Array.isArray(r?.versoes) ? r.versoes : [];
      if (versoes.length === 0) {
        lista.replaceChildren(el('div', { class: 'vazio ed-versoes__vazio' },
          el('div', { class: 'vazio__ico' }, icone('historico')),
          el('h3', { class: 'vazio__titulo' }, 'Nenhuma publicação ainda'),
          el('p', { class: 'vazio__texto' }, 'Cada vez que você publica, uma versão fica guardada aqui.')));
        return;
      }
      const itens = versoes.map((v) => {
        const botao = el('button', { type: 'button', class: 'btn btn--pequeno', onclick: () => carregarVersao(v.numero, botao) },
          icone('baixar'), 'Carregar no editor');
        return el('li', { class: 'ed-versao' },
          el('div', { class: 'ed-versao__info' },
            el('p', { class: 'ed-versao__titulo' }, `Versão ${v.numero}`,
              v.atual ? el('span', { class: 'selo selo--ok selo--ponto' }, 'No ar') : null),
            el('p', { class: 'ed-versao__meta' },
              el('time', { datetime: v.publicadoEm ?? '', title: formatarDataHora(v.publicadoEm) }, formatarDataHora(v.publicadoEm)),
              ` · ${tempoRelativo(v.publicadoEm)}`,
              v.publicadoPor ? ` · por ${v.publicadoPor}` : '')),
          botao);
      });
      const podeReverter = versoes.length > 1 && versoes[0]?.atual;
      lista.replaceChildren(...[
        el('ul', { class: 'ed-versoes__lista' }, itens),
        podeReverter ? el('div', { class: 'ed-versoes__reverter' },
          el('p', { class: 'ed-painel__ajuda' }, 'Publicou algo errado? O link pode voltar a mostrar a publicação anterior.'),
          el('button', {
            type: 'button', class: 'btn btn--pequeno btn--fantasma',
            onclick: async () => {
              if (await reverterPublicacao(ed)) janela?.fechar('revertido');
            },
          }, icone('historico'), 'Voltar à publicação anterior')) : null,
      ].filter(Boolean));
    } catch (e) {
      lista.replaceChildren(el('p', { class: 'aviso-inline aviso-inline--erro', role: 'alert' }, icone('alerta'),
        el('span', null, e?.mensagem ?? 'Não foi possível carregar o histórico.')));
    }
  }

  janela = modal({
    titulo: 'Histórico de publicações',
    descricao: 'Carregar uma versão substitui o que está no editor (dá para desfazer). O site no ar só muda quando você publicar.',
    corpo: lista,
    tamanho: 'normal',
  });
  carregar();
  return janela;
}
