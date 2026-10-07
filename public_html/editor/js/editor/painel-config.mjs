// Aba Config. (PDF §7.5, fase 2 trazida para cá): SEO (título e descrição do Google, com
// contador e prévia no estilo do resultado de busca), rastreamento (GTM-…, G-…, Pixel numérico,
// validados com a mesma regra do servidor) e endereço do site (só leitura).

import { el, icone, campo, aviso } from '../ui.mjs';
import { comoMapa } from '../compartilhado/texto.mjs';
import { contextoVariaveis } from '../compartilhado/textos.mjs';
import * as op from './operacoes.mjs';

function titulo(texto, id) {
  return el('h3', { class: 'ed-painel__titulo', id }, texto);
}

export function criarPainelConfig(ed) {
  const { lib } = ed;
  const campos = new Map();
  const sincronizadores = [];

  /* ---------------------------------------------------------------- SEO */

  function campoSeo({ chave, rotulo, max, ajuda, multilinha = false }) {
    const entrada = multilinha
      ? el('textarea', { rows: '3', maxlength: String(max * 2) })
      : el('input', { type: 'text', maxlength: String(max * 2), autocomplete: 'off' });
    const ui = campo({ rotulo, entrada, ajuda, opcional: true, max });
    entrada.removeAttribute('maxlength'); // o Google corta, não nós: o contador avisa
    const nomeCampo = chave.split('.')[1];
    entrada.addEventListener('input', () => {
      const v = entrada.value.replace(/[\r\n]+/g, ' ');
      ed.aplicar((d) => ({ ...d, seo: { ...comoMapa(d.seo), [nomeCampo]: v.trim() === '' ? null : v } }),
        { rotulo: `Alterar ${rotulo.toLowerCase()}`, agrupar: chave });
      ui.atualizarContador();
      atualizarPrevia();
    });
    entrada.addEventListener('change', () => ed.estado.encerrarGrupo());
    entrada.addEventListener('blur', () => ed.estado.encerrarGrupo());
    campos.set(chave, { entrada, uiCampo: ui });
    sincronizadores.push((doc) => {
      if (document.activeElement === entrada) return;
      const v = comoMapa(doc.seo)[nomeCampo];
      entrada.value = typeof v === 'string' ? v : '';
      ui.atualizarContador();
    });
    return { ui, entrada };
  }

  const tituloSeo = campoSeo({
    chave: 'seo.titulo', rotulo: 'Título no Google', max: op.MAX_SEO_TITULO,
    ajuda: 'Até 60 caracteres. Vazio usa "nome · segmento em cidade". Pode usar {nome} e {cidade}.',
  });
  const descricaoSeo = campoSeo({
    chave: 'seo.descricao', rotulo: 'Descrição no Google', max: op.MAX_SEO_DESCRICAO, multilinha: true,
    ajuda: 'Até 155 caracteres. Vazio usa o texto do destaque.',
  });

  const previaUrl = el('span', { class: 'ed-google__url' });
  const previaTitulo = el('span', { class: 'ed-google__titulo' });
  const previaDescricao = el('span', { class: 'ed-google__desc' });
  const previa = el('div', { class: 'ed-google', 'aria-label': 'Prévia do resultado no Google', role: 'img' },
    el('span', { class: 'ed-google__site' },
      el('span', { class: 'ed-google__favicon', 'aria-hidden': 'true' }),
      el('span', { class: 'ed-google__linhas' }, el('span', { class: 'ed-google__nome' }), previaUrl)),
    previaTitulo, previaDescricao);

  function atualizarPrevia() {
    const doc = ed.estado.doc;
    const { titulo: t, descricao } = op.seoEfetivo(doc, lib);
    const url = urlDoSite();
    previaUrl.textContent = url ? url.replace(/^https?:\/\//, '') : 'seu-site.sitesrankly.com.br';
    const nome = contextoVariaveis(doc, lib).nome;
    previa.querySelector('.ed-google__nome').textContent = nome;
    previaTitulo.textContent = op.cortarSemQuebrar(t, op.MAX_SEO_TITULO);
    previaDescricao.textContent = op.cortarSemQuebrar(descricao, op.MAX_SEO_DESCRICAO);
    previa.querySelector('.ed-google__favicon').textContent = (nome || 'R').trim().charAt(0).toUpperCase();
    previa.setAttribute('aria-label', `Prévia no Google: ${previaTitulo.textContent}. ${previaDescricao.textContent}`);
    tituloSeo.entrada.placeholder = op.tituloSeoPadrao(doc, lib);
    descricaoSeo.entrada.placeholder = op.descricaoSeoPadrao(doc, lib);
  }

  /* ---------------------------------------------------------------- rastreamento */

  function campoRastreamento({ campo: nome, rotulo, placeholder, ajuda }) {
    const chave = `rastreamento.${nome}`;
    const entrada = el('input', { type: 'text', placeholder, autocomplete: 'off', spellcheck: 'false', autocapitalize: 'characters' });
    const ui = campo({ rotulo, entrada, ajuda, opcional: true });
    let mostrou = false;
    const gravar = (valor) => ed.aplicar((d) => ({ ...d, rastreamento: { ...comoMapa(d.rastreamento), [nome]: valor } }),
      { rotulo: `Alterar ${rotulo}`, agrupar: chave });
    entrada.addEventListener('input', () => {
      gravar(op.normalizarRastreamento(entrada.value));
      if (mostrou) ui.definirErro(op.problemaRastreamento(nome, entrada.value));
    });
    entrada.addEventListener('change', () => {
      const normal = op.normalizarRastreamento(entrada.value);
      entrada.value = normal;
      gravar(normal);
      ed.estado.encerrarGrupo();
      const erro = op.problemaRastreamento(nome, normal);
      ui.definirErro(erro);
      mostrou = Boolean(erro);
    });
    entrada.addEventListener('blur', () => ed.estado.encerrarGrupo());
    campos.set(chave, { entrada, uiCampo: ui, validar: (v) => op.problemaRastreamento(nome, v) });
    sincronizadores.push((doc) => {
      if (document.activeElement === entrada) return;
      entrada.value = String(comoMapa(doc.rastreamento)[nome] ?? '');
    });
    return ui;
  }

  const gtm = campoRastreamento({ campo: 'gtm', rotulo: 'Google Tag Manager', placeholder: 'GTM-XXXXXXX', ajuda: 'Se usar o GTM, configure o GA4 e o Pixel dentro dele.' });
  const ga4 = campoRastreamento({ campo: 'ga4', rotulo: 'Google Analytics 4', placeholder: 'G-XXXXXXXXXX' });
  const pixel = campoRastreamento({ campo: 'metaPixel', rotulo: 'Meta Pixel', placeholder: '123456789012345' });

  /* ---------------------------------------------------------------- endereço do site */

  function urlDoSite() {
    return typeof ed.site?.url === 'string' ? ed.site.url : '';
  }
  const entradaUrl = el('input', { type: 'text', readonly: true, class: 'ed-url__entrada' });
  const campoUrl = campo({ rotulo: 'Link do site', entrada: entradaUrl, ajuda: 'Domínio próprio (www.seunegocio.com.br) chega na fase 2.' });
  const copiarUrl = el('button', {
    type: 'button', class: 'btn btn--pequeno',
    onclick: async () => {
      try {
        await navigator.clipboard.writeText(entradaUrl.value);
        aviso('Endereço copiado.', { tipo: 'ok' });
      } catch {
        entradaUrl.select();
        aviso('Selecionei o endereço: use Ctrl+C para copiar.');
      }
    },
  }, icone('duplicar'), 'Copiar');
  const abrirUrl = el('a', { class: 'btn btn--pequeno btn--fantasma', target: '_blank', rel: 'noopener' }, icone('abrir'), 'Abrir site');
  const situacao = el('p', { class: 'ed-painel__ajuda ed-url__situacao' });

  function atualizarUrl() {
    const url = urlDoSite();
    entradaUrl.value = url;
    const publicado = Boolean(ed.site?.publicadoEm) || ed.site?.status === 'publicado';
    abrirUrl.hidden = !url || !publicado;
    if (url) abrirUrl.href = url;
    situacao.textContent = publicado
      ? 'O site está no ar neste endereço.'
      : 'O site ainda não foi publicado: o endereço passa a funcionar depois de "Publicar".';
  }

  const elemento = el('div', { class: 'ed-painel-config formulario' },
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-seo' },
      titulo('Google (SEO)', 'ed-t-seo'),
      tituloSeo.ui.elemento, descricaoSeo.ui.elemento,
      el('p', { class: 'ed-painel__rotulo-previa' }, 'Como deve aparecer na busca'), previa),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-rastreio' },
      titulo('Rastreamento', 'ed-t-rastreio'),
      el('p', { class: 'ed-painel__ajuda' }, 'Os códigos só carregam depois que o visitante aceita a faixa de cookies do site.'),
      gtm.elemento, ga4.elemento, pixel.elemento),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-endereco-site' },
      titulo('Endereço do site', 'ed-t-endereco-site'),
      campoUrl.elemento, el('div', { class: 'ed-url__acoes' }, copiarUrl, abrirUrl), situacao));

  function atualizar() {
    const doc = ed.estado.doc;
    for (const s of sincronizadores) s(doc);
    atualizarPrevia();
    atualizarUrl();
  }

  function focarCampo(chave) {
    const c = campos.get(chave);
    if (!c) return false;
    c.entrada.scrollIntoView({ block: 'center' });
    c.entrada.focus({ preventScroll: true });
    if (c.validar) c.uiCampo.definirErro(c.validar(c.entrada.value));
    return true;
  }

  return { elemento, atualizar, focarCampo };
}
