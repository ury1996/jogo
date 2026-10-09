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

/* ------------------------------------------------------------------ exemplos e dicas (funções puras) */

/**
 * Textos-modelo por nicho/especialidade, para os chips "Exemplos para começar".
 * Os trechos entre [colchetes] são para a pessoa trocar pelos dados do negócio dela.
 * Sem números nem promessas: a IA não inventa, e os exemplos também não.
 */
export const EXEMPLOS_DESCRICAO = Object.freeze([
  {
    id: 'odontologia', nicho: 'clinicas', especialidade: 'odontologia', rotulo: 'Odontologia',
    texto: 'Clínica odontológica focada em implantes e ortodontia (aparelho fixo e alinhadores). Atendemos convênios e também aos sábados. Diferenciais: [anos de experiência da equipe], atendimento sem pressa e consultório fácil de chegar no bairro [seu bairro]. Público: famílias e adultos que querem voltar a sorrir sem medo de dentista.',
  },
  {
    id: 'medicina', nicho: 'clinicas', especialidade: 'medicina', rotulo: 'Clínica médica',
    texto: 'Clínica médica com clínico geral, cardiologia e dermatologia. Fazemos consultas de rotina e check-up com exames no mesmo lugar. Atendemos convênios e particular, com horário estendido durante a semana. Diferenciais: [o que faz os pacientes escolherem vocês]. Público: adultos e idosos da região de [seu bairro].',
  },
  {
    id: 'estetica', nicho: 'clinicas', especialidade: 'estetica', rotulo: 'Estética',
    texto: 'Clínica de estética facial e corporal: limpeza de pele, harmonização facial, depilação a laser e drenagem linfática. Começamos sempre com uma avaliação e um protocolo personalizado. Diferenciais: [equipamentos, formação da equipe]. Público: mulheres e homens de [sua região] que querem se cuidar com segurança.',
  },
  {
    id: 'fisioterapia', nicho: 'clinicas', especialidade: 'fisioterapia', rotulo: 'Fisioterapia',
    texto: 'Clínica de fisioterapia ortopédica e esportiva, com pilates e RPG. Atendimento individual, com plano de tratamento explicado desde a primeira sessão. Atendemos convênios e também em domicílio. Diferenciais: [especializações da equipe]. Público: quem sente dor nas costas, está no pós-operatório ou pratica esporte.',
  },
  {
    id: 'psicologia', nicho: 'clinicas', especialidade: 'psicologia', rotulo: 'Psicologia',
    texto: 'Consultório de psicologia com atendimento presencial e on-line para adultos e adolescentes, na abordagem [sua abordagem, ex.: TCC]. Trabalho com ansiedade, depressão, luto e orientação de carreira. Diferenciais: [formação e experiência]. Horários à noite e aos sábados.',
  },
  {
    id: 'familia', nicho: 'advocacia', especialidade: 'advocacia', rotulo: 'Família e previdência',
    texto: 'Escritório de advocacia focado em direito de família (divórcio, guarda e pensão) e direito previdenciário (aposentadoria, BPC/LOAS e revisões). Atendimento presencial e on-line, com explicação clara de cada etapa do processo. Diferenciais: [tempo de atuação, OAB/UF]. Público: famílias e trabalhadores de [sua região].',
  },
  {
    id: 'trabalhista', nicho: 'advocacia', especialidade: 'advocacia', rotulo: 'Trabalhista e empresarial',
    texto: 'Advocacia trabalhista e empresarial: defesa de empresas em reclamações trabalhistas, contratos, consultoria preventiva e cobranças. Atendemos pequenas e médias empresas com acompanhamento mensal. Diferenciais: [setores que vocês conhecem bem, tempo de atuação]. Atendemos [sua região] e também on-line.',
  },
  {
    id: 'engenharia', nicho: 'empresas', especialidade: 'engenharia', rotulo: 'Engenharia',
    texto: 'Empresa de engenharia elétrica e manutenção predial: projetos, laudos técnicos com ART, instalações e manutenção preventiva. Atendemos condomínios, indústrias e comércio em [sua região]. Diferenciais: [equipe própria, atendimento de emergência, certificações].',
  },
  {
    id: 'industria', nicho: 'empresas', especialidade: 'industria', rotulo: 'Indústria',
    texto: 'Indústria de [seu produto] com fabricação própria e entrega para [estados ou regiões]. Produzimos sob medida e em pequenos lotes. Diferenciais: [certificações, prazo, capacidade]. Clientes: distribuidores e empresas de [setor].',
  },
  {
    id: 'servicos-empresariais', nicho: 'empresas', especialidade: 'servicos-empresariais', rotulo: 'Serviços para empresas',
    texto: 'Empresa de serviços terceirizados de limpeza e portaria para condomínios e escritórios. Equipe uniformizada e treinada, com supervisão diária. Diferenciais: [tempo de mercado, tipo de contrato]. Atendemos [sua região].',
  },
  {
    id: 'contabilidade', nicho: 'financas', especialidade: 'contabilidade', rotulo: 'Contabilidade',
    texto: 'Escritório de contabilidade para pequenas empresas, MEI e profissionais liberais: abertura de empresa, folha de pagamento, impostos e imposto de renda. Atendimento on-line e presencial. Diferenciais: [atendimento pelo WhatsApp, setores em que é especialista]. Público: empreendedores de [sua região].',
  },
  {
    id: 'consultoria-financeira', nicho: 'financas', especialidade: 'consultoria-financeira', rotulo: 'Consultoria financeira',
    texto: 'Consultoria financeira para famílias e pequenas empresas: organização do orçamento, saída das dívidas e planejamento de investimentos. Reuniões on-line ou presenciais. Diferenciais: [certificações, forma de cobrança]. Público: [quem você atende].',
  },
  {
    id: 'credito', nicho: 'financas', especialidade: 'credito', rotulo: 'Crédito',
    texto: 'Correspondente bancário de crédito: financiamento imobiliário, crédito consignado e empréstimo com garantia de imóvel, com vários bancos parceiros. Fazemos a simulação e cuidamos da papelada. Diferenciais: [bancos parceiros, como é o atendimento]. Atendemos [sua região].',
  },
]);

const EXEMPLO_GERAL = Object.freeze({
  id: 'geral', nicho: null, especialidade: null, rotulo: 'Modelo em branco',
  texto: '[Tipo de negócio] em [sua cidade] que oferece [serviços principais]. Atendemos [público] [dias e horários]. Diferenciais: [o que faz os clientes escolherem vocês].',
});

/**
 * Chips de exemplo para o nicho: os da especialidade escolhida primeiro, depois os outros do
 * mesmo nicho; completa com o modelo em branco. Nicho desconhecido → só o modelo em branco.
 */
export function exemplosDescricao(nichoId, especialidadeId = null, max = 4) {
  const doNicho = EXEMPLOS_DESCRICAO.filter((e) => e.nicho === nichoId);
  const primeiro = doNicho.filter((e) => especialidadeId && e.especialidade === especialidadeId);
  const lista = [...primeiro, ...doNicho.filter((e) => !primeiro.includes(e))].slice(0, Math.max(0, max - 1));
  return [...lista, EXEMPLO_GERAL];
}

/** Exemplo curto para o placeholder do campo: a primeira frase do exemplo do nicho, sem [trechos]. */
export function placeholderDescricao(nichoId, especialidadeId = null) {
  const ex = exemplosDescricao(nichoId, especialidadeId).find((e) => e.nicho);
  if (!ex) return 'Ex.: o que o negócio faz, os serviços principais, para quem e os diferenciais.';
  return `Ex.: ${ex.texto.split(/(?<=\.)\s/)[0]} Diferenciais: …`;
}

/** Primeiro trecho [entre colchetes] do texto → { inicio, fim } (para selecionar e trocar), ou null. */
export function trechoParaTrocar(texto) {
  const m = /\[[^\]\n]{1,80}\]/.exec(String(texto ?? ''));
  return m ? { inicio: m.index, fim: m.index + m[0].length } : null;
}

const RE_DIFERENCIAIS = /diferencia|exclusiv|únic|especializ|experi[êe]ncia|\banos\b|certifica|premiad|garantia|sem pressa|personaliza|humaniza|equipe própria|emergência/i;
const RE_PUBLICO = /público|publico|clientes|pacientes|famílias|familias|empresas|condomínios|empreendedores|adultos|crianças|adolescentes|idosos|região|regiao|bairro|atendemos/i;

/**
 * Dica de qualidade da descrição, ao vivo. → { nivel, texto }
 * nivel: "vazio" | "curto" | "colchetes" | "bom" | "otimo".
 */
export function dicaDescricao(texto) {
  const t = String(texto ?? '').replace(/\s+/g, ' ').trim();
  const n = [...t].length;
  if (n === 0) return { nivel: 'vazio', texto: 'Sem descrição, o site usa os textos de exemplo do seu tipo de negócio.' };
  if (n < MIN_DESCRICAO) return { nivel: 'curto', texto: 'Escreva pelo menos uma frase para a IA começar.' };
  if (trechoParaTrocar(t)) return { nivel: 'colchetes', texto: 'Troque os trechos entre [colchetes] pelos dados do seu negócio.' };
  if (n < 80) return { nivel: 'bom', texto: 'Bom começo — conte também os serviços principais e os diferenciais.' };
  if (!RE_DIFERENCIAIS.test(t)) return { nivel: 'bom', texto: 'Bom começo — conte também os diferenciais (o que faz os clientes escolherem vocês).' };
  if (!RE_PUBLICO.test(t)) return { nivel: 'bom', texto: 'Muito bom — diga também para quem é o atendimento (público e região).' };
  return { nivel: 'otimo', texto: 'Ótima descrição: a IA tem o que precisa para escrever o site.' };
}

/** Rótulo do botão final do assistente: deixa claro se a IA vai escrever. */
export function rotuloGerar({ ia = false, descricao = '' } = {}) {
  return ia && String(descricao ?? '').trim().length >= MIN_DESCRICAO ? 'Gerar meu site com IA' : 'Gerar com textos de exemplo';
}

/** Os textos do site ainda são todos os de exemplo? (nenhum texto editado, nenhum SEO escrito). */
export function textosSaoDeExemplo(doc) {
  const textos = doc?.textos && typeof doc.textos === 'object' ? doc.textos : {};
  if (Object.values(textos).some((v) => String(v ?? '').trim() !== '')) return false;
  return !doc?.seo?.titulo && !doc?.seo?.descricao;
}

/** Mostrar a faixa "Os textos ainda são de exemplo…" no editor? */
export function mostrarFaixaIa({ doc, ia = false, dispensada = false } = {}) {
  return Boolean(ia) && !dispensada && textosSaoDeExemplo(doc);
}

/* A dispensa da faixa fica no navegador, por site (conveniência). */
const chaveFaixa = (siteId) => `rk-ia-faixa-dispensada-${siteId}`;
export function faixaDispensada(siteId) {
  try {
    return localStorage.getItem(chaveFaixa(siteId)) === '1';
  } catch {
    return false;
  }
}
export function dispensarFaixa(siteId) {
  try {
    localStorage.setItem(chaveFaixa(siteId), '1');
  } catch {
    /* modo privado: some só nesta visita */
  }
}

/* ------------------------------------------------------------------ peças de tela compartilhadas */

/**
 * Chips "Exemplos para começar" ligados a um textarea. Clicar preenche o campo com o texto-modelo
 * (editável) e seleciona o primeiro [trecho] para a pessoa trocar. Se havia um texto próprio,
 * um aviso oferece "Desfazer".
 */
export function chipsExemplos(textarea, { nicho, especialidade = null, aoMudar = () => {}, id = 'ia-exemplos' } = {}) {
  const exemplos = exemplosDescricao(nicho, especialidade);
  const textosModelo = new Set(EXEMPLOS_DESCRICAO.map((e) => e.texto).concat(EXEMPLO_GERAL.texto));
  const usar = (ex) => {
    const antes = textarea.value;
    textarea.value = ex.texto;
    aoMudar();
    textarea.focus();
    const t = trechoParaTrocar(ex.texto);
    try {
      if (t) textarea.setSelectionRange(t.inicio, t.fim);
      else textarea.setSelectionRange(ex.texto.length, ex.texto.length);
    } catch {
      /* nada */
    }
    if (antes.trim() && !textosModelo.has(antes)) {
      aviso('O exemplo substituiu o texto que você tinha escrito.', {
        acao: { rotulo: 'Desfazer', fn: () => { textarea.value = antes; aoMudar(); textarea.focus(); } },
      });
    }
  };
  return el('div', { class: 'ia-exemplos' },
    el('span', { class: 'ia-exemplos__rotulo', id }, 'Exemplos para começar:'),
    el('div', { class: 'ia-exemplos__lista', role: 'group', 'aria-labelledby': id },
      exemplos.map((ex) => el('button', {
        type: 'button', class: 'ia-chip', 'aria-label': `Usar o exemplo ${ex.rotulo}`, title: 'Preenche o campo com um texto-modelo para você ajustar',
        onclick: () => usar(ex),
      }, icone('mais'), ex.rotulo))));
}

/** Linha de dica ao vivo (texto + nível visual). Devolve { elemento, atualizar(texto) }. */
export function linhaDica(id) {
  const texto = el('span');
  const elemento = el('p', { class: 'ia-dica', id, 'aria-live': 'polite' }, el('span', { class: 'ia-dica__ponto', 'aria-hidden': 'true' }), texto);
  let ultimo = '';
  return {
    elemento,
    atualizar(valor) {
      const d = dicaDescricao(valor);
      elemento.dataset.nivel = d.nivel;
      // Só troca o texto quando a dica muda (o leitor de tela não repete a cada tecla).
      if (d.texto !== ultimo) texto.textContent = d.texto;
      ultimo = d.texto;
    },
  };
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
  const doc = ed.estado?.doc ?? {};
  const campo = el('textarea', {
    id: 'ia-descricao', class: 'campo__entrada ia-janela__descricao', rows: 9, maxlength: MAX_DESCRICAO, 'data-foco-inicial': '',
    'aria-describedby': 'ia-descricao-ajuda ia-descricao-dica',
    placeholder: placeholderDescricao(doc.nicho, doc.especialidade),
  });
  campo.value = lerDescricao(ed.siteId);
  const contador = el('span', { class: 'ia-janela__contador', 'aria-hidden': 'true' });
  const dica = linhaDica('ia-descricao-dica');
  const erro = el('p', { class: 'campo__erro', role: 'alert', hidden: true });
  const progresso = el('p', { class: 'campo__ajuda', role: 'status', 'aria-live': 'polite' });
  const atualizarContador = () => {
    contador.textContent = `${[...campo.value].length}/${MAX_DESCRICAO}`;
    dica.atualizar(campo.value);
  };
  campo.addEventListener('input', atualizarContador);
  atualizarContador();

  const corpo = el('div', { class: 'ia-janela' },
    el('label', { class: 'campo__rotulo ia-janela__rotulo', for: 'ia-descricao' }, 'Conte sobre o seu negócio'),
    el('p', { class: 'campo__ajuda', id: 'ia-descricao-ajuda' },
      'O que o negócio faz, os serviços principais, o público e os diferenciais. Quanto mais detalhes verdadeiros, melhores os textos.'),
    chipsExemplos(campo, { nicho: doc.nicho, especialidade: doc.especialidade, aoMudar: atualizarContador }),
    campo,
    el('div', { class: 'ia-janela__rodape' }, dica.elemento, contador),
    erro,
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
