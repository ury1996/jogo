// Dados estruturados do negócio [M6]: telefone, WhatsApp, endereço, horários, registro, redes.
// PARIDADE OBRIGATÓRIA com app/Preparo/Dados.php.

import { CLASSE_ESPACOS, codificarUri, colapsarEspacos, comoLista, comoMapa, temChave, textoDe } from './texto.mjs';

// DDDs brasileiros válidos (Anatel).
const DDDS = new Set([
  11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 24, 27, 28, 31, 32, 33, 34, 35, 37, 38,
  41, 42, 43, 44, 45, 46, 47, 48, 49, 51, 53, 54, 55, 61, 62, 63, 64, 65, 66, 67, 68, 69,
  71, 73, 74, 75, 77, 79, 81, 82, 83, 84, 85, 86, 87, 88, 89, 91, 92, 93, 94, 95, 96, 97, 98, 99,
]);
const RE_NUMERO_ESPECIAL = /^0[3589]00/; // 0800, 0300, 0500, 0900
const DIAS = [['seg', 'Seg'], ['ter', 'Ter'], ['qua', 'Qua'], ['qui', 'Qui'], ['sex', 'Sex'], ['sab', 'Sáb'], ['dom', 'Dom']];
const ROTULOS_REDES = { instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', youtube: 'YouTube', google: 'Google' };
const PERFIS_REDES = {
  instagram: 'https://www.instagram.com/',
  facebook: 'https://www.facebook.com/',
  linkedin: 'https://www.linkedin.com/in/',
  youtube: 'https://www.youtube.com/@',
};
const RE_EMAIL = new RegExp(`^[^${CLASSE_ESPACOS}@<>"']+@[^${CLASSE_ESPACOS}@<>"']+\\.[^${CLASSE_ESPACOS}@<>"']+$`);

export function soDigitos(s) {
  return textoDe(s).replace(/[^0-9]+/g, '');
}

/** Dígitos no formato nacional: tira +55 (12–13 dígitos) e o 0 de operadora. */
export function digitosNacionais(s) {
  let d = soDigitos(s);
  if ((d.length === 12 || d.length === 13) && d.startsWith('55')) d = d.slice(2);
  else if ((d.length === 11 || d.length === 12) && d.startsWith('0') && !RE_NUMERO_ESPECIAL.test(d)) d = d.slice(1);
  return d;
}

/** 10 ou 11 dígitos, DDD válido; celular (11) começa com 9; fixo (10) com 2–5. */
export function validarWhatsapp(s) {
  const d = digitosNacionais(s);
  if (d.length !== 10 && d.length !== 11) return false;
  if (!DDDS.has(Number(d.slice(0, 2)))) return false;
  if (d.length === 11) return d[2] === '9';
  return d[2] >= '2' && d[2] <= '5';
}

/** "(11) 98765-4321", "(11) 3456-7890", "0800 123 4567"; incompleto → como digitado. */
export function formatarTelefone(s) {
  const d = digitosNacionais(s);
  if (d.length === 11 && RE_NUMERO_ESPECIAL.test(d)) return `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7)}`;
  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  if (d.length === 9) return `${d.slice(0, 5)}-${d.slice(5)}`;
  if (d.length === 8) return `${d.slice(0, 4)}-${d.slice(4)}`;
  return colapsarEspacos(s);
}

/** https://wa.me/55{dígitos}?text={mensagem codificada}; sem dígitos → "". */
export function linkWhatsapp(numero, msg) {
  const d = digitosNacionais(numero);
  if (d === '') return '';
  const mensagem = textoDe(msg);
  return `https://wa.me/55${d}` + (mensagem !== '' ? `?text=${codificarUri(mensagem)}` : '');
}

/** tel:+55{dígitos} (0800 sem +55); sem dígitos → "". */
export function linkTelefone(s) {
  const d = digitosNacionais(s);
  if (d === '') return '';
  if (RE_NUMERO_ESPECIAL.test(d)) return `tel:${d}`;
  if (d.length === 10 || d.length === 11) return `tel:+55${d}`;
  return `tel:${d}`;
}

export function validarEmail(s) {
  return RE_EMAIL.test(colapsarEspacos(s));
}

/** CEP com 8 dígitos → "00000-000"; outro formato → como digitado. */
export function formatarCep(s) {
  const d = soDigitos(s);
  if (d.length === 8) return `${d.slice(0, 5)}-${d.slice(5)}`;
  return colapsarEspacos(s);
}

/**
 * Endereço em duas linhas: ["Rua X, 123 - Sala 4", "Bairro, Cidade - UF, CEP 00000-000"].
 * Sem logradouro → [] (cidade sozinha não é endereço). Cidade/UF vêm de dados.cidade/uf.
 */
export function linhasEndereco(dados) {
  const d = comoMapa(dados);
  const e = comoMapa(d.endereco);
  const logradouro = colapsarEspacos(e.logradouro);
  if (logradouro === '') return [];
  const numero = colapsarEspacos(e.numero);
  const complemento = colapsarEspacos(e.complemento);
  const bairro = colapsarEspacos(e.bairro);
  const cidade = colapsarEspacos(d.cidade);
  const uf = colapsarEspacos(d.uf).toUpperCase();
  const cep = formatarCep(e.cep);
  let linha1 = logradouro;
  if (numero !== '') linha1 += `, ${numero}`;
  if (complemento !== '') linha1 += ` - ${complemento}`;
  const cidadeUf = cidade !== '' && uf !== '' ? `${cidade} - ${uf}` : cidade + uf;
  let linha2 = bairro;
  if (cidadeUf !== '') linha2 = linha2 !== '' ? `${linha2}, ${cidadeUf}` : cidadeUf;
  if (cep !== '') linha2 = linha2 !== '' ? `${linha2}, CEP ${cep}` : `CEP ${cep}`;
  return linha2 !== '' ? [linha1, linha2] : [linha1];
}

/** "Rua X, 123 - Sala 4 - Bairro, Cidade - UF, CEP 00000-000" (ou ""). */
export function formatarEndereco(dados) {
  return linhasEndereco(dados).join(' - ');
}

/** "08:00" → "8h"; "08:30" → "8h30"; inválido → "". */
export function formatarHora(s) {
  const m = /^(\d{1,2}):(\d{2})$/.exec(colapsarEspacos(s));
  if (!m) return '';
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (h > 24 || min > 59) return '';
  return min === 0 ? `${h}h` : `${h}h${m[2]}`;
}

function formatarIntervalos(v) {
  const partes = [];
  for (let i = 0; i + 1 < v.length; i += 2) {
    const abre = formatarHora(v[i]);
    const fecha = formatarHora(v[i + 1]);
    if (abre !== '' && fecha !== '') partes.push(`${abre} às ${fecha}`);
  }
  return partes.join(' e ');
}

/**
 * Horários agrupando dias consecutivos iguais: [{dias:"Seg a Sex", horas:"8h às 18h"},
 * {dias:"Sáb", horas:"8h às 12h"}, {dias:"Dom", horas:"Fechado"}]. Dia ausente = não
 * informado (fica de fora); null = fechado. Nenhum dia aberto → [].
 */
export function formatarHorarios(horarios) {
  const h = comoMapa(horarios);
  const grupos = [];
  DIAS.forEach(([chave, nome], i) => {
    if (!temChave(h, chave)) return;
    const v = h[chave];
    let horas;
    if (v === null || v === false) horas = 'Fechado';
    else if (Array.isArray(v)) horas = formatarIntervalos(v);
    else return;
    if (horas === '') return;
    const ultimo = grupos[grupos.length - 1];
    if (ultimo && ultimo.horas === horas && ultimo.fim === i - 1) {
      ultimo.fim = i;
      ultimo.nomeFim = nome;
      ultimo.n += 1;
    } else {
      grupos.push({ inicio: i, fim: i, nomeInicio: nome, nomeFim: nome, horas, n: 1 });
    }
  });
  if (!grupos.some((g) => g.horas !== 'Fechado')) return [];
  return grupos.map((g) => ({
    dias: g.n === 1 ? g.nomeInicio : `${g.nomeInicio}${g.n === 2 ? ' e ' : ' a '}${g.nomeFim}`,
    horas: g.horas,
  }));
}

/** "Seg a Sex: 8h às 18h · Sáb: 8h às 12h · Dom: Fechado". */
export function horariosTexto(lista) {
  return comoLista(lista).map((g) => `${g.dias}: ${g.horas}`).join(' · ');
}

/**
 * "Responsável técnico: Dra. X · CRO-SP 12345" conforme especialidade.rotuloRegistro
 * (OAB usa barra: "OAB/SP 123.456"). Sem número → "".
 */
export function formatarRegistro(dados, especialidade) {
  const d = comoMapa(dados);
  const r = comoMapa(d.registro);
  const numero = colapsarEspacos(r.numero);
  if (numero === '') return '';
  const esp = comoMapa(especialidade);
  const rotulo = colapsarEspacos(esp.rotuloRegistro) || colapsarEspacos(esp.conselho) || 'Registro';
  const uf = (colapsarEspacos(r.uf) || colapsarEspacos(d.uf)).toUpperCase();
  const separador = rotulo === 'OAB' ? '/' : '-';
  const registro = uf !== '' ? `${rotulo}${separador}${uf} ${numero}` : `${rotulo} ${numero}`;
  const responsavel = colapsarEspacos(r.responsavel);
  if (responsavel === '') return registro;
  const rotuloResponsavel = colapsarEspacos(esp.rotuloResponsavel) || 'Responsável técnico';
  return `${rotuloResponsavel}: ${responsavel} · ${registro}`;
}

/** Primeira letra ou número do nome, em maiúscula (ou ""). */
export function inicial(nome) {
  const m = /[\p{L}\p{N}]/u.exec(textoDe(nome));
  return m ? m[0].toUpperCase() : '';
}

/** Tratamentos que não contam para a inicial de uma pessoa ("Dra. Beatriz" → "B"). */
const RE_TRATAMENTO = /^(?:(?:dr|dra|prof|profa|sr|sra|srta|me|ma|eng|enga|adv|arq|pe|pr|psic|fisio|nutri)\.?ª?\.?\s+)+/iu;

/** Inicial de uma pessoa, sem o tratamento (Dr., Dra., Prof.…); só tratamento → a inicial dele. */
export function inicialPessoa(nome) {
  const t = textoDe(nome).trim();
  return inicial(t.replace(RE_TRATAMENTO, '')) || inicial(t);
}

/**
 * URL https do perfil (aceita URL, "@usuario", "usuario" ou "dominio.com/…").
 * Só https:// sai daqui (§12.1); o resto vira "".
 */
export function urlRede(rede, valor) {
  const v = colapsarEspacos(valor);
  if (v === '') return '';
  let url = '';
  if (/^https:\/\//i.test(v)) url = 'https://' + v.slice(8);
  else if (/^http:\/\//i.test(v)) url = 'https://' + v.slice(7);
  else if (v.includes('/') || /^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)*\.(com|br|net|org|me|gl|be|page|link)$/i.test(v)) url = 'https://' + v;
  else {
    const usuario = v.startsWith('@') ? v.slice(1) : v;
    if (/^[A-Za-z0-9._-]+$/.test(usuario) && temChave(PERFIS_REDES, rede)) url = PERFIS_REDES[rede] + usuario;
  }
  return /^https:\/\/[^ "'<>`]+$/.test(url) ? url : '';
}

export function rotuloRede(rede) {
  return temChave(ROTULOS_REDES, rede) ? ROTULOS_REDES[rede] : rede;
}
