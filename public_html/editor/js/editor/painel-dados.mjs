// Aba Dados (PDF §7.5 + [M6]): dados estruturados do negócio — não são textos do site.
// Nome, especialidade, cidade + UF, WhatsApp (máscara e validação), telefone, e-mail,
// endereço com CEP (ViaCEP, tolerante a erro), horários por dia, registro profissional com o
// rótulo do conselho e responsável técnico, redes sociais (https://) e logo.
//
// Cada campo atualiza a prévia enquanto digita, mas entra no histórico como UM passo quando
// a alteração termina (change/blur fecham o grupo).

import { el, icone, campo, aviso, mascaraTelefone, problemaWhatsapp, UFS, ACEITA_LOGO, problemaArquivoLogo } from '../ui.mjs';
import { api } from '../api.mjs';
import { comoMapa, comoLista } from '../compartilhado/texto.mjs';
import { completarDados, especialidade as especialidadeDoc, nichoDe, DIAS, REDES } from '../compartilhado/documento.mjs';
import { rotuloRede } from '../compartilhado/dados.mjs';
import { escolherArquivo, enviarLogo } from './fotos.mjs';
import * as op from './operacoes.mjs';

const NOMES_DIAS = { seg: 'Segunda', ter: 'Terça', qua: 'Quarta', qui: 'Quinta', sex: 'Sexta', sab: 'Sábado', dom: 'Domingo' };
const HORARIO_PADRAO = ['08:00', '18:00'];
const EXEMPLOS_REDES = {
  instagram: 'https://www.instagram.com/seunegocio',
  facebook: 'https://www.facebook.com/seunegocio',
  linkedin: 'https://www.linkedin.com/company/seunegocio',
  youtube: 'https://www.youtube.com/@seunegocio',
  google: 'https://g.page/seunegocio',
};

function dadosDe(doc) {
  return completarDados(comoMapa(doc).dados);
}

/** Documento com `dados` alterado por `fn(dados)` (cópia profunda só da camada dados). */
function comDados(doc, fn) {
  const dados = JSON.parse(JSON.stringify(dadosDe(doc)));
  return { ...doc, dados: fn(dados) ?? dados };
}

function titulo(texto, id) {
  return el('h3', { class: 'ed-painel__titulo', id }, texto);
}

export function criarPainelDados(ed) {
  const { lib } = ed;
  const campos = new Map(); // chave ("dados.whatsapp") → { entrada, definirErro, ler, validar }
  const sincronizadores = [];

  /**
   * Liga um <input> ao documento.
   *   chave      "dados.cidade" (para "Ir até lá" e o grupo de histórico)
   *   ler(doc)   valor para mostrar
   *   gravar(doc, valor) → novo doc
   *   mascara(valor) → valor mostrado enquanto digita
   *   aoSair(valor)  → valor normalizado ao terminar (ex.: rede → https://…)
   *   validar(valor) → mensagem ou null
   */
  function ligar({ chave, entrada, ler, gravar, rotulo, mascara = null, aoSair = null, validar = null, uiCampo = null }) {
    let mostrouErro = false;
    const aplicarValor = (valor) => {
      ed.aplicar((d) => gravar(d, valor), { rotulo, agrupar: chave });
    };
    entrada.addEventListener('input', () => {
      if (mascara) {
        const fimAntes = entrada.selectionStart === entrada.value.length;
        const novo = mascara(entrada.value);
        if (novo !== entrada.value) {
          entrada.value = novo;
          if (fimAntes) entrada.setSelectionRange(novo.length, novo.length);
        }
      }
      aplicarValor(entrada.value);
      if (mostrouErro && validar) uiCampo?.definirErro(validar(entrada.value));
    });
    const terminar = () => {
      if (aoSair) {
        const normal = aoSair(entrada.value);
        if (normal !== entrada.value) {
          entrada.value = normal;
          aplicarValor(normal);
        }
      }
      ed.estado.encerrarGrupo();
      if (validar) {
        const erro = validar(entrada.value);
        uiCampo?.definirErro(erro);
        mostrouErro = Boolean(erro);
      }
    };
    entrada.addEventListener('change', terminar);
    entrada.addEventListener('blur', () => ed.estado.encerrarGrupo());
    campos.set(chave, { entrada, uiCampo, validar });
    sincronizadores.push((doc) => {
      if (document.activeElement === entrada) return;
      const v = ler(doc) ?? '';
      if (entrada.type === 'checkbox') entrada.checked = Boolean(v);
      else if (entrada.value !== v) entrada.value = v;
    });
  }

  function campoTexto({ chave, rotulo, ajuda = '', opcional = false, max = 0, tipo = 'text', autocomplete = 'off', inputmode = null, placeholder = '', ...resto }) {
    const entrada = el('input', { type: tipo, autocomplete, inputmode, placeholder, spellcheck: tipo === 'text' ? 'true' : 'false' });
    const ui = campo({ rotulo, entrada, ajuda, opcional, max });
    ligar({ chave, entrada, rotulo: `Alterar ${rotulo.toLowerCase()}`, uiCampo: ui, ...resto });
    return ui;
  }

  const gravarDado = (caminho) => (d, valor) => comDados(d, (dados) => {
    let alvo = dados;
    for (const p of caminho.slice(0, -1)) alvo = alvo[p];
    alvo[caminho.at(-1)] = valor;
    return dados;
  });
  const lerDado = (caminho) => (doc) => caminho.reduce((v, p) => comoMapa(v)[p], dadosDe(doc));

  /* ---------------------------------------------------------------- negócio */

  const nome = campoTexto({
    chave: 'dados.nome', rotulo: 'Nome do negócio', max: 80, autocomplete: 'organization',
    ler: lerDado(['nome']), gravar: gravarDado(['nome']),
    validar: (v) => (v.trim() === '' ? 'O nome aparece no topo, no rodapé e no título do site.' : null),
  });

  const especialidades = comoLista(nichoDe(ed.estado.doc, lib).especialidades).filter((e) => e && e.id);
  const selectEsp = el('select', null, especialidades.map((e) => el('option', { value: e.id }, e.nome ?? e.id)));
  const campoEsp = campo({ rotulo: 'Especialidade', entrada: selectEsp, ajuda: 'Define o segmento usado nos textos, o conselho profissional e os ícones sugeridos.' });
  selectEsp.addEventListener('change', () => {
    ed.estado.encerrarGrupo();
    ed.aplicar((d) => ({ ...d, especialidade: selectEsp.value }), { rotulo: 'Trocar especialidade' });
  });
  campos.set('especialidade', { entrada: selectEsp, uiCampo: campoEsp });
  sincronizadores.push((doc) => {
    if (document.activeElement !== selectEsp) selectEsp.value = comoMapa(especialidadeDoc(doc, lib)).id ?? '';
  });

  const cidade = campoTexto({
    chave: 'dados.cidade', rotulo: 'Cidade', max: 60, autocomplete: 'address-level2',
    ler: lerDado(['cidade']), gravar: gravarDado(['cidade']),
    validar: (v) => (v.trim() === '' ? 'Sem cidade, o site mostra a cidade de exemplo.' : null),
  });
  const selectUf = el('select', { autocomplete: 'address-level1' }, el('option', { value: '' }, '—'), UFS.map((u) => el('option', { value: u }, u)));
  const campoUf = campo({ rotulo: 'UF', entrada: selectUf });
  ligar({ chave: 'dados.uf', entrada: selectUf, rotulo: 'Alterar UF', ler: lerDado(['uf']), gravar: gravarDado(['uf']), uiCampo: campoUf });

  /* ---------------------------------------------------------------- contato */

  const whatsapp = campoTexto({
    chave: 'dados.whatsapp', rotulo: 'WhatsApp', tipo: 'tel', inputmode: 'tel', autocomplete: 'tel-national',
    placeholder: '(11) 98765-4321', ajuda: 'Todos os botões de contato do site abrem este WhatsApp.',
    ler: lerDado(['whatsapp']), gravar: gravarDado(['whatsapp']), mascara: mascaraTelefone,
    validar: (v) => (v.trim() === '' ? 'Informe o WhatsApp para os botões funcionarem.' : problemaWhatsapp(v)),
  });
  const telefone = campoTexto({
    chave: 'dados.telefone', rotulo: 'Telefone fixo', tipo: 'tel', inputmode: 'tel', opcional: true, placeholder: '(11) 3456-7890',
    ler: lerDado(['telefone']), gravar: gravarDado(['telefone']), mascara: mascaraTelefone, validar: op.problemaTelefone,
  });
  const email = campoTexto({
    chave: 'dados.email', rotulo: 'E-mail', tipo: 'email', inputmode: 'email', autocomplete: 'email', opcional: true,
    placeholder: 'contato@seunegocio.com.br', ler: lerDado(['email']), gravar: gravarDado(['email']),
    aoSair: (v) => v.trim(), validar: op.problemaEmail,
  });

  /* ---------------------------------------------------------------- endereço */

  const statusCep = el('p', { class: 'campo__ajuda ed-cep__status', role: 'status' });
  const cep = campoTexto({
    chave: 'dados.endereco.cep', rotulo: 'CEP', inputmode: 'numeric', autocomplete: 'postal-code', opcional: true, placeholder: '00000-000',
    ler: lerDado(['endereco', 'cep']), gravar: gravarDado(['endereco', 'cep']), mascara: op.mascaraCep,
  });
  cep.elemento.append(statusCep);
  const logradouro = campoTexto({
    chave: 'dados.endereco.logradouro', rotulo: 'Rua / avenida', opcional: true, autocomplete: 'address-line1', max: 120,
    ler: lerDado(['endereco', 'logradouro']), gravar: gravarDado(['endereco', 'logradouro']),
  });
  const numero = campoTexto({
    chave: 'dados.endereco.numero', rotulo: 'Número', opcional: true, inputmode: 'text', max: 12,
    ler: lerDado(['endereco', 'numero']), gravar: gravarDado(['endereco', 'numero']),
  });
  const complemento = campoTexto({
    chave: 'dados.endereco.complemento', rotulo: 'Complemento', opcional: true, autocomplete: 'address-line2', max: 60,
    placeholder: 'Sala 4', ler: lerDado(['endereco', 'complemento']), gravar: gravarDado(['endereco', 'complemento']),
  });
  const bairro = campoTexto({
    chave: 'dados.endereco.bairro', rotulo: 'Bairro', opcional: true, max: 60,
    ler: lerDado(['endereco', 'bairro']), gravar: gravarDado(['endereco', 'bairro']),
  });

  let buscaCep = null;
  let ultimoCep = '';
  async function consultarCep() {
    const digitos = cep.entrada.value.replace(/\D/g, '');
    if (digitos.length !== 8 || digitos === ultimoCep) return;
    ultimoCep = digitos;
    buscaCep?.abort();
    const controle = new AbortController();
    buscaCep = controle;
    const tempo = setTimeout(() => controle.abort(), 7000);
    statusCep.textContent = 'Buscando o endereço…';
    try {
      const resp = await fetch(`https://viacep.com.br/ws/${digitos}/json/`, { signal: controle.signal, credentials: 'omit' });
      const endereco = op.enderecoDoViaCep(resp.ok ? await resp.json() : null);
      // A resposta chegou depois de a pessoa mudar o CEP (ou de fechar o editor): descarta.
      if (buscaCep !== controle || ed.desmontado || cep.entrada.value.replace(/\D/g, '') !== digitos) return;
      if (!endereco) {
        statusCep.textContent = 'Não encontramos este CEP. Confira os números ou preencha o endereço abaixo.';
        return;
      }
      ed.estado.encerrarGrupo();
      ed.aplicar((d) => comDados(d, (dados) => {
        dados.endereco.cep = op.mascaraCep(digitos);
        dados.endereco.logradouro = endereco.logradouro || dados.endereco.logradouro;
        dados.endereco.bairro = endereco.bairro || dados.endereco.bairro;
        dados.cidade = endereco.cidade;
        dados.uf = endereco.uf;
        return dados;
      }), { rotulo: 'Preencher endereço pelo CEP' });
      statusCep.textContent = `Endereço encontrado: ${[endereco.logradouro, endereco.bairro, `${endereco.cidade} - ${endereco.uf}`].filter(Boolean).join(', ')}. Confira o número.`;
      atualizar();
      if (!numero.entrada.value) numero.entrada.focus();
    } catch (e) {
      if (controle.signal.aborted && buscaCep !== controle) return;
      statusCep.textContent = 'Não foi possível consultar o CEP agora. Preencha o endereço abaixo.';
      ultimoCep = '';
    } finally {
      clearTimeout(tempo);
    }
  }
  cep.entrada.addEventListener('input', () => {
    if (op.cepCompleto(cep.entrada.value)) {
      consultarCep();
    } else {
      // CEP incompleto: a consulta anterior (de outro CEP) não vale mais.
      buscaCep?.abort();
      buscaCep = null;
      ultimoCep = '';
      statusCep.textContent = '';
    }
  });

  /* ---------------------------------------------------------------- horários */

  const informar = el('input', { type: 'checkbox', role: 'switch' });
  const linhasHorario = el('div', { class: 'ed-horarios', role: 'group', 'aria-label': 'Horário por dia' });
  const errosHorario = el('p', { class: 'campo__erro', hidden: true, role: 'alert' });
  const copiar = el('button', { type: 'button', class: 'btn btn--pequeno btn--fantasma', onclick: copiarSegunda }, icone('duplicar'), 'Copiar segunda para os dias úteis');
  const controlesDia = {};

  function gravarHorarios(fn, rotulo, agrupar = null) {
    ed.aplicar((d) => comDados(d, (dados) => {
      dados.horarios = op.ordenarHorarios(fn({ ...dados.horarios }));
      return dados;
    }), { rotulo, agrupar });
  }

  for (const dia of DIAS) {
    const aberto = el('input', { type: 'checkbox', 'aria-label': `${NOMES_DIAS[dia]}: aberto` });
    const abre = el('input', { type: 'time', class: 'campo__entrada', 'aria-label': `${NOMES_DIAS[dia]}: abre às`, step: '900' });
    const fecha = el('input', { type: 'time', class: 'campo__entrada', 'aria-label': `${NOMES_DIAS[dia]}: fecha às`, step: '900' });
    const fechadoTxt = el('span', { class: 'ed-horario__fechado' }, 'Fechado');
    aberto.addEventListener('change', () => {
      ed.estado.encerrarGrupo();
      gravarHorarios((h) => ({ ...h, [dia]: aberto.checked ? [abre.value || HORARIO_PADRAO[0], fecha.value || HORARIO_PADRAO[1]] : null }),
        aberto.checked ? `Abrir ${NOMES_DIAS[dia].toLowerCase()}` : `Fechar ${NOMES_DIAS[dia].toLowerCase()}`);
    });
    for (const entrada of [abre, fecha]) {
      entrada.addEventListener('input', () => {
        if (!abre.value || !fecha.value) return;
        gravarHorarios((h) => ({ ...h, [dia]: [abre.value, fecha.value] }), 'Alterar horário', `horario.${dia}`);
      });
      entrada.addEventListener('change', () => {
        ed.estado.encerrarGrupo();
        validarHorarios();
      });
    }
    controlesDia[dia] = { aberto, abre, fecha, fechadoTxt };
    linhasHorario.append(el('div', { class: 'ed-horario' },
      el('label', { class: 'ed-horario__dia' }, aberto, el('span', null, NOMES_DIAS[dia])),
      el('div', { class: 'ed-horario__horas' }, abre, el('span', { class: 'ed-horario__ate', 'aria-hidden': 'true' }, 'às'), fecha),
      fechadoTxt));
  }

  function copiarSegunda() {
    ed.estado.encerrarGrupo();
    gravarHorarios((h) => op.copiarSegundaParaUteis(h), 'Copiar horário de segunda');
    aviso('Horário de segunda copiado para terça a sexta.', { acao: { rotulo: 'Desfazer', fn: () => ed.desfazer() } });
  }

  informar.addEventListener('change', () => {
    ed.estado.encerrarGrupo();
    if (informar.checked) {
      gravarHorarios(() => ({
        seg: HORARIO_PADRAO.slice(), ter: HORARIO_PADRAO.slice(), qua: HORARIO_PADRAO.slice(), qui: HORARIO_PADRAO.slice(),
        sex: HORARIO_PADRAO.slice(), sab: ['08:00', '12:00'], dom: null,
      }), 'Informar horários');
    } else {
      gravarHorarios(() => ({}), 'Não informar horários');
    }
  });

  function validarHorarios() {
    const h = dadosDe(ed.estado.doc).horarios;
    const problemas = DIAS.filter((d) => Object.prototype.hasOwnProperty.call(h, d))
      .map((d) => [d, op.problemaHorario(h[d])]).filter(([, p]) => p);
    errosHorario.hidden = problemas.length === 0;
    errosHorario.textContent = problemas.map(([d, p]) => `${NOMES_DIAS[d]}: ${p}`).join(' ');
  }

  sincronizadores.push((doc) => {
    const h = dadosDe(doc).horarios;
    const tem = Object.keys(h).length > 0;
    informar.checked = tem;
    linhasHorario.hidden = !tem;
    copiar.hidden = !tem;
    for (const dia of DIAS) {
      const c = controlesDia[dia];
      const v = h[dia];
      const aberto = Array.isArray(v);
      c.aberto.checked = aberto;
      c.abre.hidden = !aberto;
      c.fecha.hidden = !aberto;
      c.abre.parentElement.hidden = !aberto;
      c.fechadoTxt.hidden = aberto;
      if (aberto) {
        if (document.activeElement !== c.abre) c.abre.value = v[0] ?? '';
        if (document.activeElement !== c.fecha) c.fecha.value = v[1] ?? '';
      }
    }
  });
  campos.set('dados.horarios', { entrada: informar });

  /* ---------------------------------------------------------------- registro profissional */

  const blocoRegistro = el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-registro' });
  const tituloRegistro = titulo('Registro profissional', 'ed-t-registro');
  const ajudaRegistro = el('p', { class: 'ed-painel__ajuda' });
  const registroNumero = campoTexto({
    chave: 'dados.registro.numero', rotulo: 'Número do registro', max: 30,
    ler: lerDado(['registro', 'numero']), gravar: gravarDado(['registro', 'numero']),
    validar: (v) => (registroObrigatorio() && v.trim() === '' ? `O número do ${rotuloConselho()} é obrigatório para publicar.` : null),
  });
  const selectUfReg = el('select', null, el('option', { value: '' }, '—'), UFS.map((u) => el('option', { value: u }, u)));
  const campoUfReg = campo({ rotulo: 'UF', entrada: selectUfReg });
  ligar({ chave: 'dados.registro.uf', entrada: selectUfReg, rotulo: 'Alterar UF do registro', ler: lerDado(['registro', 'uf']), gravar: gravarDado(['registro', 'uf']), uiCampo: campoUfReg });
  const responsavel = campoTexto({
    chave: 'dados.registro.responsavel', rotulo: 'Responsável técnico', max: 80, autocomplete: 'name',
    placeholder: 'Dra. Ana Souza', ler: lerDado(['registro', 'responsavel']), gravar: gravarDado(['registro', 'responsavel']),
    validar: (v) => (registroObrigatorio() && v.trim() === '' ? 'Informe quem é o responsável técnico (nome que aparece com o registro).' : null),
  });
  blocoRegistro.append(tituloRegistro, ajudaRegistro,
    el('div', { class: 'campo__linha' }, registroNumero.elemento, campoUfReg.elemento), responsavel.elemento);

  function especialidadeAtual() {
    return comoMapa(especialidadeDoc(ed.estado.doc, lib));
  }
  function registroObrigatorio() {
    return especialidadeAtual().registroObrigatorio === true;
  }
  function rotuloConselho() {
    const e = especialidadeAtual();
    return e.rotuloRegistro || e.conselho || 'registro';
  }
  sincronizadores.push(() => {
    const obrig = registroObrigatorio();
    const rotulo = rotuloConselho();
    tituloRegistro.textContent = obrig ? `Registro no ${rotulo}` : 'Registro profissional';
    ajudaRegistro.textContent = obrig
      ? `Obrigatório para ${String(especialidadeAtual().nome ?? 'esta profissão').toLowerCase()}: aparece no rodapé do site, como pedem as regras do conselho.`
      : 'Opcional. Se tiver, aparece no rodapé do site.';
    registroNumero.elemento.querySelector('.campo__rotulo').firstChild.textContent = obrig ? `Número do ${rotulo}` : 'Número do registro';
    registroNumero.elemento.querySelector('.campo__opcional')?.remove();
    responsavel.elemento.querySelector('.campo__opcional')?.remove();
    if (!obrig) {
      registroNumero.elemento.querySelector('.campo__rotulo').append(el('span', { class: 'campo__opcional' }, ' (opcional)'));
      responsavel.elemento.querySelector('.campo__rotulo').append(el('span', { class: 'campo__opcional' }, ' (opcional)'));
    }
    registroNumero.entrada.required = obrig;
    registroNumero.entrada.setAttribute('aria-required', obrig ? 'true' : 'false');
    responsavel.entrada.setAttribute('aria-required', obrig ? 'true' : 'false');
  });

  /* ---------------------------------------------------------------- redes */

  const redes = REDES.map((rede) => campoTexto({
    chave: `dados.redes.${rede}`, rotulo: rotuloRede(rede), tipo: 'url', inputmode: 'url', opcional: true,
    placeholder: EXEMPLOS_REDES[rede] ?? 'https://',
    ler: lerDado(['redes', rede]), gravar: gravarDado(['redes', rede]),
    aoSair: (v) => op.normalizarRede(rede, v), validar: (v) => op.problemaRede(rede, v),
  }));

  /* ---------------------------------------------------------------- logo */

  const logoPrevia = el('div', { class: 'ed-logo__previa' });
  const logoNome = el('span', { class: 'ed-logo__info' });
  const logoProgresso = el('progress', { class: 'ed-logo__progresso', max: '100', value: '0', hidden: true, 'aria-label': 'Enviando logo' });
  const botaoLogo = el('button', { type: 'button', class: 'btn btn--pequeno', onclick: escolherLogo }, icone('enviar'), 'Enviar logo');
  const removerLogo = el('button', {
    type: 'button', class: 'btn btn--pequeno btn--fantasma btn--perigo-suave',
    onclick: () => {
      ed.estado.encerrarGrupo();
      ed.aplicar((d) => comDados(d, (dados) => { dados.logo = null; return dados; }), { rotulo: 'Remover logo' });
      aviso('Logo removido. O site usa o nome com a inicial.', { acao: { rotulo: 'Desfazer', fn: () => ed.desfazer() } });
    },
  }, icone('lixeira'), 'Remover');
  campos.set('dados.logo', { entrada: botaoLogo });

  async function escolherLogo() {
    const arquivo = await escolherArquivo(ACEITA_LOGO);
    if (!arquivo) return;
    const problema = problemaArquivoLogo(arquivo);
    if (problema) {
      aviso(problema, { tipo: 'erro' });
      return;
    }
    botaoLogo.setAttribute('aria-busy', 'true');
    logoProgresso.hidden = false;
    logoProgresso.value = 0;
    try {
      const midia = await enviarLogo(ed, arquivo, (f) => { logoProgresso.value = Math.round(f * 100); });
      ed.estado.encerrarGrupo();
      ed.aplicar((d) => comDados(d, (dados) => { dados.logo = midia.id; return dados; }), { rotulo: 'Trocar logo' });
      aviso('Logo enviado.', { tipo: 'ok' });
    } catch (e) {
      aviso(e?.mensagem ?? e?.message ?? 'Não foi possível enviar o logo.', { tipo: 'erro' });
    } finally {
      botaoLogo.removeAttribute('aria-busy');
      logoProgresso.hidden = true;
    }
  }

  sincronizadores.push((doc) => {
    const id = dadosDe(doc).logo;
    const m = id ? ed.estado.midia[id] : null;
    logoPrevia.replaceChildren();
    if (id) {
      const w = m?.formato === 'svg' ? 'orig' : (comoLista(m?.variantes).includes(320) ? 320 : (comoLista(m?.variantes)[0] ?? 'orig'));
      logoPrevia.append(el('img', { src: m?.local ?? api.url(`/media/${id}/${w}`), alt: 'Logo atual', class: 'ed-logo__img' }));
      logoNome.textContent = m?.largura ? `${m.largura} × ${m.altura} px` : '';
      botaoLogo.lastChild.textContent = 'Trocar logo';
      removerLogo.hidden = false;
    } else {
      logoPrevia.append(el('span', { class: 'ed-logo__vazio', 'aria-hidden': 'true' }, (dadosDe(doc).nome || 'A').trim().charAt(0).toUpperCase()));
      logoNome.textContent = 'Sem logo, o site usa o nome com a inicial.';
      botaoLogo.lastChild.textContent = 'Enviar logo';
      removerLogo.hidden = true;
    }
  });

  /* ---------------------------------------------------------------- montagem */

  const elemento = el('div', { class: 'ed-painel-dados formulario' },
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-negocio' },
      titulo('Negócio', 'ed-t-negocio'),
      nome.elemento,
      especialidades.length > 1 ? campoEsp.elemento : null,
      el('div', { class: 'campo__linha' }, cidade.elemento, campoUf.elemento)),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-contato' },
      titulo('Contato', 'ed-t-contato'), whatsapp.elemento, telefone.elemento, email.elemento),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-endereco' },
      titulo('Endereço', 'ed-t-endereco'),
      el('p', { class: 'ed-painel__ajuda' }, 'Aparece no contato, no rodapé e no mapa. Digite o CEP para preencher sozinho.'),
      cep.elemento, logradouro.elemento,
      el('div', { class: 'campo__linha campo__linha--meio' }, numero.elemento, complemento.elemento),
      bairro.elemento),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-horarios' },
      titulo('Horário de atendimento', 'ed-t-horarios'),
      el('label', { class: 'alternar' }, informar, 'Mostrar horários no site'),
      linhasHorario, errosHorario, copiar),
    blocoRegistro,
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-redes' },
      titulo('Redes sociais', 'ed-t-redes'),
      el('p', { class: 'ed-painel__ajuda' }, 'Cole o endereço do perfil (começando com https://). Aparecem no rodapé.'),
      redes.map((r) => r.elemento)),
    el('section', { class: 'ed-painel__bloco', 'aria-labelledby': 'ed-t-logo' },
      titulo('Logo', 'ed-t-logo'),
      el('div', { class: 'ed-logo' }, logoPrevia, el('div', { class: 'ed-logo__textos' }, logoNome,
        el('div', { class: 'ed-logo__acoes' }, botaoLogo, removerLogo), logoProgresso)),
      el('p', { class: 'ed-painel__ajuda' }, 'PNG, JPG, SVG ou WebP até 5 MB. Prefira fundo transparente.')));

  function atualizar() {
    const doc = ed.estado.doc;
    for (const s of sincronizadores) s(doc);
  }

  /** "Ir até lá": rola até o campo, foca e mostra o erro. */
  function focarCampo(chave) {
    let c = campos.get(chave);
    if (!c && chave.startsWith('dados.registro')) c = campos.get('dados.registro.numero');
    if (!c && chave.startsWith('dados.endereco')) c = campos.get('dados.endereco.cep');
    if (!c) return false;
    c.entrada.scrollIntoView({ block: 'center' });
    c.entrada.focus({ preventScroll: true });
    if (c.validar && c.uiCampo) c.uiCampo.definirErro(c.validar(c.entrada.value));
    return true;
  }

  return { elemento, atualizar, focarCampo };
}
