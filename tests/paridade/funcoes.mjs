// Registro das funções puras comparadas entre JS e PHP ("Classe.metodo" igual ao PHP) e
// geração automática das chamadas de teste. renderizar.php tem o mesmo registro.

import * as texto from '../../public_html/editor/js/compartilhado/texto.mjs';
import * as paleta from '../../public_html/editor/js/compartilhado/paleta.mjs';
import * as tons from '../../public_html/editor/js/compartilhado/tons.mjs';
import * as icones from '../../public_html/editor/js/compartilhado/icones.mjs';
import * as dados from '../../public_html/editor/js/compartilhado/dados.mjs';
import * as textos from '../../public_html/editor/js/compartilhado/textos.mjs';
import * as documento from '../../public_html/editor/js/compartilhado/documento.mjs';
import * as preparo from '../../public_html/editor/js/compartilhado/preparo.mjs';
import * as fotos from '../../public_html/editor/js/compartilhado/fotos.mjs';

export const FUNCOES = {
  'Texto.semAcentos': texto.semAcentos,
  'Texto.normalizar': texto.normalizar,
  'Texto.colapsarEspacos': texto.colapsarEspacos,
  'Texto.aparar': texto.aparar,
  'Texto.escapeHtml': texto.escapeHtml,
  'Texto.arred': texto.arred,
  'Texto.codificarUri': texto.codificarUri,
  'Paleta.hexParaRgb': paleta.hexParaRgb,
  'Paleta.normalizarCor': paleta.normalizarCor,
  'Paleta.rgbParaHsl': paleta.rgbParaHsl,
  'Paleta.hslParaHex': paleta.hslParaHex,
  'Paleta.luminancia': paleta.luminancia,
  'Paleta.contraste': paleta.contraste,
  'Paleta.gerarPaleta': paleta.gerarPaleta,
  'Paleta.cssPaleta': paleta.cssPaleta,
  'Tons.calcularFundos': tons.calcularFundos,
  'Icones.escolherIcone': icones.escolherIcone,
  'Icones.iconeDoItem': icones.iconeDoItem,
  'Icones.svg': icones.svg,
  'Dados.soDigitos': dados.soDigitos,
  'Dados.validarWhatsapp': dados.validarWhatsapp,
  'Dados.formatarTelefone': dados.formatarTelefone,
  'Dados.linkWhatsapp': dados.linkWhatsapp,
  'Dados.linkTelefone': dados.linkTelefone,
  'Dados.validarEmail': dados.validarEmail,
  'Dados.formatarCep': dados.formatarCep,
  'Dados.linhasEndereco': dados.linhasEndereco,
  'Dados.formatarEndereco': dados.formatarEndereco,
  'Dados.formatarHora': dados.formatarHora,
  'Dados.formatarHorarios': dados.formatarHorarios,
  'Dados.formatarRegistro': dados.formatarRegistro,
  'Dados.inicial': dados.inicial,
  'Dados.inicialPessoa': dados.inicialPessoa,
  'Dados.urlRede': dados.urlRede,
  'Textos.contextoVariaveis': textos.contextoVariaveis,
  'Textos.substituirVariaveis': textos.substituirVariaveis,
  'Textos.textoPadrao': textos.textoPadrao,
  'Textos.textoEfetivo': textos.textoEfetivo,
  'Textos.ehPadrao': textos.ehPadrao,
  'Textos.retokenizar': textos.retokenizar,
  'Textos.itensLista': textos.itensLista,
  'Textos.textosPadraoEfetivos': textos.textosPadraoEfetivos,
  'Textos.aplicarEdicaoTexto': textos.aplicarEdicaoTexto,
  'Textos.adicionarItem': textos.adicionarItem,
  'Textos.removerItem': textos.removerItem,
  'Textos.moverItem': textos.moverItem,
  'Documento.criarDocumento': documento.criarDocumento,
  'Fotos.generoDoNome': fotos.generoDoNome,
  'Fotos.imagensDeExemplo': fotos.imagensDeExemplo,
  'Documento.receitaModelo': documento.receitaModelo,
  'Documento.aplicarModelo': documento.aplicarModelo,
  'Documento.migrar': documento.migrar,
  'Documento.registroCampos': documento.registroCampos,
  'Documento.registroListas': documento.registroListas,
  'Documento.especialidade': documento.especialidade,
  'Documento.completarDados': documento.completarDados,
  'Html.imagem': preparo.imagemHtml,
  'Html.logo': preparo.logoHtml,
  'Preparo.montarDados': preparo.montarDados,
  'Preparo.renderizarTemplate': preparo.renderizarTemplate,
};

/** Argumento "$lib" vira a biblioteca; "$lib.a.b", um pedaço dela (evita mandar o JSON inteiro). */
export function resolverArgumento(a, lib) {
  if (a === '$lib') return lib;
  if (typeof a === 'string' && a.startsWith('$lib.')) return a.slice(5).split('.').reduce((v, k) => (v == null ? v : v[k]), lib);
  return a;
}

/** Executa as chamadas em JS. */
export function executarFuncoes(chamadas, lib) {
  return chamadas.map(({ nome, fn, args }) => {
    const funcao = FUNCOES[fn];
    if (!funcao) return { nome, erro: `função desconhecida: ${fn}` };
    try {
      const valor = funcao(...args.map((a) => resolverArgumento(a, lib)));
      return { nome, resultado: valor === undefined ? null : JSON.parse(JSON.stringify(valor)) };
    } catch (erro) {
      return { nome, erro: String(erro?.message ?? erro) };
    }
  });
}

// Gerador pseudoaleatório determinístico (LCG) — os casos são sempre os mesmos.
function sorteador(semente) {
  let s = semente >>> 0;
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

const hex2 = (n) => n.toString(16).padStart(2, '0');

/** ~200 cores (grade + sorteio + casos-limite) para a paleta. */
export function coresDeTeste() {
  const cores = ['#c23b6e', '#f5d90a', '#1b2a4a', '#777777', '#000000', '#ffffff', '#808080', '#fff3b0', '#2a7f86',
    '#2e6fd1', '#14161a', '#ff0000', '#00ff00', '#0000ff', '#C23B6E', 'abc', '#ABC', 'zzz', '', '#12345', '  #2e6fd1  '];
  const passos = [0, 51, 128, 204, 255];
  for (const r of passos) for (const g of passos) for (const b of passos) cores.push(`#${hex2(r)}${hex2(g)}${hex2(b)}`);
  const rnd = sorteador(42);
  while (cores.length < 220) cores.push(`#${hex2(Math.floor(rnd() * 256))}${hex2(Math.floor(rnd() * 256))}${hex2(Math.floor(rnd() * 256))}`);
  return cores;
}

/** ~100+ títulos para o ícone automático: palavras-chave, variações e títulos dos nichos. */
export function titulosDeTeste(lib) {
  const titulos = new Set([
    'Direito Trabalhista', 'Direito Previdenciário', 'Planejamento tributário', 'Contador dedicado', 'Energia solar',
    'Ortodontia', 'Implantes', 'Harmonização facial', 'Projetos elétricos', 'Plantão 24 horas', 'Consultoria técnica',
    'Emissão de ART', 'Parte elétrica', 'ART', 'art.', 'Arte', '(ART)', 'CONSULTORIA   TÉCNICA', 'Consultoria Técnica 24 horas',
    '', '   ', 'xyz', '123', 'Ação & reação <b>', 'Clareamento dental', 'Limpeza', 'Atendimento', 'Odontologia estética',
  ]);
  const lista = Array.isArray(lib?.icones?.icones) ? lib.icones.icones : [];
  for (const icone of lista) {
    for (const p of Array.isArray(icone?.palavras) ? icone.palavras : []) {
      titulos.add(p);
      titulos.add(`Serviço de ${p}`.toUpperCase());
      titulos.add(`x${p}y`);
    }
  }
  for (const nicho of Object.values(lib?.nichos ?? {})) {
    for (const [chave, valor] of Object.entries(nicho?.textos ?? {})) {
      if (/\.(t|q|n)$/.test(chave) && typeof valor === 'string') titulos.add(valor);
    }
  }
  return [...titulos];
}

const TEXTOS_ESPECIAIS = [
  'Título & <b>"aspas"</b> \'apóstrofo\' / barra `crase` = igual',
  'ÁÉÍÓÚ àèìòù âêîôû ãõ äëïöü ç ñ ý ÿ Ç Ñ Ÿ',
  'Café com NFD', '  espaços \t\n  demais  ', ' nbsp em　ideográfico﻿', 'emoji 😀 e 中文',
  '{nome} em {cidade} — {segmento} {outra}', '!*\'()~-_. %20 + & = ? #', 'https://exemplo.com/a b?c=d&e=f',
];

/** Lista de chamadas {nome, fn, args} cobrindo as funções puras. */
export function gerarChamadas(lib, docBase) {
  const chamadas = [];
  const add = (fn, ...args) => chamadas.push({ nome: `${fn}#${chamadas.length}`, fn, args });

  for (const s of TEXTOS_ESPECIAIS) {
    for (const fn of ['Texto.semAcentos', 'Texto.normalizar', 'Texto.colapsarEspacos', 'Texto.aparar', 'Texto.escapeHtml', 'Texto.codificarUri']) add(fn, s);
  }
  for (const v of [null, true, false, 0, 12, 'a', ['x', 1, null], { a: 1 }]) add('Texto.escapeHtml', v);
  for (const x of [0, 0.5, 1.5, 2.5, -0.5, -1.5, 254.49999999999997, 127.5]) add('Texto.arred', x);

  const cores = coresDeTeste();
  for (const cor of cores) {
    add('Paleta.gerarPaleta', cor);
    add('Paleta.hexParaRgb', cor);
  }
  for (let i = 0; i < 256; i += 1) add('Paleta.luminancia', `#${hex2(i)}${hex2(i)}${hex2(i)}`);
  const rnd = sorteador(7);
  for (let i = 0; i < 60; i += 1) {
    const h = Math.floor(rnd() * 720) - 180;
    const s = Math.floor(rnd() * 101);
    const l = Math.floor(rnd() * 101);
    add('Paleta.hslParaHex', h + rnd(), s + rnd() / 2, l);
    add('Paleta.contraste', cores[i], cores[cores.length - 1 - i]);
    add('Paleta.rgbParaHsl', [Math.floor(rnd() * 256), Math.floor(rnd() * 256), Math.floor(rnd() * 256)]);
  }
  add('Paleta.cssPaleta', { '--p': '#123456', '--x': 'y' });
  add('Tons.calcularFundos', ['branco', 'claro', 'claro', 'escuro', 'claro', 'cor', 'claro', 'tom-claro', 'claro', 'claro', 'desconhecido']);
  add('Tons.calcularFundos', []);

  for (const titulo of titulosDeTeste(lib)) add('Icones.escolherIcone', titulo, '$lib.icones');
  const utilitarios = Object.keys(lib?.icones?.utilitarios ?? {});
  const ids = (Array.isArray(lib?.icones?.icones) ? lib.icones.icones : []).map((i) => i.id);
  for (const id of [...ids.slice(0, 5), ...utilitarios.slice(0, 5), 'nao-existe']) {
    for (const acabamento of ['classico', 'moderno', 'direto', 'outro']) add('Icones.svg', '$lib', id, acabamento);
  }

  const telefones = ['(11) 98765-4321', '11987654321', '+55 11 98765-4321', '5511987654321', '011 98765-4321', '(11) 3456-7890',
    '1134567890', '(11) 8765-4321', '(20) 98765-4321', '0800 123 4567', '08001234567', '98765-4321', '3456-7890', '123', '', 'abc',
    '(55) 99999-9999', '55 55 99999-9999', '(19) 99876-5432'];
  for (const t of telefones) {
    for (const fn of ['Dados.soDigitos', 'Dados.validarWhatsapp', 'Dados.formatarTelefone', 'Dados.linkTelefone']) add(fn, t);
    add('Dados.linkWhatsapp', t, 'Olá! Vi o site & quero (agendar) uma "avaliação" 100%');
  }
  add('Dados.linkWhatsapp', '(11) 98765-4321', '');
  for (const e of ['contato@clinica.com.br', 'a@b.c', 'sem-arroba', 'a b@c.com', 'x@y', ' x@y.com ', 'nome+tag@dominio.com', 'ação@domínio.com.br', '<a>@b.com']) add('Dados.validarEmail', e);
  for (const c of ['12245000', '12245-000', '1224', '', ' 12.245-000 ']) add('Dados.formatarCep', c);
  const enderecos = [
    { cidade: 'Jundiaí', uf: 'sp', endereco: { cep: '13201000', logradouro: 'Rua X', numero: '123', complemento: 'Sala 4', bairro: 'Centro' } },
    { cidade: 'Jundiaí', uf: '', endereco: { logradouro: 'Rua X', numero: '', complemento: '', bairro: '' } },
    { cidade: '', uf: 'SP', endereco: { logradouro: 'Av. Brasil', numero: 's/n', cep: '123' } },
    { cidade: 'Campinas', uf: 'SP', endereco: { logradouro: '', numero: '10', bairro: 'Cambuí' } },
    { cidade: '', uf: '', endereco: { logradouro: '  Rua   com   espaços ', bairro: 'Vila' } },
    {},
  ];
  for (const d of enderecos) {
    add('Dados.linhasEndereco', d);
    add('Dados.formatarEndereco', d);
  }
  for (const h of ['08:00', '8:00', '08:30', '18:05', '24:00', '25:00', '12:60', '8h', '', ' 09:00 ']) add('Dados.formatarHora', h);
  const horarios = [
    { seg: ['08:00', '18:00'], ter: ['08:00', '18:00'], qua: ['08:00', '18:00'], qui: ['08:00', '18:00'], sex: ['08:00', '18:00'], sab: ['08:00', '12:00'], dom: null },
    { seg: ['08:00', '12:00', '14:00', '18:00'], ter: ['08:00', '12:00', '14:00', '18:00'], qua: null, qui: ['09:30', '17:30'] },
    { sab: null, dom: null },
    { sab: ['08:00', '12:00'], dom: ['08:00', '12:00'] },
    { seg: ['08:00', '18:00'], qua: ['08:00', '18:00'] },
    { seg: [], ter: ['x', 'y'], qua: 'fechado', qui: false },
    {},
    [],
  ];
  for (const h of horarios) add('Dados.formatarHorarios', h);
  const especialidades = [
    { rotuloRegistro: 'CRO', conselho: 'CRO' }, { rotuloRegistro: 'OAB' }, { conselho: 'CRC' }, {}, null,
    { rotuloRegistro: 'CREA', rotuloResponsavel: 'Engenheiro responsável' },
  ];
  const registros = [
    { uf: 'SP', registro: { numero: '12345', uf: '', responsavel: 'Dra. Ana Lima' } },
    { uf: 'sp', registro: { numero: '123.456', uf: 'rj', responsavel: '' } },
    { uf: '', registro: { numero: '987', uf: '', responsavel: 'Dr. Zé' } },
    { uf: 'SP', registro: { numero: '', responsavel: 'Ninguém' } },
  ];
  for (const e of especialidades) for (const r of registros) add('Dados.formatarRegistro', r, e);
  for (const n of ['Clínica Sorriso', 'ética', '  9 vidas', '¿Quién?', '', '—', 'ßeta', 'Ångström']) add('Dados.inicial', n);
  for (const n of ['Dra. Beatriz Ramos', 'dr Felipe', 'Prof.ª Ana', 'Profª Ana', 'Dr. Dra. Lu', 'Dra.', 'Dr.', '  Sr. Édson', 'Drummond', 'Pedro', 'Ma. Clara', '']) add('Dados.inicialPessoa', n);
  const redes = ['@clinica.teste', 'clinica_teste', 'instagram.com/clinica', 'https://www.instagram.com/x/', 'http://facebook.com/x',
    'www.site.com.br', 'linkedin.com/in/fulano', 'javascript:alert(1)', 'https://x.com/"><script>', 'perfil com espaço', '', 'g.page/clinica'];
  for (const rede of ['instagram', 'facebook', 'linkedin', 'youtube', 'google']) for (const v of redes) add('Dados.urlRede', rede, v);

  // Textos e documento
  const contexto = { nome: 'Clínica Sorriso', cidade: 'Jundiaí', segmento: 'Dentista' };
  for (const s of TEXTOS_ESPECIAIS) add('Textos.substituirVariaveis', s, contexto);
  add('Textos.substituirVariaveis', '{nome}{nome}{cidade}', { nome: '{cidade}', cidade: '$1 \\0' });
  const retok = ['A Clínica Sorriso atende em Jundiaí.', 'clínica sorriso (minúsculas)', 'Jundiaí Jundiaí Clínica Sorriso Jundiaí',
    'Nada aqui', 'Clínica Sorriso Jundiaí'];
  for (const s of retok) {
    add('Textos.retokenizar', s, { nome: 'Clínica Sorriso', cidade: 'Jundiaí' });
    add('Textos.retokenizar', s, { nome: 'Cl', cidade: 'Ju' });
    add('Textos.retokenizar', s, { nome: 'Clínica Sorriso Jundiaí', cidade: 'Jundiaí' });
  }
  for (const nome of ['Dra. Helena', 'dr. Rafael', 'DRA ANA', 'Eng. André', 'Juliana Prado', 'Roberto', 'Lua', 'Sr.', '', 'Ângela', 'Sra.Maria']) add('Fotos.generoDoNome', nome);
  if (docBase) {
    add('Fotos.imagensDeExemplo', docBase, '$lib');
    add('Textos.contextoVariaveis', docBase, '$lib');
    add('Textos.textosPadraoEfetivos', docBase, '$lib');
    add('Documento.registroCampos', '$lib');
    add('Documento.registroListas', '$lib');
    add('Documento.especialidade', docBase, '$lib');
    add('Preparo.montarDados', docBase, '$lib', { modo: 'publicar', ano: 2026 }, null, {});
    for (const chave of Object.keys(documento.registroCampos(lib)).filter((k) => !k.includes('*')).slice(0, 40)) {
      add('Textos.textoEfetivo', docBase, '$lib', chave);
      add('Textos.textoPadrao', docBase, '$lib', chave);
    }
    for (const lista of Object.keys(documento.registroListas(lib))) {
      add('Textos.itensLista', docBase, '$lib', lista);
      add('Textos.adicionarItem', docBase, '$lib', lista, null, 'nab12');
      add('Textos.adicionarItem', docBase, '$lib', lista, '1', 'nab12');
      add('Textos.removerItem', docBase, '$lib', lista, '2');
      add('Textos.moverItem', docBase, '$lib', lista, '1', 1);
      add('Textos.moverItem', docBase, '$lib', lista, '1', -1);
      add('Textos.textoEfetivo', docBase, '$lib', `${lista}.nzzzz.t`);
      for (let p = 0; p < 6; p += 1) add('Icones.iconeDoItem', docBase, '$lib', lista, String(p + 1), p);
    }
    add('Textos.aplicarEdicaoTexto', docBase, '$lib', 'hero.titulo', '   ');
    add('Textos.aplicarEdicaoTexto', docBase, '$lib', 'hero.titulo', 'Novo título da Clínica Sorriso Vivo em Jundiaí');
    add('Textos.aplicarEdicaoTexto', docBase, '$lib', 'hero.eyebrow', 'Dentista em Jundiaí');
    add('Documento.migrar', docBase, '$lib');
    add('Documento.completarDados', docBase.dados);
  }
  for (const nicho of Object.keys(lib?.nichos ?? {})) {
    for (const modelo of Object.keys(lib?.modelos ?? {})) {
      add('Documento.receitaModelo', '$lib', modelo, nicho);
      add('Documento.criarDocumento', { nicho, modelo, dados: { nome: 'X & Y' } }, '$lib');
      if (documento.modeloDoNicho(lib, modelo, nicho)) {
        const doc = documento.criarDocumento({ nicho, modelo, dados: {} }, lib);
        add('Fotos.imagensDeExemplo', doc, '$lib');
        add('Fotos.imagensDeExemplo', { ...doc, imagens: { 'hero.img': 'm_00000001' }, listas: { equipe: ['2', 'n1', '1'] } }, '$lib');
      }
      add('Documento.criarDocumento', { nicho, modelo, especialidade: 'nao-existe', estilo: { cor: '#ABC', fonte: 'nao', acabamento: 'direto', whatsappFlutuante: false } }, '$lib');
      if (docBase) add('Documento.aplicarModelo', docBase, '$lib', modelo);
    }
  }
  add('Documento.criarDocumento', { nicho: 'nao-existe', modelo: 'x' }, '$lib');
  add('Documento.migrar', documentoV1(), '$lib');
  add('Documento.migrar', documentoV1(), null);
  add('Documento.migrar', { versaoEsquema: 2, textos: [], listas: { serv: ['1', 2, 'x y', '1', 3.5] }, secoes: [{ tipo: 'hero' }, 'x', { tipo: 'hero', opcao: 'a' }] }, '$lib');
  add('Documento.migrar', null, null);
  add('Documento.completarDados', { nome: 1, horarios: { seg: ['08:00', 1], ter: 'x', dom: false, xyz: [] }, redes: { instagram: 5 } });

  // Motor Mustache: casos-limite em que mustache.js e mustache.php divergiam
  const view = { l: ['a', 'b'], b: true, v: '<v> & \'"/`=', vazio: [], o: {}, n: 3, z: 0, s: 'texto', zt: '', x: { y: { z: 'fundo' } }, item: { d: 'campo', c: 'cargo' }, d: { link: 'raiz' } };
  const parciais = { p: '<p>\n  {{v}}\n</p>\n', q: '<q>\n  {{> p}}\n</q>\n', r: 'a\nb' };
  const templates = [
    '<ul>\r\n  {{#l}}\r\n  <li>{{.}}</li>\r\n  {{/l}}\r\n</ul>\r\n', 'a\n  {{#b}}  \nX\n  {{/b}}\t\nc\n', '<div>\n    {{> p}}\n</div>\n',
    '<div>x {{> p}} y</div>', '<div>\n  {{> q}}\n</div>', 'a\n  {{! nada }}\nb {{! x }} c\n', '{{=<% %>=}}\n<% v %>\n<%={{ }}=%>{{v}}',
    '{{^vazio}}sim{{/vazio}}{{^l}}nao{{/l}}', '  {{#b}}\n  x\n  {{/b}}', '{{#o}}obj{{/o}}{{^o}}vazio{{/o}}', '{{n}} {{#n}}[{{.}}]{{/n}} {{z}}{{#z}}Z{{/z}}',
    '{{l.0}} {{l.1}} {{s.length}} {{l.length}}', '<div>\n  {{> p}} x\n</div>', '<div>\n  {{> p}}   \n</div>', '<div>\r\n  {{> p}}\r\n</div>\r\n',
    '{{#b}}\n    {{> p}}\n{{/b}}\n', '\t{{> p}}\n', '{{> p}}\nfim', '  {{> r}}\nx', '{{#l}}{{#b}}[{{.}}]{{/b}}{{/l}}', '{{#s}}<{{.}}>{{/s}}',
    '{{#zt}}Z{{/zt}}{{^zt}}nZ{{/zt}}', '{{x.y.z}} {{#x}}{{y.z}}{{/x}} {{x.nao.z}}', '{{#item}}[{{d.link}}][{{d}}][{{c}}]{{/item}}', '{{{v}}} {{&v}} {{v}}',
    '{{#l}}{{#x}}{{.}}{{/x}}{{/l}}', '{{b}} {{z}} {{zt}} {{nada}} {{toString}} {{constructor}} {{l.constructor}}',
  ];
  for (const t of templates) add('Preparo.renderizarTemplate', t, view, parciais);

  // Imagem e logo
  const midia = midiaDeTeste();
  for (const midiaId of [...Object.keys(midia), 'm_ffffffff', null]) {
    for (const lcp of [false, true]) {
      add('Html.imagem', { chave: 'hero.img', midiaId, rotulo: 'Foto & "rótulo"', alt: 'Alt <padrão>', sizes: '(max-width: 760px) 100vw, 50vw', lcp }, { modo: 'publicar', urlMidia: 'img/{id}-{w}.{ext}', midia });
    }
    add('Html.imagem', { chave: 'serv.1.img', midiaId, rotulo: 'Foto do serviço', alt: '', sizes: '', lcp: false }, { modo: 'editor', midia });
    add('Html.logo', { midiaId, nome: 'Clínica & "Sorriso"', inicial: 'C' }, { modo: 'publicar', midia });
    add('Html.logo', { midiaId, nome: 'Clínica', inicial: 'C' }, { modo: 'editor', urlMidia: '/api/media/{id}/{w}', midia });
  }
  return chamadas;
}

/** Mídias fictícias: fotos com variantes, foto pequena, logo raster e SVG, prévia local. */
export function midiaDeTeste() {
  return {
    m_00000001: { largura: 2400, altura: 1600, variantes: [480, 960, 1600], alt: '', tipo: 'foto', formato: 'webp' },
    m_00000002: { largura: 1200, altura: 900, variantes: [960, 480, 1200], alt: 'Recepção "nova" & <ampla>', tipo: 'foto', formato: 'webp' },
    m_00000003: { largura: 400, altura: 300, variantes: [400], alt: '   ', tipo: 'foto', formato: 'webp' },
    m_00000004: { largura: 1001, altura: 667, variantes: [480, 960, 1001], alt: '', tipo: 'foto', formato: 'webp' },
    m_00000005: { largura: 800, altura: 800, variantes: [], alt: '', tipo: 'foto', formato: 'svg' },
    m_0000000a: { largura: 800, altura: 200, variantes: [160, 320, 640], alt: '', tipo: 'logo', formato: 'webp' },
    m_0000000b: { largura: 120, altura: 40, variantes: [], alt: '', tipo: 'logo', formato: 'svg' },
    m_0000000c: { largura: 300, altura: 91, variantes: [160, 300], alt: '', tipo: 'logo', formato: 'webp' },
    m_0000000d: { largura: 1600, altura: 1200, variantes: [480, 960, 1600], alt: '', tipo: 'foto', formato: 'webp', local: 'blob:http://x/1' },
  };
}

/** Documento no esquema 1 do PDF (opção por índice, listas base 0). */
export function documentoV1() {
  return {
    versaoEsquema: 1,
    nicho: 'clinicas',
    modelo: 'moderno',
    estilo: { cor: '#C23B6E', fonte: 'editorial', acabamento: 'moderno', whatsappFlutuante: true },
    dados: { nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', whatsapp: '(11) 98765-4321', logo: 'm_8f3a2c' },
    secoes: [{ tipo: 'header', opcao: 0 }, { tipo: 'hero', opcao: 0 }, { tipo: 'servicos', opcao: 3 }, { tipo: 'faq', opcao: 9 }, { tipo: 'rodape', opcao: 0 }, { tipo: 'xyz', opcao: 0 }],
    textos: { 'hero.titulo': 'Cuidado com o seu sorriso…', 'serv.0.t': 'Ortodontia', 'serv.1.d': 'Desc', 'sobre.l.0': 'Item', 'clientes.2': 'ACME', 'clientes.titulo': 'Clientes', 'contato.end': 'Rua X' },
    imagens: { 'hero.img': 'm_91c0de', 'serv.0.img': 'm_0a1b2c', 'equipe.2.f': 'm_ffffff' },
    icones: { 'serv.1': 'h-odontology', 'dif.0': 'heart' },
    rastreamento: { gtm: 'GTM-XXXXXXX' },
    seo: { titulo: null, descricao: null },
  };
}
