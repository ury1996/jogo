// Painel "Meus sites" (#/sites) — [M9].
//
// Lista (nome, nicho, modelo, status, "atualizado há X", contatos não lidos) com as ações
// Editar, Ver site, Leads, Duplicar, Trocar modelo e Arquivar (com confirmação), o botão
// "Novo site" e um estado vazio para quem ainda não criou nenhum.

import { api } from './api.mjs';
import {
  el, icone, aviso, confirmar, navegar, carregando, estadoVazio, telaErro, menuAcoes,
  tempoRelativo, formatarDataHora,
} from './ui.mjs';
import { carregarBiblioteca, nomeNicho, nomeModelo } from './biblioteca.mjs';
import { inicial } from './compartilhado/dados.mjs';
import { normalizarCor, textoSobre } from './compartilhado/paleta.mjs';

const ROTULO_STATUS = {
  publicado: { texto: 'Publicado', classe: 'selo--ok' },
  rascunho: { texto: 'Rascunho', classe: '' },
  arquivado: { texto: 'Arquivado', classe: 'selo--alerta' },
};

/** "1 contato novo" / "3 contatos novos". */
export function textoLeadsNovos(n) {
  const q = Number(n) || 0;
  return q === 1 ? '1 contato novo' : `${q} contatos novos`;
}

/** "Nenhum site" / "1 site" / "4 sites". */
export function textoQuantidadeSites(n) {
  const q = Number(n) || 0;
  if (q === 0) return 'Nenhum site';
  return q === 1 ? '1 site' : `${q} sites`;
}

/**
 * Cor do quadradinho do site: a cor do site, se a API mandar (`cor`), senão a cor padrão do
 * nicho no modelo (a lista não traz o documento).
 */
export function corDoSite(lib, site) {
  return normalizarCor(site?.cor) ?? normalizarCor(lib?.nichos?.[site?.nicho]?.padroesPorModelo?.[site?.modelo]?.cor) ?? '#2457d6';
}

function linhaSite(site, lib, acoes) {
  const status = ROTULO_STATUS[site.status] ?? { texto: site.status, classe: '' };
  const cor = corDoSite(lib, site);
  const publicado = site.status === 'publicado';
  const idNome = `site-${site.id}-nome`;
  const novos = Number(site.leadsNaoLidos) || 0;

  const menu = menuAcoes({
    rotulo: `Mais ações para ${site.nome}`,
    itens: [
      { rotulo: 'Trocar modelo', icone: 'grade', href: `#/site/${site.id}/modelo` },
      { rotulo: 'Duplicar', icone: 'duplicar', fn: () => acoes.duplicar(site) },
      publicado ? { rotulo: 'Abrir o site publicado', icone: 'abrir', href: site.url, alvo: '_blank' } : null,
      { rotulo: 'Arquivar', icone: 'arquivar', perigo: true, fn: () => acoes.arquivar(site) },
    ],
  });

  return el('li', { class: 'site-linha', dataset: { siteId: site.id }, 'aria-labelledby': idNome },
    el('div', { class: 'site-linha__avatar', style: { '--cor-site': cor, '--on-cor-site': textoSobre(cor) }, 'aria-hidden': 'true' },
      inicial(site.nome) || '?'),
    el('div', { class: 'site-linha__info' },
      el('h2', { class: 'site-linha__nome', id: idNome },
        el('a', { href: `#/site/${site.id}` }, site.nome),
        el('span', { class: ['selo', 'selo--ponto', status.classe] }, status.texto),
        novos > 0 ? el('a', { class: 'selo selo--pri', href: `#/site/${site.id}/leads` }, textoLeadsNovos(novos)) : null),
      el('p', { class: 'site-linha__meta' },
        el('span', null, icone('pincel'), `${nomeNicho(lib, site.nicho)} · modelo ${nomeModelo(lib, site.modelo)}`),
        el('span', { title: formatarDataHora(site.atualizadoEm) }, icone('historico'), `atualizado ${tempoRelativo(site.atualizadoEm)}`),
        publicado && site.url
          ? el('span', null, icone('globo'), el('a', { class: 'site-linha__url', href: site.url, target: '_blank', rel: 'noopener' },
            String(site.url).replace(/^https?:\/\//, '')))
          : el('span', null, icone('globo'), 'ainda não publicado'))),
    el('div', { class: 'site-linha__acoes' },
      el('a', { class: 'btn btn--primario btn--pequeno', href: `#/site/${site.id}`, 'aria-label': `Editar ${site.nome}` }, icone('editar'), 'Editar'),
      publicado && site.url
        ? el('a', { class: 'btn btn--pequeno', href: site.url, target: '_blank', rel: 'noopener', 'aria-label': `Ver o site ${site.nome} (abre em nova aba)` }, icone('abrir'), 'Ver site')
        : null,
      el('a', { class: 'btn btn--pequeno', href: `#/site/${site.id}/leads`, 'aria-label': `Contatos de ${site.nome}${novos ? ` (${textoLeadsNovos(novos)})` : ''}` },
        icone('conversa'), 'Leads', novos > 0 ? el('span', { class: 'selo selo--perigo selo--contagem', 'aria-hidden': 'true' }, String(novos)) : null),
      menu.elemento));
}

export async function montar(alvo) {
  document.title = 'Meus sites · Construtor Rankly';
  let vivo = true;
  let lib = null;
  let sites = [];

  const titulo = el('h1', { class: 'titulo-pagina' }, 'Meus sites');
  const resumo = el('p', { class: 'subtitulo' }, 'Carregando…');
  const corpo = el('div', { class: 'painel-sites' }, carregando('Carregando seus sites…'));
  alvo.replaceChildren(el('div', { class: 'pagina' },
    el('div', { class: 'pagina__cab' },
      el('div', { class: 'pagina__titulos' }, titulo, resumo),
      el('a', { class: 'btn btn--primario', href: '#/novo' }, icone('mais'), 'Novo site')),
    corpo));

  const acoes = {
    async duplicar(site) {
      try {
        const r = await api.post(`/sites/${site.id}/duplicar`);
        aviso(`Cópia criada: ${r.site.nome}.`, { tipo: 'ok', acao: { rotulo: 'Editar a cópia', fn: () => navegar(`#/site/${r.site.id}`) } });
        await carregar();
        corpo.querySelector(`[data-site-id="${r.site.id}"] .site-linha__nome a`)?.focus();
      } catch (e) {
        aviso(e?.mensagem ?? 'Não foi possível duplicar o site.', { tipo: 'erro' });
      }
    },
    async arquivar(site) {
      const publicado = site.status === 'publicado';
      const ok = await confirmar(
        el('div', { class: 'modal__texto' },
          el('p', null, 'O site ', el('strong', null, site.nome), ' sai da sua lista e não pode mais ser editado.'),
          publicado ? el('p', { class: 'aviso-inline aviso-inline--alerta', style: 'margin-top:12px' }, icone('alerta'),
            el('span', null, 'O site publicado continua no ar. Os contatos recebidos ficam guardados.')) : null),
        { titulo: 'Arquivar este site?', confirmar: 'Arquivar', perigo: true });
      if (!ok) return;
      try {
        await api.del(`/sites/${site.id}`);
        sites = sites.filter((s) => s.id !== site.id);
        desenhar();
        aviso(`"${site.nome}" foi arquivado.`, { tipo: 'ok' });
        titulo.tabIndex = -1;
        titulo.focus();
      } catch (e) {
        aviso(e?.mensagem ?? 'Não foi possível arquivar o site.', { tipo: 'erro' });
      }
    },
  };

  function desenhar() {
    if (!vivo) return;
    const naoLidos = sites.reduce((t, s) => t + (Number(s.leadsNaoLidos) || 0), 0);
    resumo.textContent = sites.length === 0
      ? 'Crie o primeiro site em três passos.'
      : `${textoQuantidadeSites(sites.length)}${naoLidos > 0 ? ` · ${textoLeadsNovos(naoLidos)}` : ''}`;
    if (sites.length === 0) {
      corpo.replaceChildren(estadoVazio({
        icone: 'brilho',
        titulo: 'Nenhum site ainda',
        texto: 'Escolha o tipo de negócio, um modelo e preencha nome, cidade e WhatsApp. Em poucos minutos o site está pronto para editar.',
        acao: el('a', { class: 'btn btn--primario btn--grande', href: '#/novo' }, icone('mais'), 'Criar meu primeiro site'),
      }));
      return;
    }
    corpo.replaceChildren(el('ul', { class: 'lista-sites', 'aria-label': 'Sites' }, sites.map((s) => linhaSite(s, lib, acoes))));
  }

  async function carregar() {
    const [r, l] = await Promise.all([api.get('/sites'), carregarBiblioteca().catch(() => null)]);
    if (!vivo) return;
    lib = l;
    sites = Array.isArray(r?.sites) ? r.sites : [];
    desenhar();
  }

  try {
    await carregar();
  } catch (e) {
    if (e?.status === 401) throw e;
    resumo.textContent = '';
    corpo.replaceChildren(telaErro(e?.mensagem ?? 'Não foi possível carregar seus sites.', () => {
      corpo.replaceChildren(carregando('Carregando seus sites…'));
      carregar().catch((e2) => corpo.replaceChildren(telaErro(e2?.mensagem ?? 'Não foi possível carregar seus sites.')));
    }));
  }

  return {
    desmontar() {
      vivo = false;
    },
  };
}
