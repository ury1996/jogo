// Banco de imagens (Pixabay): janela "Biblioteca de imagens" para buscar uma foto e trazê-la
// para dentro do site. O servidor faz a busca (GET /api/banco-imagens/buscar) e a importação
// (POST /api/banco-imagens/importar → {midia} igual ao upload, com origem e crédito do autor);
// aqui a foto escolhida vira UMA alteração desfazível, pelo mesmo caminho do upload.
//
// Funções puras (testadas no node): termoSugerido, sugestoesDoNicho, rotuloFoto.

import { api } from '../api.mjs';
import { el, icone, modal, aviso } from '../ui.mjs';

/* ================================================================== termos sugeridos */

/** Termo base por especialidade (ou nicho, quando a especialidade não tem um próprio). */
const TERMO_BASE = {
  odontologia: 'consultório odontológico',
  medicina: 'consultório médico',
  estetica: 'clínica de estética',
  fisioterapia: 'fisioterapia',
  psicologia: 'consultório de psicologia',
  advocacia: 'escritório de advocacia',
  engenharia: 'engenharia',
  industria: 'indústria',
  'servicos-empresariais': 'escritório corporativo',
  contabilidade: 'contabilidade',
  'consultoria-financeira': 'consultoria financeira',
  credito: 'financiamento imobiliário',
  // nichos
  clinicas: 'clínica',
  empresas: 'escritório corporativo',
  financas: 'contabilidade',
};

/** Chips de sugestão por especialidade/nicho (o termo do espaço vem antes). */
const SUGESTOES = {
  odontologia: ['dentista', 'sorriso', 'consultório odontológico', 'cadeira de dentista', 'recepção de clínica', 'paciente sorrindo'],
  medicina: ['médico', 'consultório médico', 'recepção de clínica', 'estetoscópio', 'paciente', 'equipe médica'],
  estetica: ['clínica de estética', 'tratamento facial', 'skincare', 'massagem', 'spa', 'beleza'],
  fisioterapia: ['fisioterapia', 'alongamento', 'reabilitação', 'pilates', 'massagem terapêutica', 'academia'],
  psicologia: ['terapia', 'consultório de psicologia', 'conversa', 'bem-estar', 'poltrona', 'calma'],
  advocacia: ['escritório de advocacia', 'advogado', 'balança da justiça', 'contrato', 'reunião de negócios', 'livros de direito'],
  engenharia: ['engenheiro', 'obra', 'projeto de engenharia', 'capacete de segurança', 'planta baixa', 'construção'],
  industria: ['indústria', 'fábrica', 'linha de produção', 'operário', 'máquinas industriais', 'galpão'],
  'servicos-empresariais': ['escritório', 'reunião de negócios', 'equipe de trabalho', 'atendimento ao cliente', 'aperto de mãos', 'computador'],
  contabilidade: ['contabilidade', 'calculadora', 'impostos', 'planilha', 'documentos', 'escritório'],
  'consultoria-financeira': ['consultoria financeira', 'investimentos', 'gráficos', 'planejamento financeiro', 'reunião', 'economia'],
  credito: ['financiamento imobiliário', 'casa nova', 'chaves de casa', 'contrato', 'família feliz', 'carro novo'],
  clinicas: ['clínica', 'saúde', 'recepção de clínica', 'equipe de saúde', 'paciente', 'bem-estar'],
  empresas: ['escritório', 'reunião de negócios', 'equipe', 'indústria', 'tecnologia', 'aperto de mãos'],
  financas: ['contabilidade', 'finanças', 'calculadora', 'gráficos', 'reunião', 'documentos'],
};

const GENERICAS = ['escritório', 'atendimento ao cliente', 'equipe de trabalho', 'reunião', 'aperto de mãos', 'cidade'];

/** Grupo do espaço de imagem: "equipe.3.f" → "equipe"; "hero.img2" → "hero". */
function grupoDaChave(chave) {
  return String(chave ?? '').split('.')[0];
}

function limpo(s) {
  return String(s ?? '').replace(/\s+/g, ' ').trim();
}

/**
 * Termo já pesquisado ao abrir a janela, a partir do nicho/especialidade e do espaço.
 * contexto: { nicho, especialidade, chave, rotulo, titulo } — titulo = texto do item (ex.: nome do serviço).
 *   equipe → "retrato profissional"; advocacia → "escritório de advocacia"; serviço "Implantes" → "Implantes".
 */
export function termoSugerido({ nicho = '', especialidade = '', chave = '', rotulo = '', titulo = '' } = {}) {
  const base = TERMO_BASE[especialidade] ?? TERMO_BASE[nicho] ?? 'escritório';
  const saude = nicho === 'clinicas' || ['odontologia', 'medicina', 'estetica', 'fisioterapia', 'psicologia'].includes(especialidade);
  const grupo = grupoDaChave(chave);
  const textoRotulo = limpo(rotulo).toLowerCase();
  if (grupo === 'equipe' || /\b(pessoa|retrato|profissional)\b/.test(textoRotulo)) return 'retrato profissional';
  if (grupo === 'dep') return saude ? 'paciente sorrindo' : 'cliente satisfeito';
  if (grupo === 'passos') return saude ? 'atendimento ao paciente' : 'atendimento ao cliente';
  if (grupo === 'serv') {
    const t = limpo(titulo);
    if (t !== '' && t.length <= 60 && !/[{}]/.test(t)) return t;
  }
  return base;
}

/** Chips de sugestão: o termo do espaço, depois os do nicho (sem repetir, no máximo `max`). */
export function sugestoesDoNicho({ nicho = '', especialidade = '' } = {}, termo = '', max = 7) {
  const lista = [termo, ...(SUGESTOES[especialidade] ?? []), ...(SUGESTOES[nicho] ?? []), ...GENERICAS];
  const vistos = new Set();
  const r = [];
  for (const s of lista) {
    const t = limpo(s);
    const k = t.toLowerCase();
    if (t === '' || vistos.has(k)) continue;
    vistos.add(k);
    r.push(t);
    if (r.length >= max) break;
  }
  return r;
}

/** Rótulo acessível da miniatura: descrição + fotógrafo. */
export function rotuloFoto(foto) {
  const alt = limpo(foto?.alt);
  const autor = limpo(foto?.autor);
  const partes = [alt || 'Foto sem descrição'];
  if (autor) partes.push(`foto de ${autor}`);
  return `${partes.join(', ')}. Usar esta foto`;
}

/* ================================================================== servidor */

let disponibilidade = null;

/** O banco de imagens está ligado no servidor? (consulta uma vez por carregamento). */
export function bancoDisponivel() {
  disponibilidade ??= api.get('/banco-imagens').then((r) => Boolean(r?.disponivel)).catch(() => {
    disponibilidade = null;
    return false;
  });
  return disponibilidade;
}

export function buscarFotos(termo, pagina = 1) {
  const q = new URLSearchParams({ q: termo, pagina: String(pagina) });
  return api.get(`/banco-imagens/buscar?${q}`);
}

export function importarFoto(siteId, fotoId) {
  return api.post('/banco-imagens/importar', { site_id: siteId, foto_id: fotoId });
}

/* ================================================================== janela */

/**
 * Abre a "Biblioteca de imagens".
 * contexto: { nicho, especialidade, chave, rotulo, titulo } (para o termo sugerido).
 * aoEscolher(midia): aplica a mídia importada no documento (UMA alteração desfazível).
 */
export function abrirBancoImagens({ siteId, contexto = {}, aoEscolher, disponivel = null }) {
  let fechar = () => {};
  const cab = el('div', { class: 'modal__cab fb-cab' },
    el('h2', { class: 'modal__titulo fb-cab__titulo', id: 'fb-titulo' }, 'Biblioteca de imagens'),
    el('span', { class: 'selo selo--ok fb-cab__selo' }, 'Grátis para uso comercial'),
    el('button', { type: 'button', class: 'icone-btn modal__fechar fb-cab__fechar', 'aria-label': 'Fechar', onclick: () => fechar() }, icone('fechar')));

  const corpo = el('div', { class: 'fb' });
  const janela = modal({ cabecalho: cab, rotulo: 'Biblioteca de imagens', corpo, tamanho: 'largo', classe: 'modal--fotos-banco' });
  janela.elemento.setAttribute('aria-labelledby', 'fb-titulo');
  janela.elemento.removeAttribute('aria-label');
  fechar = () => janela.fechar();

  const rodape = el('p', { class: 'fb-rodape' },
    'Imagens do ', el('a', { href: 'https://pixabay.com/', target: '_blank', rel: 'noopener noreferrer' }, 'Pixabay'),
    ' (uso grátis, inclusive comercial). A foto escolhida é copiada para o seu site com o crédito do autor.');

  const montar = (ligado) => {
    if (!ligado) {
      corpo.replaceChildren(el('div', { class: 'fb-desligado' },
        icone('imagem'),
        el('p', { class: 'fb-desligado__titulo' }, 'O banco de imagens ainda não está ligado.'),
        el('p', null,
          'Para ligar, crie uma conta grátis no ',
          el('a', { href: 'https://pixabay.com/api/docs/', target: '_blank', rel: 'noopener noreferrer' }, 'Pixabay'),
          ', copie a sua chave da API e coloque-a no arquivo .env do sistema, na linha PIXABAY_API_KEY (passo a passo no guia "Como testar").')),
      rodape);
      return;
    }
    montarBusca();
  };

  function montarBusca() {
    const termoInicial = termoSugerido(contexto);
    const entrada = el('input', {
      type: 'search', class: 'fb-busca__entrada', id: 'fb-busca', value: termoInicial, maxlength: '100',
      autocomplete: 'off', enterkeyhint: 'search', 'aria-label': 'O que você procura?', placeholder: 'Ex.: consultório odontológico',
    });
    const botao = el('button', { type: 'submit', class: 'btn btn--primario fb-busca__botao' }, icone('lupa'), 'Buscar');
    const form = el('form', { class: 'fb-busca', role: 'search' }, entrada, botao);
    const chips = el('div', { class: 'fb-chips', role: 'group', 'aria-label': 'Sugestões' },
      ...sugestoesDoNicho(contexto, termoInicial).map((s) => el('button', {
        type: 'button', class: 'fb-chip', onclick: () => {
          entrada.value = s;
          buscar(s, 1);
        },
      }, s)));
    const status = el('p', { class: 'fb-status', role: 'status', 'aria-live': 'polite' });
    const grade = el('ul', { class: 'fb-grade', 'aria-label': 'Fotos encontradas' });
    const mais = el('button', { type: 'button', class: 'btn fb-mais', hidden: true }, 'Carregar mais');
    corpo.replaceChildren(form, chips, status, grade, el('div', { class: 'fb-mais-caixa' }, mais), rodape);

    let atual = { termo: '', pagina: 0, temMais: false };
    let pedido = 0;
    let importando = false;

    async function buscar(termo, pagina) {
      termo = termo.trim();
      if (termo === '') {
        status.textContent = 'Digite o que você procura.';
        entrada.focus();
        return;
      }
      const meu = ++pedido;
      for (const c of chips.children) c.setAttribute('aria-pressed', String(c.textContent === termo));
      if (pagina === 1) {
        grade.replaceChildren(...Array.from({ length: 6 }, () => el('li', { class: 'fb-item fb-item--esqueleto', 'aria-hidden': 'true' })));
        mais.hidden = true;
      } else {
        mais.setAttribute('aria-busy', 'true');
        mais.disabled = true;
      }
      status.textContent = 'Buscando…';
      try {
        const r = await buscarFotos(termo, pagina);
        if (meu !== pedido) return;
        const fotos = Array.isArray(r?.fotos) ? r.fotos : [];
        if (pagina === 1) grade.replaceChildren();
        const novos = fotos.map(miniatura);
        grade.append(...novos);
        atual = { termo, pagina, temMais: Boolean(r?.temMais) };
        mais.hidden = !atual.temMais;
        const total = grade.querySelectorAll('.fb-item:not(.fb-item--esqueleto)').length;
        status.textContent = total === 0
          ? `Nenhuma foto para "${termo}". Tente outra palavra (ex.: em inglês ou mais simples).`
          : `${total} ${total === 1 ? 'foto' : 'fotos'} para "${termo}".`;
        if (pagina > 1) novos[0]?.querySelector('button')?.focus();
      } catch (e) {
        if (meu !== pedido) return;
        if (pagina === 1) grade.replaceChildren();
        status.textContent = e?.mensagem ?? 'Não foi possível buscar as fotos. Tente de novo.';
      } finally {
        if (meu === pedido) {
          mais.removeAttribute('aria-busy');
          mais.disabled = false;
        }
      }
    }

    function miniatura(foto) {
      const img = el('img', {
        src: foto.miniatura, alt: '', loading: 'lazy', decoding: 'async', class: 'fb-item__img', referrerpolicy: 'no-referrer',
      });
      const autor = el('span', { class: 'fb-item__autor', 'aria-hidden': 'true' }, foto.autor ? `Imagem de ${foto.autor}` : 'Pixabay');
      const estado = el('span', { class: 'fb-item__estado', 'aria-hidden': 'true' }, el('span', { class: 'giro' }), 'Baixando…');
      const b = el('button', {
        type: 'button', class: 'fb-item__botao', 'aria-label': rotuloFoto(foto), title: foto.autor ? `Foto de ${foto.autor}` : null,
        style: foto.cor ? `background-color:${foto.cor}` : null,
      }, img, autor, estado);
      b.addEventListener('click', () => escolher(foto, b));
      return el('li', { class: 'fb-item' }, b);
    }

    async function escolher(foto, botaoFoto) {
      if (importando) return;
      importando = true;
      botaoFoto.classList.add('fb-item__botao--baixando');
      botaoFoto.setAttribute('aria-busy', 'true');
      corpo.classList.add('fb--importando');
      status.textContent = 'Baixando a foto para o seu site…';
      try {
        const r = await importarFoto(siteId, foto.id);
        if (!r?.midia?.id) throw new Error('Resposta inesperada do servidor.');
        janela.fechar('importada');
        aoEscolher?.(r.midia, foto);
      } catch (e) {
        status.textContent = e?.mensagem ?? e?.message ?? 'Não foi possível baixar a foto. Tente de novo.';
        aviso(status.textContent, { tipo: 'erro', duracao: 9000 });
      } finally {
        importando = false;
        botaoFoto.classList.remove('fb-item__botao--baixando');
        botaoFoto.removeAttribute('aria-busy');
        corpo.classList.remove('fb--importando');
      }
    }

    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      buscar(entrada.value, 1);
    });
    mais.addEventListener('click', () => buscar(atual.termo, atual.pagina + 1));
    entrada.focus({ preventScroll: true });
    entrada.select();
    buscar(termoInicial, 1);
  }

  if (disponivel === true || disponivel === false) {
    montar(disponivel);
  } else {
    corpo.replaceChildren(el('div', { class: 'carregando', role: 'status' }, el('span', { class: 'giro', 'aria-hidden': 'true' }), el('span', null, 'Carregando…')));
    bancoDisponivel().then(montar);
  }
  return janela;
}
