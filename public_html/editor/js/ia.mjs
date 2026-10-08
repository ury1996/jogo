// IA que escreve os textos do site a partir de uma descrição do negócio.
// O servidor (POST /api/sites/{id}/ia) devolve um "patch" já filtrado (limites, termos proibidos
// do conselho, sem números/depoimentos inventados); aqui ele vira UMA alteração desfazível.

import api from './api.mjs';
import { el, icone, modal, aviso } from './ui.mjs';

export const MIN_DESCRICAO = 10;
export const MAX_DESCRICAO = 1500;

let disponibilidade = null;

/** A IA está configurada no servidor? (consulta uma vez por carregamento). */
export function iaDisponivel() {
  disponibilidade ??= api.get('/ia').then((r) => Boolean(r?.disponivel)).catch(() => {
    disponibilidade = null;
    return false;
  });
  return disponibilidade;
}

/** Pede os textos ao servidor. escopo = "site" ou o tipo de uma seção. */
export function pedirConteudo(siteId, descricao, escopo = 'site') {
  return api.post(`/sites/${siteId}/ia`, { descricao, escopo });
}

/**
 * Aplica o patch da IA numa cópia do documento (função pura — usada pelo editor e pelo assistente).
 * textos: entram/substituem; remover: saem (voltam ao padrão); listas: nova ordem de itens;
 * iconesRemover: ícones escolhidos à mão que voltam ao automático; seo: título e descrição.
 */
export function aplicarPatchIa(doc, patch) {
  const novo = { ...doc, textos: { ...(doc.textos ?? {}) }, listas: { ...(doc.listas ?? {}) }, icones: { ...(doc.icones ?? {}) } };
  for (const chave of patch?.remover ?? []) delete novo.textos[chave];
  Object.assign(novo.textos, patch?.textos ?? {});
  for (const [lista, ids] of Object.entries(patch?.listas ?? {})) novo.listas[lista] = [...ids];
  for (const chave of patch?.iconesRemover ?? []) delete novo.icones[chave];
  if (patch?.seo?.titulo && patch?.seo?.descricao) novo.seo = { ...(doc.seo ?? {}), titulo: patch.seo.titulo, descricao: patch.seo.descricao };
  return novo;
}

/** Quantos campos o patch muda (para a mensagem de sucesso). */
export function contarAlteracoes(patch) {
  return Object.keys(patch?.textos ?? {}).length + (patch?.remover?.length ?? 0) + (patch?.seo ? 2 : 0);
}

/* A última descrição usada fica no navegador (conveniência; nada depende disso). */
const chaveDescricao = (siteId) => `rk-ia-descricao-${siteId}`;
export function lerDescricao(siteId) {
  try {
    return localStorage.getItem(chaveDescricao(siteId)) ?? '';
  } catch {
    return '';
  }
}
export function guardarDescricao(siteId, texto) {
  try {
    localStorage.setItem(chaveDescricao(siteId), texto);
  } catch {
    /* modo privado: tudo bem */
  }
}

/** Mensagem curta com o resultado (avisos das travas e palavras-chave). */
export function resumoResultado(patch) {
  const partes = [];
  const n = contarAlteracoes(patch);
  partes.push(n === 1 ? 'A IA escreveu 1 texto.' : `A IA escreveu ${n} textos.`);
  if (patch?.palavrasChave?.length) partes.push(`Palavras-chave: ${patch.palavrasChave.slice(0, 5).join(', ')}.`);
  partes.push('Revise antes de publicar. Desfazer volta ao que estava.');
  return partes.join(' ');
}

/**
 * Janela "Escrever com IA" do editor.
 * ed: objeto do editor (estado, lib, siteId). escopo: "site" ou tipo de seção; nomeSecao para o título.
 */
export function abrirJanelaIa(ed, { escopo = 'site', nomeSecao = '' } = {}) {
  const deSecao = escopo !== 'site';
  const campo = el('textarea', {
    id: 'ia-descricao', class: 'ia-janela__descricao', rows: 6, maxlength: MAX_DESCRICAO, 'data-foco-inicial': '',
    placeholder: 'Ex.: Clínica odontológica focada em implantes e ortodontia. Atendemos convênios e aos sábados. Público: famílias da região central.',
  });
  campo.value = lerDescricao(ed.siteId);
  const contador = el('span', { class: 'ia-janela__contador', 'aria-hidden': 'true' });
  const erro = el('p', { class: 'campo__erro', role: 'alert', hidden: true });
  const progresso = el('p', { class: 'campo__ajuda', role: 'status', 'aria-live': 'polite' });
  const atualizarContador = () => {
    contador.textContent = `${campo.value.length}/${MAX_DESCRICAO}`;
  };
  campo.addEventListener('input', atualizarContador);
  atualizarContador();

  const corpo = el('div', { class: 'ia-janela' },
    el('label', { class: 'campo__rotulo', for: 'ia-descricao' }, 'O que você quer no site?'),
    el('p', { class: 'campo__ajuda' },
      'Conte o que o negócio faz, os serviços principais, o público e os diferenciais. Quanto mais detalhes verdadeiros, melhores os textos.'),
    campo, contador, erro,
    el('ul', { class: 'ia-janela__regras' },
      el('li', null, icone('check'), deSecao ? 'Reescreve só os textos desta seção.' : 'Escreve títulos, textos, serviços, perguntas frequentes e o título e a descrição para o Google, com palavras-chave da sua cidade.'),
      el('li', null, icone('check'), 'Não inventa números, depoimentos nem nomes: esses continuam para você conferir.'),
      el('li', null, icone('check'), 'Segue as regras de publicidade da sua profissão. Você pode desfazer tudo.')),
    progresso);

  modal({
    titulo: deSecao ? `Reescrever "${nomeSecao}" com IA` : 'Escrever o site com IA',
    corpo,
    tamanho: 'normal',
    classe: 'modal--ia',
    acoes: [
      { rotulo: 'Cancelar', tipo: 'fantasma' },
      {
        rotulo: deSecao ? 'Reescrever' : 'Escrever com IA', tipo: 'primario', icone: 'brilho',
        fn: async () => {
          const descricao = campo.value.trim();
          if (descricao.length < MIN_DESCRICAO) {
            erro.textContent = 'Conte um pouco mais sobre o negócio (pelo menos uma frase).';
            erro.hidden = false;
            campo.focus();
            return false;
          }
          erro.hidden = true;
          guardarDescricao(ed.siteId, descricao);
          campo.disabled = true;
          progresso.textContent = 'Salvando as últimas alterações…';
          try {
            await ed.estado.salvar();
            progresso.textContent = 'A IA está escrevendo os textos… isso leva de 10 a 40 segundos.';
            const patch = await pedirConteudo(ed.siteId, descricao, escopo);
            if (ed.desmontado) return true;
            ed.estado.aplicar((d) => aplicarPatchIa(d, patch), { rotulo: deSecao ? `IA: ${nomeSecao}` : 'Textos escritos pela IA' });
            aviso(resumoResultado(patch), { tipo: 'ok', duracao: 9000 });
            for (const a of patch.avisos ?? []) aviso(a, { duracao: 9000 });
            return true;
          } catch (e) {
            if (e?.status === 401) return true;
            erro.textContent = e?.mensagem ?? 'A IA não respondeu. Tente de novo.';
            erro.hidden = false;
            return false;
          } finally {
            campo.disabled = false;
            progresso.textContent = '';
          }
        },
      },
    ],
  });
}
