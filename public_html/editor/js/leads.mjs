// Contatos recebidos pelo site (#/site/{id}/leads) — §8 e [M22].
//
// Lista paginada (data, nome, telefone, e-mail, mensagem, origem legível), "Chamar no
// WhatsApp", lido/não lido, exclusão (LGPD, com confirmação), exportação CSV e filtro
// "Só não lidos".

import { api } from './api.mjs';
import {
  el, anexar, icone, aviso, confirmar, carregando, estadoVazio, telaErro, tempoRelativo, formatarDataHora,
} from './ui.mjs';
import { formatarTelefone, linkTelefone } from './compartilhado/dados.mjs';

const REDES_CONHECIDAS = [
  [/(^|\.)google\./, 'Google'],
  [/(^|\.)bing\.com$/, 'Bing'],
  [/(^|\.)(instagram\.com|l\.instagram\.com)$/, 'Instagram'],
  [/(^|\.)(facebook\.com|fb\.com|m\.facebook\.com|l\.facebook\.com)$/, 'Facebook'],
  [/(^|\.)linkedin\.com$|(^|\.)lnkd\.in$/, 'LinkedIn'],
  [/(^|\.)youtube\.com$|(^|\.)youtu\.be$/, 'YouTube'],
  [/(^|\.)(t\.co|twitter\.com|x\.com)$/, 'X (Twitter)'],
  [/(^|\.)whatsapp\.com$|(^|\.)wa\.me$/, 'WhatsApp'],
];

function limpo(v) {
  return String(v ?? '').replace(/\s+/g, ' ').trim().slice(0, 120);
}

function hostDe(url) {
  try {
    return new URL(String(url)).hostname.replace(/^www\./, '').toLowerCase();
  } catch {
    return '';
  }
}

/**
 * Descreve de onde o contato veio, em português:
 *   { canal: "Google Ads (gclid)" | "utm: instagram / cpc" | "Veio do Google" | "Acesso direto",
 *     detalhes: ["campanha: …", "página: /…"] }
 */
export function descreverOrigem(origem) {
  const o = origem && typeof origem === 'object' && !Array.isArray(origem) ? origem : {};
  const detalhes = [];
  let canal;
  const ads = ['gclid', 'gbraid', 'wbraid'].filter((k) => limpo(o[k]) !== '');
  const fonte = limpo(o.utm_source);
  const meio = limpo(o.utm_medium);
  if (ads.length > 0) {
    canal = `Google Ads (${ads.join(', ')})`;
    if (fonte || meio) detalhes.push(`utm: ${[fonte, meio].filter(Boolean).join(' / ')}`);
  } else if (fonte || meio) {
    canal = `utm: ${[fonte || '—', meio].filter(Boolean).join(' / ')}`;
  } else {
    const host = hostDe(o.referencia);
    if (host) {
      const conhecida = REDES_CONHECIDAS.find(([re]) => re.test(host));
      canal = conhecida ? `Veio do ${conhecida[1]}` : `Veio de ${host}`;
    } else {
      canal = 'Acesso direto';
    }
  }
  if (limpo(o.utm_campaign)) detalhes.push(`campanha: ${limpo(o.utm_campaign)}`);
  if (limpo(o.utm_term)) detalhes.push(`termo: ${limpo(o.utm_term)}`);
  if (limpo(o.utm_content)) detalhes.push(`conteúdo: ${limpo(o.utm_content)}`);
  const pagina = limpo(o.pagina);
  if (pagina) {
    let caminho = pagina;
    try {
      const u = new URL(pagina, 'https://site.invalido');
      caminho = u.pathname || '/';
    } catch {
      /* fica como veio */
    }
    detalhes.push(`página: ${caminho}`);
  }
  return { canal, detalhes };
}

/** "12 contatos · 3 não lidos". */
export function resumoLeads(total, naoLidos) {
  const t = Number(total) || 0;
  const n = Number(naoLidos) || 0;
  const a = t === 0 ? 'Nenhum contato' : t === 1 ? '1 contato' : `${t} contatos`;
  if (t === 0) return a;
  return `${a} · ${n === 0 ? 'todos lidos' : n === 1 ? '1 não lido' : `${n} não lidos`}`;
}

export async function montar(alvo, { siteId }) {
  document.title = 'Contatos · Construtor Rankly';
  let vivo = true;
  let pagina = 1;
  let soNaoLidos = false;
  let dados = { leads: [], total: 0, naoLidos: 0, pagina: 1, paginas: 1 };

  const nomeSite = el('span', null, '');
  const titulo = el('h1', { class: 'titulo-pagina' }, 'Contatos recebidos');
  const subtitulo = el('p', { class: 'subtitulo' }, 'Quem preencheu o formulário do site ', nomeSite, '.');
  const resumo = el('p', { class: 'barra-ferramentas__resumo', 'aria-live': 'polite' }, '');
  const filtro = el('input', { type: 'checkbox', role: 'switch' });
  const exportar = el('a', {
    class: 'btn btn--pequeno', href: api.url(`/sites/${siteId}/leads.csv`), download: '',
  }, icone('baixar'), 'Exportar CSV');
  const lista = el('div', { class: 'leads-corpo' }, carregando('Carregando contatos…'));
  const paginacao = el('nav', { class: 'paginacao', 'aria-label': 'Páginas de contatos', hidden: true });

  alvo.replaceChildren(el('div', { class: 'pagina pagina--estreita' },
    el('a', { class: 'link-voltar', href: '#/sites' }, icone('voltar'), 'Meus sites'),
    el('div', { class: 'pagina__cab' },
      el('div', { class: 'pagina__titulos' }, titulo, subtitulo),
      el('a', { class: 'btn', href: `#/site/${siteId}` }, icone('editar'), 'Editar o site')),
    el('div', { class: 'barra-ferramentas' },
      resumo,
      el('label', { class: 'alternar' }, filtro, 'Só não lidos'),
      exportar),
    lista,
    paginacao));

  filtro.addEventListener('change', () => {
    soNaoLidos = filtro.checked;
    pagina = 1;
    carregarPagina();
  });

  function atualizarResumo() {
    resumo.textContent = resumoLeads(dados.total, dados.naoLidos);
    exportar.hidden = dados.total === 0;
  }

  function cartao(lead) {
    const origem = descreverOrigem(lead.origem);
    const idNome = `lead-${lead.id}-nome`;
    const telefone = formatarTelefone(lead.telefone);
    const item = el('li', { class: ['lead', !lead.lido && 'lead--nao-lido'], 'aria-labelledby': idNome, dataset: { leadId: lead.id } });

    const botaoLido = el('button', { type: 'button', class: 'btn btn--pequeno btn--fantasma' });
    const desenharLido = () => {
      botaoLido.replaceChildren(icone(lead.lido ? 'naoLido' : 'lido'), lead.lido ? 'Marcar como não lido' : 'Marcar como lido');
      item.classList.toggle('lead--nao-lido', !lead.lido);
      selo.hidden = lead.lido;
    };
    const selo = el('span', { class: 'selo selo--pri selo--ponto' }, 'Novo');

    async function marcar(lido, { silencioso = false } = {}) {
      if (lead.lido === lido) return;
      const antes = lead.lido;
      lead.lido = lido;
      dados.naoLidos += lido ? -1 : 1;
      desenharLido();
      atualizarResumo();
      try {
        await api.patch(`/leads/${lead.id}`, { lido });
      } catch (e) {
        lead.lido = antes;
        dados.naoLidos += lido ? 1 : -1;
        desenharLido();
        atualizarResumo();
        if (!silencioso) aviso(e?.mensagem ?? 'Não foi possível atualizar o contato.', { tipo: 'erro' });
      }
    }
    botaoLido.addEventListener('click', () => marcar(!lead.lido));

    const whatsapp = lead.whatsappLink
      ? el('a', {
        class: 'btn btn--primario btn--pequeno', href: lead.whatsappLink, target: '_blank', rel: 'noopener',
        'aria-label': `Chamar ${lead.nome} no WhatsApp (abre em nova aba)`,
      }, icone('whatsapp'), 'Chamar no WhatsApp')
      : null;
    whatsapp?.addEventListener('click', () => marcar(true, { silencioso: true }));

    const excluir = el('button', { type: 'button', class: 'icone-btn', 'aria-label': `Excluir o contato de ${lead.nome}`, title: 'Excluir' }, icone('lixeira'));
    excluir.addEventListener('click', async () => {
      const ok = await confirmar(
        el('p', { class: 'modal__texto' }, 'O contato de ', el('strong', null, lead.nome),
          ' será apagado de vez, inclusive da exportação. Use quando a pessoa pedir a exclusão dos dados (LGPD).'),
        { titulo: 'Excluir este contato?', confirmar: 'Excluir', perigo: true });
      if (!ok) return;
      try {
        await api.del(`/leads/${lead.id}`);
        aviso('Contato excluído.', { tipo: 'ok' });
        const proximo = item.nextElementSibling ?? item.previousElementSibling;
        dados.total -= 1;
        if (!lead.lido) dados.naoLidos -= 1;
        item.remove();
        atualizarResumo();
        if (!lista.querySelector('.lead')) carregarPagina();
        else (proximo?.querySelector('a, button') ?? titulo).focus();
      } catch (e) {
        aviso(e?.mensagem ?? 'Não foi possível excluir o contato.', { tipo: 'erro' });
      }
    });

    const data = el('time', { class: 'lead__data', datetime: lead.criadoEm, title: formatarDataHora(lead.criadoEm) },
      `${formatarDataHora(lead.criadoEm)} · ${tempoRelativo(lead.criadoEm)}`);

    anexar(item,
      el('div', { class: 'lead__info' },
        el('div', { class: 'lead__cab' }, el('h2', { class: 'lead__nome', id: idNome }, lead.nome), selo),
        data,
        el('p', { class: 'lead__contatos' },
          el('span', null, icone('telefone'), ' ', el('a', { href: linkTelefone(lead.telefone) || null }, telefone)),
          lead.email ? el('span', null, icone('envelope'), ' ', el('a', { href: `mailto:${lead.email}` }, lead.email)) : null)),
      el('div', { class: 'lead__acoes' }, whatsapp, botaoLido, excluir),
      lead.mensagem ? el('p', { class: 'lead__msg' }, lead.mensagem) : null,
      el('p', { class: 'lead__origem' },
        icone('globo'), el('span', { class: 'selo' }, origem.canal),
        origem.detalhes.length ? el('span', null, origem.detalhes.join(' · ')) : null));
    desenharLido();
    return item;
  }

  function desenharPaginacao() {
    paginacao.replaceChildren();
    paginacao.hidden = dados.paginas <= 1;
    if (paginacao.hidden) return;
    const anterior = el('button', { type: 'button', class: 'btn btn--pequeno', disabled: dados.pagina <= 1 }, icone('esquerda'), 'Anterior');
    const proxima = el('button', { type: 'button', class: 'btn btn--pequeno', disabled: dados.pagina >= dados.paginas }, 'Próxima', icone('direita'));
    anterior.addEventListener('click', () => {
      pagina = Math.max(1, dados.pagina - 1);
      carregarPagina({ focar: true });
    });
    proxima.addEventListener('click', () => {
      pagina = Math.min(dados.paginas, dados.pagina + 1);
      carregarPagina({ focar: true });
    });
    paginacao.append(anterior, el('span', { 'aria-current': 'page' }, `Página ${dados.pagina} de ${dados.paginas}`), proxima);
  }

  function desenhar() {
    atualizarResumo();
    desenharPaginacao();
    const visiveis = soNaoLidos ? dados.leads.filter((l) => !l.lido) : dados.leads;
    if (dados.total === 0) {
      lista.replaceChildren(estadoVazio({
        icone: 'conversa',
        titulo: 'Nenhum contato ainda',
        texto: 'Quando alguém preencher o formulário do site publicado, o contato aparece aqui e você recebe um e-mail.',
      }));
      return;
    }
    if (visiveis.length === 0) {
      lista.replaceChildren(estadoVazio({
        icone: 'ok',
        titulo: 'Tudo lido por aqui',
        texto: soNaoLidos ? 'Nenhum contato não lido nesta página.' : 'Nenhum contato nesta página.',
      }));
      return;
    }
    lista.replaceChildren(el('ul', { class: 'lista-leads', 'aria-label': 'Contatos' }, visiveis.map(cartao)));
  }

  async function carregarPagina({ focar = false } = {}) {
    lista.setAttribute('aria-busy', 'true');
    try {
      const r = await api.get(`/sites/${siteId}/leads?pagina=${pagina}${soNaoLidos ? '&naoLidos=1' : ''}`);
      if (!vivo) return;
      dados = {
        leads: Array.isArray(r?.leads) ? r.leads : [],
        total: Number(r?.total) || 0,
        naoLidos: Number(r?.naoLidos) || 0,
        pagina: Number(r?.pagina) || 1,
        paginas: Math.max(1, Number(r?.paginas) || 1),
      };
      if (r?.naoLidos === undefined) dados.naoLidos = dados.leads.filter((l) => !l.lido).length;
      desenhar();
      if (focar) {
        titulo.tabIndex = -1;
        titulo.focus();
        window.scrollTo({ top: 0 });
      }
    } catch (e) {
      if (!vivo || e?.status === 401) return;
      lista.replaceChildren(telaErro(e?.mensagem ?? 'Não foi possível carregar os contatos.', () => carregarPagina()));
    } finally {
      lista.removeAttribute('aria-busy');
    }
  }

  // Nome do site para o título (a lista de sites é leve; o 404 aparece como erro da tela).
  try {
    const r = await api.get('/sites');
    const site = (r?.sites ?? []).find((s) => Number(s.id) === Number(siteId));
    if (site) {
      nomeSite.textContent = site.nome;
      document.title = `Contatos · ${site.nome} · Construtor Rankly`;
    } else {
      subtitulo.textContent = 'Quem preencheu o formulário do site.';
    }
  } catch (e) {
    if (e?.status === 401) throw e;
  }
  await carregarPagina();

  return {
    desmontar() {
      vivo = false;
    },
  };
}
