// Conteúdo da biblioteca: nichos, modelos, ícones e fontes (contrato §2.2, §3.5–§3.8; PDF cap. 8 e 11).
// Rodar: node --test tests/js/conteudo.test.mjs   (depois de: node ferramentas/construir.mjs)

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const BIB = path.join(RAIZ, 'biblioteca');
const lerJson = (rel) => JSON.parse(fs.readFileSync(path.join(BIB, rel), 'utf8'));
const existe = (rel) => fs.existsSync(path.join(RAIZ, rel));

// ------------------------------------------------------------------ normalização e algoritmo de ícones
// Usa o módulo compartilhado (o mesmo do editor) quando existe; senão, cópia local do PDF §8.2.

const ACENTOS = 'áàâãäåéèêëíìîïóòôõöúùûüçñýÿ';
const SEM_ACENTOS = 'aaaaaaeeeeiiiiooooouuuucnyy';
function normalizarLocal(s) {
  return String(s).toLowerCase().replace(/[áàâãäåéèêëíìîïóòôõöúùûüçñýÿ]/g, (c) => SEM_ACENTOS[ACENTOS.indexOf(c)])
    .replace(/\s+/g, ' ').trim();
}
function escolherIconeLocal(titulo, icones) {
  const texto = normalizarLocal(titulo);
  if (texto === '') return null;
  const alnum = (c) => c !== undefined && /^[a-z0-9]$/.test(c);
  const inteira = (p) => {
    for (let i = texto.indexOf(p); i !== -1; i = texto.indexOf(p, i + 1)) {
      if (!alnum(texto[i - 1]) && !alnum(texto[i + p.length])) return true;
    }
    return false;
  };
  let melhor = null;
  let pontos = 0;
  for (const icone of Array.isArray(icones) ? icones : icones.icones) {
    for (const bruta of icone.palavras) {
      const p = normalizarLocal(bruta);
      if (p.length === 0 || p.length <= pontos) continue;
      if (p.length <= 3 ? inteira(p) : texto.includes(p)) {
        melhor = icone.id;
        pontos = p.length;
      }
    }
  }
  return melhor;
}

async function importarSeExistir(rel) {
  return existe(rel) ? import(pathToFileURL(path.join(RAIZ, rel)).href) : null;
}
const modTexto = await importarSeExistir('public_html/editor/js/compartilhado/texto.mjs');
const modIcones = await importarSeExistir('public_html/editor/js/compartilhado/icones.mjs');
const normalizar = modTexto?.normalizar ?? normalizarLocal;
const escolherIcone = modIcones?.escolherIcone ?? escolherIconeLocal;
const { problemasSvg, LIMITE_BYTES } = await import(pathToFileURL(path.join(RAIZ, 'ferramentas/construir-icones.mjs')).href);

// ------------------------------------------------------------------ dados

const NICHOS = ['advocacia', 'financas', 'empresas', 'clinicas'];
const MODELOS = ['classico', 'moderno', 'direto'];
const comum = lerJson('nichos/comum.json');
const nichos = Object.fromEntries(NICHOS.map((id) => [id, lerJson(`nichos/${id}.json`)]));
const modelos = Object.fromEntries(MODELOS.map((id) => [id, lerJson(`modelos/${id}.json`)]));
const iconesJson = lerJson('icones/icones.json');
const fontes = lerJson('fontes/fontes.json');
const idsIcones = new Set(iconesJson.icones.map((i) => i.id));
const nomeIcone = (id) => iconesJson.icones.find((i) => i.id === id)?.nome ?? null;

// ------------------------------------------------------------------ tabela canônica do contrato §2.2

const ESCALARES = {
  hero: ['eyebrow', 'titulo', 'texto', 'cta', 'cta2', 'img', 'img2', 'selo'],
  form: ['titulo', 'nota', 'botao'],
  dif: ['eyebrow', 'titulo', 'img'],
  cli: ['titulo'],
  sobre: ['eyebrow', 'titulo', 'texto', 'img', 'img2', 'cta'],
  serv: ['eyebrow', 'titulo', 'texto', 'link'],
  num: [],
  passos: ['eyebrow', 'titulo', 'texto', 'img'],
  equipe: ['eyebrow', 'titulo', 'texto'],
  dep: ['eyebrow', 'titulo', 'img'],
  aval: ['nota', 'txt'],
  faq: ['eyebrow', 'titulo', 'texto', 'img', 'ajuda'],
  cta: ['titulo', 'texto', 'botao', 'img'],
  contato: ['eyebrow', 'titulo', 'texto', 'botao', 'mapa'],
  rodape: ['sobre', 'texto'],
};
const LISTAS = {
  dif: { campos: ['t', 'd', 'ic'], repete: [3, 3] },
  cli: { campos: ['t'], repete: [3, 8] },
  sobrel: { campos: ['t'], repete: [4, 4] },
  serv: { campos: ['t', 'd', 'img', 'ic'], repete: [3, 8] },
  num: { campos: ['v', 'l'], repete: [3, 3] },
  passos: { campos: ['t', 'd'], repete: [3, 3] },
  equipe: { campos: ['n', 'c', 'f'], repete: [3, 3] },
  dep: { campos: ['t', 'n', 'c'], repete: [3, 3] },
  faq: { campos: ['q', 'a'], repete: [3, 10] },
};
const NAO_TEXTO = new Set(['img', 'img2', 'f', 'ic']);
const camposTexto = (lista) => LISTAS[lista].campos.filter((c) => !NAO_TEXTO.has(c));
const CHAVES_ESCALARES = Object.entries(ESCALARES).flatMap(([g, cs]) => cs.filter((c) => !NAO_TEXTO.has(c)).map((c) => `${g}.${c}`));

const MAX_ESCALAR = {
  'hero.titulo': 90, 'hero.texto': 200, 'sobre.texto': 420,
  'hero.cta': 28, 'hero.cta2': 28, 'sobre.cta': 28, 'cta.botao': 28, 'form.botao': 28, 'contato.botao': 28,
  'serv.link': 24, 'contato.mapa': 24, 'hero.selo': 40, 'form.titulo': 60, 'form.nota': 120, 'cli.titulo': 60,
  'aval.nota': 4, 'aval.txt': 60, 'faq.ajuda': 120, 'cta.texto': 180, 'contato.texto': 200,
  'rodape.sobre': 200, 'rodape.texto': 120,
};
const MAX_ITEM = {
  'cli.t': 30, 'sobrel.t': 60, 'dif.t': 40, 'dif.d': 140, 'serv.t': 40, 'serv.d': 160, 'num.v': 8, 'num.l': 40,
  'passos.t': 40, 'passos.d': 160, 'equipe.n': 40, 'equipe.c': 50, 'dep.t': 240, 'dep.n': 40, 'dep.c': 50,
  'faq.q': 100, 'faq.a': 320,
};
function maxDe(chave) {
  const p = chave.split('.');
  if (p.length === 3) return MAX_ITEM[`${p[0]}.${p[2]}`];
  if (MAX_ESCALAR[chave] !== undefined) return MAX_ESCALAR[chave];
  return { eyebrow: 40, titulo: 80, texto: 220 }[p[1]];
}

// Catálogo de seções (§3.2).
const CATALOGO = {
  header: ['simples', 'barra'],
  hero: ['cards-flutuantes', 'fundo-cards', 'formulario', 'centralizado'],
  diferenciais: ['faixa-icones', 'foto-selo'],
  clientes: ['faixa'],
  sobre: ['duas-fotos', 'foto-numeros'],
  servicos: ['cards', 'lista', 'cards-foto', 'blocos'],
  numeros: ['faixa-clara', 'faixa-cor'],
  passos: ['linha-tempo', 'lista-foto'],
  equipe: ['fotos-nome', 'compacta'],
  depoimentos: ['cards-nota', 'destaque-foto'],
  faq: ['centralizada', 'foto-ajuda'],
  cta: ['faixa-cor', 'caixa-clara', 'foto-fundo'],
  contato: ['formulario', 'mapa'],
  rodape: ['completo', 'simples'],
};

const listaPadrao = (nicho, lista) => nicho?.listas?.[lista] ?? comum.listas[lista];
const textoPadrao = (nicho, chave) => nicho?.textos?.[chave] ?? comum.textos[chave];
const comprimento = (s) => [...s].length;

/** Receita do modelo filtrada por nicho (so/exceto), como no §3.5. */
function receita(modelo, nichoId) {
  return modelo.secoes.filter(([, , f]) => !(f?.so && !f.so.includes(nichoId)) && !(f?.exceto && f.exceto.includes(nichoId)))
    .map(([tipo, opcao]) => ({ tipo, opcao }));
}

/** Termo proibido presente como frase inteira (normalizada) no texto? */
function contemTermo(texto, termo) {
  const t = ` ${normalizar(texto).replace(/[^a-z0-9%]+/g, ' ')} `;
  const p = normalizar(termo).replace(/[^a-z0-9%]+/g, ' ').trim();
  return p !== '' && t.includes(` ${p} `);
}

// ================================================================== JSON e estrutura

describe('arquivos JSON', () => {
  test('todos os arquivos de conteúdo são JSON válido', () => {
    const arquivos = [
      'nichos/comum.json', ...NICHOS.map((n) => `nichos/${n}.json`), ...MODELOS.map((m) => `modelos/${m}.json`),
      'icones/icones.json', 'fontes/fontes.json',
    ];
    for (const rel of arquivos) assert.doesNotThrow(() => lerJson(rel), rel);
  });
});

// ================================================================== modelos

describe('modelos (§3.5)', () => {
  test('campos obrigatórios e acabamento', () => {
    for (const [id, m] of Object.entries(modelos)) {
      assert.equal(m.id, id);
      assert.ok(typeof m.nome === 'string' && m.nome.length > 0, `${id}.nome`);
      for (const campo of ['descricao', 'personalidade']) assert.ok(typeof m[campo] === 'string' && m[campo].length > 20, `${id}.${campo}`);
      assert.equal(m.acabamento, id, `${id}: acabamento`);
    }
  });

  test('só tipos e opções do catálogo §3.2; filtros só com nichos conhecidos', () => {
    for (const [id, m] of Object.entries(modelos)) {
      for (const entrada of m.secoes) {
        assert.ok(Array.isArray(entrada) && entrada.length >= 2 && entrada.length <= 3, `${id}: ${JSON.stringify(entrada)}`);
        const [tipo, opcao, filtro] = entrada;
        assert.ok(CATALOGO[tipo]?.includes(opcao), `${id}: ${tipo}/${opcao} fora do catálogo`);
        if (filtro !== undefined) {
          assert.deepEqual(Object.keys(filtro).filter((k) => k !== 'so' && k !== 'exceto'), [], `${id}: filtro inválido`);
          for (const n of [...(filtro.so ?? []), ...(filtro.exceto ?? [])]) assert.ok(NICHOS.includes(n), `${id}: nicho "${n}"`);
        }
      }
    }
  });

  test('receitas do PDF §4.3 (ids estáveis do §3.2)', () => {
    const r = (m, n) => receita(modelos[m], n).map((s) => `${s.tipo}/${s.opcao}`);
    assert.deepEqual(r('classico', 'empresas'), ['header/barra', 'hero/fundo-cards', 'clientes/faixa', 'sobre/duas-fotos',
      'servicos/cards', 'numeros/faixa-cor', 'equipe/fotos-nome', 'depoimentos/cards-nota', 'faq/foto-ajuda',
      'cta/foto-fundo', 'contato/formulario', 'rodape/completo']);
    assert.deepEqual(r('moderno', 'clinicas'), ['header/simples', 'hero/cards-flutuantes', 'diferenciais/faixa-icones',
      'servicos/blocos', 'sobre/foto-numeros', 'passos/linha-tempo', 'depoimentos/destaque-foto', 'faq/centralizada',
      'cta/caixa-clara', 'contato/mapa', 'rodape/completo']);
    assert.deepEqual(r('direto', 'financas'), ['header/simples', 'hero/formulario', 'servicos/cards', 'passos/linha-tempo',
      'depoimentos/cards-nota', 'faq/centralizada', 'cta/faixa-cor', 'contato/mapa', 'rodape/simples']);
    // Contagens das telas do protótipo (advocacia): Clássico 10, Moderno 11, Direto 8 seções.
    assert.equal(r('classico', 'advocacia').length, 10);
    assert.equal(r('moderno', 'advocacia').length, 11);
    assert.equal(r('direto', 'advocacia').length, 8);
  });

  test('para todo nicho: cabeçalho primeiro, rodapé último, sem tipo repetido; advocacia sem depoimentos', () => {
    for (const m of Object.values(modelos)) {
      for (const n of NICHOS) {
        const tipos = receita(m, n).map((s) => s.tipo);
        assert.equal(tipos[0], 'header');
        assert.equal(tipos.at(-1), 'rodape');
        assert.equal(new Set(tipos).size, tipos.length, `${m.id}/${n}: tipo repetido`);
        if (n === 'advocacia') assert.ok(!tipos.includes('depoimentos'), `${m.id}: depoimentos na advocacia`);
        if (n !== 'empresas') assert.ok(!tipos.includes('clientes'), `${m.id}/${n}: clientes só em empresas`);
      }
    }
  });

  test('integração: as opções existem nos manifestos já presentes na biblioteca', () => {
    for (const m of Object.values(modelos)) {
      for (const [tipo, opcao] of m.secoes) {
        const rel = `biblioteca/secoes/${tipo}/manifest.json`;
        if (!existe(rel)) continue;
        const manifest = JSON.parse(fs.readFileSync(path.join(RAIZ, rel), 'utf8'));
        assert.ok(manifest.opcoes.some((o) => o.id === opcao), `${m.id}: ${tipo}/${opcao} não está no manifesto`);
      }
    }
  });
});

// ================================================================== nichos

describe('nichos (§3.6) — estrutura', () => {
  const ESPERADO = {
    advocacia: [['advocacia', 'Advocacia', 'LegalService', 'OAB', true]],
    financas: [['contabilidade', 'Contabilidade', 'AccountingService', 'CRC', true],
      ['consultoria-financeira', 'Consultoria financeira', 'FinancialService', '', false],
      ['credito', 'Correspondente de crédito', 'FinancialService', '', false]],
    empresas: [['engenharia', 'Engenharia e serviços técnicos', 'ProfessionalService', 'CREA', true],
      ['industria', 'Indústria', 'LocalBusiness', '', false],
      ['servicos-empresariais', 'Serviços para empresas', 'ProfessionalService', '', false]],
    clinicas: [['odontologia', 'Odontologia', 'Dentist', 'CRO', true], ['medicina', 'Clínica médica', 'MedicalClinic', 'CRM', true],
      ['estetica', 'Estética', 'HealthAndBeautyBusiness', '', false], ['fisioterapia', 'Fisioterapia', 'Physiotherapy', 'CREFITO', true],
      ['psicologia', 'Psicologia', 'MedicalBusiness', 'CRP', true]],
  };
  const CATEGORIA = { advocacia: 'juridico', financas: 'financas', empresas: 'empresas', clinicas: 'saude' };

  test('id, nome, descrição e ordem únicos', () => {
    const ordens = new Set();
    for (const [id, n] of Object.entries(nichos)) {
      assert.equal(n.id, id);
      assert.ok(n.nome && n.descricao, id);
      assert.ok(Number.isInteger(n.ordem) && !ordens.has(n.ordem), `${id}: ordem`);
      ordens.add(n.ordem);
    }
  });

  test('especialidades [M7] com schema.org, conselho e categoria de ícones', () => {
    for (const [id, n] of Object.entries(nichos)) {
      const obtido = n.especialidades.map((e) => [e.id, e.nome, e.schemaOrg, e.conselho, e.registroObrigatorio]);
      assert.deepEqual(obtido, ESPERADO[id], id);
      for (const e of n.especialidades) {
        assert.ok(typeof e.segmento === 'string' && e.segmento.length > 0, `${id}/${e.id}: segmento`);
        assert.equal(typeof e.rotuloRegistro, 'string');
        if (e.registroObrigatorio) assert.equal(e.rotuloRegistro, e.conselho, `${id}/${e.id}: rótulo do registro`);
        assert.equal(e.iconesCategoria, CATEGORIA[id], `${id}/${e.id}: iconesCategoria`);
      }
    }
    assert.equal(nichos.clinicas.especialidades[0].segmento, 'Dentista');
  });

  test('exemplo (PDF) e exemplo.dados no formato do §2', () => {
    assert.deepEqual([nichos.clinicas.exemplo.nome, nichos.clinicas.exemplo.cidade, nichos.clinicas.exemplo.whatsapp],
      ['Clínica Sorriso Vivo', 'Jundiaí', '(11) 98765-4321']);
    assert.deepEqual([nichos.advocacia.exemplo.nome, nichos.advocacia.exemplo.cidade, nichos.advocacia.exemplo.whatsapp],
      ['Moraes & Lima Advogados', 'Campinas', '(19) 99876-5432']);
    for (const [id, n] of Object.entries(nichos)) {
      const ex = n.exemplo;
      assert.ok(ex.nome.length <= 60 && ex.cidade.length <= 40 && /^[A-Z]{2}$/.test(ex.uf), id);
      const digitos = ex.whatsapp.replace(/\D/g, '');
      assert.ok(digitos.length === 11 && digitos[2] === '9' && Number(digitos.slice(0, 2)) >= 11, `${id}: WhatsApp`);
      const d = ex.dados;
      assert.ok(/^\(\d{2}\) \d{4,5}-\d{4}$/.test(d.telefone), `${id}: telefone`);
      assert.ok(/^[a-z0-9.-]+@[a-z0-9.-]+\.[a-z]{2,}$/.test(d.email), `${id}: e-mail`);
      assert.deepEqual(Object.keys(d.endereco), ['cep', 'logradouro', 'numero', 'complemento', 'bairro'], `${id}: endereço`);
      assert.ok(/^\d{5}-\d{3}$/.test(d.endereco.cep), `${id}: CEP`);
      assert.deepEqual(Object.keys(d.horarios), ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'], `${id}: horários`);
      for (const v of Object.values(d.horarios)) {
        assert.ok(v === null || (Array.isArray(v) && v.length === 2 && v.every((h) => /^([01]\d|2[0-3]):[0-5]\d$/.test(h)) && v[0] < v[1]), `${id}: ${v}`);
      }
    }
  });

  test('padrões por modelo: cor #rrggbb minúscula e fonte existente (PDF §4.3/§11.1)', () => {
    for (const [id, n] of Object.entries(nichos)) {
      assert.deepEqual(Object.keys(n.padroesPorModelo).sort(), [...MODELOS].sort(), id);
      for (const [m, p] of Object.entries(n.padroesPorModelo)) {
        assert.match(p.cor, /^#[0-9a-f]{6}$/, `${id}/${m}`);
        assert.ok(p.fonte in fontes.pares, `${id}/${m}: fonte ${p.fonte}`);
      }
    }
    assert.deepEqual(nichos.clinicas.padroesPorModelo, {
      classico: { cor: '#2a7f86', fonte: 'amigavel' }, moderno: { cor: '#c23b6e', fonte: 'editorial' }, direto: { cor: '#2e6fd1', fonte: 'moderna' },
    });
    assert.equal(nichos.advocacia.padroesPorModelo.classico.fonte, 'classica');
  });

  test('menu (rótulos por tipo de seção), mensagem do WhatsApp e conformidade', () => {
    assert.equal(nichos.clinicas.menu.servicos, 'Tratamentos');
    assert.equal(nichos.advocacia.menu.servicos, 'Áreas de atuação');
    for (const [id, n] of Object.entries(nichos)) {
      for (const [tipo, rotulo] of Object.entries(n.menu)) {
        assert.ok(tipo in CATALOGO && tipo !== 'header' && tipo !== 'rodape', `${id}: menu.${tipo}`);
        assert.ok(rotulo.length > 0 && rotulo.length <= 24, `${id}: rótulo "${rotulo}"`);
      }
      assert.ok(n.mensagemWhatsapp.startsWith('Olá') && n.mensagemWhatsapp.length <= 120, id);
      const c = n.conformidade;
      assert.ok(c.conselho && c.aviso.length > 40 && Array.isArray(c.termosProibidos) && c.termosProibidos.length >= 8, id);
      assert.equal(new Set(c.termosProibidos.map(normalizar)).size, c.termosProibidos.length, `${id}: termo repetido`);
    }
  });
});

describe('nichos — textos (§2.2)', () => {
  test('toda chave pertence à tabela do §2.2 (escalares e campos de lista) e cada item está na lista padrão', () => {
    const verificar = (dono, textos, nicho) => {
      for (const chave of Object.keys(textos)) {
        const p = chave.split('.');
        if (p.length === 2) {
          assert.ok(CHAVES_ESCALARES.includes(chave), `${dono}: chave escalar fora da tabela: ${chave}`);
        } else {
          assert.equal(p.length, 3, `${dono}: chave malformada ${chave}`);
          const [lista, id, campo] = p;
          assert.ok(lista in LISTAS, `${dono}: lista desconhecida em ${chave}`);
          assert.ok(camposTexto(lista).includes(campo), `${dono}: campo "${campo}" não é texto da lista ${lista}`);
          assert.ok(listaPadrao(nicho, lista).includes(id), `${dono}: item órfão ${chave} (fora da lista padrão)`);
        }
        assert.equal(typeof textos[chave], 'string', `${dono}: ${chave}`);
        assert.ok(textos[chave].trim().length > 0, `${dono}: ${chave} vazio`);
        assert.equal(textos[chave], textos[chave].trim(), `${dono}: ${chave} com espaço nas pontas`);
      }
    };
    verificar('comum', comum.textos, null);
    for (const [id, n] of Object.entries(nichos)) verificar(id, n.textos, n);
    for (const [lista, item] of Object.entries(comum.novoItem)) {
      assert.ok(lista in LISTAS, `novoItem.${lista}`);
      assert.deepEqual(Object.keys(item).sort(), camposTexto(lista).sort(), `novoItem.${lista}: campos`);
    }
    assert.deepEqual(Object.keys(comum.novoItem).sort(), Object.keys(LISTAS).sort(), 'novoItem de cada lista');
  });

  test('listas padrão: ids "1", "2", … dentro de repete (mín–máx)', () => {
    assert.deepEqual(Object.keys(comum.listas).sort(), Object.keys(LISTAS).sort());
    for (const dono of [null, ...Object.values(nichos)]) {
      for (const lista of Object.keys(LISTAS)) {
        const ids = listaPadrao(dono, lista);
        const [min, max] = LISTAS[lista].repete;
        assert.ok(ids.length >= min && ids.length <= max, `${dono?.id ?? 'comum'}.${lista}: ${ids.length} itens`);
        assert.deepEqual(ids, ids.map((_, i) => String(i + 1)), `${dono?.id ?? 'comum'}.${lista}: ids`);
      }
    }
  });

  test('cada nicho (+ comum) cobre TODAS as chaves escalares e os itens das listas padrão', () => {
    for (const [id, n] of Object.entries(nichos)) {
      for (const chave of CHAVES_ESCALARES) assert.ok(textoPadrao(n, chave), `${id}: falta ${chave}`);
      for (const lista of Object.keys(LISTAS)) {
        for (const item of listaPadrao(n, lista)) {
          for (const campo of camposTexto(lista)) assert.ok(textoPadrao(n, `${lista}.${item}.${campo}`), `${id}: falta ${lista}.${item}.${campo}`);
        }
      }
      // ~90 textos próprios por nicho (PDF §11.1)
      assert.ok(Object.keys(n.textos).length >= 85, `${id}: só ${Object.keys(n.textos).length} textos`);
    }
  });

  test('comprimentos ≤ max (texto cru e com as variáveis do exemplo de cada especialidade)', () => {
    const conferir = (dono, chave, texto, contexto) => {
      const max = maxDe(chave);
      assert.ok(max !== undefined, `sem max para ${chave}`);
      assert.ok(comprimento(texto) <= max, `${dono}: ${chave} tem ${comprimento(texto)} > ${max}: "${texto}"`);
      if (contexto) {
        const efetivo = texto.replace(/\{(nome|cidade|segmento)\}/g, (_, v) => contexto[v]);
        assert.ok(comprimento(efetivo) <= max, `${dono}: ${chave} com variáveis tem ${comprimento(efetivo)} > ${max}: "${efetivo}"`);
      }
    };
    // Mesmo contexto longo que o lint da biblioteca usa para o comum.
    const longo = { nome: 'Empresa Exemplo Ltda', cidade: 'São José dos Campos', segmento: 'Segmento' };
    for (const [chave, texto] of Object.entries(comum.textos)) conferir('comum', chave, texto, longo);
    for (const [lista, item] of Object.entries(comum.novoItem)) {
      for (const [campo, texto] of Object.entries(item)) conferir('novoItem', `${lista}.n.${campo}`, texto);
    }
    for (const [id, n] of Object.entries(nichos)) {
      const chaves = new Set([...CHAVES_ESCALARES, ...Object.keys(n.textos)]);
      for (const lista of Object.keys(LISTAS)) {
        for (const item of listaPadrao(n, lista)) for (const campo of camposTexto(lista)) chaves.add(`${lista}.${item}.${campo}`);
      }
      for (const esp of n.especialidades) {
        const ctx = { nome: n.exemplo.nome, cidade: n.exemplo.cidade, segmento: esp.segmento };
        for (const chave of chaves) conferir(`${id}/${esp.id}`, chave, textoPadrao(n, chave), ctx);
      }
    }
  });

  test('só as variáveis {nome}, {cidade} e {segmento}; sem chaves soltas', () => {
    const todos = [comum.textos, ...Object.values(nichos).map((n) => n.textos), ...Object.values(comum.novoItem)];
    for (const textos of todos) {
      for (const [chave, texto] of Object.entries(textos)) {
        const resto = texto.replace(/\{(nome|cidade|segmento)\}/g, '');
        assert.ok(!/[{}]/.test(resto), `${chave}: "${texto}"`);
      }
    }
  });

  test('números ([num]) curtos e nota do Google no formato 4,9', () => {
    for (const dono of [comum, ...Object.values(nichos)]) {
      for (const id of listaPadrao(dono === comum ? null : dono, 'num')) {
        const v = textoPadrao(dono === comum ? null : dono, `num.${id}.v`);
        assert.ok(comprimento(v) <= 8, v);
      }
      if (dono.textos['aval.nota']) assert.match(dono.textos['aval.nota'], /^[1-5],\d$/);
    }
  });
});

describe('nichos — conformidade (PDF cap. 11)', () => {
  /** Todos os textos que o nicho pode exibir: os próprios, os herdados do comum e os de item novo. */
  function textosEfetivos(n) {
    const r = { ...comum.textos, ...n.textos };
    for (const [lista, item] of Object.entries(comum.novoItem)) {
      for (const [campo, texto] of Object.entries(item)) r[`novoItem.${lista}.${campo}`] = texto;
    }
    r['nicho.mensagemWhatsapp'] = n.mensagemWhatsapp;
    return r;
  }

  test('termos proibidos ausentes de todos os textos que o nicho pode exibir', () => {
    for (const [id, n] of Object.entries(nichos)) {
      for (const [chave, texto] of Object.entries(textosEfetivos(n))) {
        for (const termo of n.conformidade.termosProibidos) {
          assert.ok(!contemTermo(texto, termo), `${id}: "${termo}" em ${chave}: "${texto}"`);
        }
      }
    }
  });

  test('a busca de termos é por frase inteira (sem falso positivo dentro de outra palavra)', () => {
    assert.ok(contemTermo('Tratamento SEM DOR garantido', 'sem dor'));
    assert.ok(contemTermo('Resultado 100% natural', '100%'));
    assert.ok(!contemTermo('Contabilidade sem dor de cabeça', 'garantido'));
    assert.ok(!contemTermo('Para melhorar a sua rotina', 'a melhor'));
  });

  test('advocacia: nenhum depoimento, nota ou cliente próprio; sem números de resultado', () => {
    const chaves = Object.keys(nichos.advocacia.textos);
    assert.ok(!chaves.some((k) => /^(dep|aval|cli)\./.test(k)), 'advocacia não define depoimentos/nota/clientes');
    for (const k of chaves.filter((c) => c.startsWith('num.'))) {
      assert.ok(!/caso|causa|vit[oó]ria|ganh|indeniza|sucesso|clientes/i.test(nichos.advocacia.textos[k]), `${k}: ${nichos.advocacia.textos[k]}`);
    }
  });

  test('clínicas: sem "antes e depois", sem promessa de resultado, sem preço ou gratuidade', () => {
    for (const texto of Object.values(nichos.clinicas.textos)) {
      assert.ok(!/antes e depois|garanti|sem dor|indolor|r\$|gr[aá]tis|gratuit|promo[cç][aã]o|desconto/i.test(texto), texto);
    }
  });

  test('finanças: nenhuma promessa de economia com número fixo (sem %, sem R$)', () => {
    for (const texto of Object.values(nichos.financas.textos)) assert.ok(!/%|r\$/i.test(texto), texto);
  });

  test('empresas (engenharia): menciona CREA e ART', () => {
    const tudo = Object.values(nichos.empresas.textos).join(' ');
    assert.match(tudo, /\bCREA\b/);
    assert.match(tudo, /\bART\b/);
  });
});

// ================================================================== ícones

describe('ícones (§3.7, PDF cap. 8)', () => {
  const CATEGORIAS = ['geral', 'juridico', 'financas', 'empresas', 'saude'];
  const UTILITARIOS = ['seta', 'check', 'estrela', 'pin', 'relogio', 'telefone', 'email', 'whatsapp', 'instagram', 'facebook',
    'linkedin', 'youtube', 'google', 'mais', 'menos', 'aspas', 'mapa', 'menu', 'fechar', 'calendario', 'circulo'];

  test(`estrutura, ~75 Phosphor + ~10 Healthicons e tamanho ≤ ${LIMITE_BYTES / 1024} KB`, () => {
    assert.equal(iconesJson.versao, 1);
    const bytes = fs.statSync(path.join(BIB, 'icones/icones.json')).size;
    assert.ok(bytes <= LIMITE_BYTES, `${bytes} bytes`);
    assert.ok(iconesJson.icones.length >= 80 && iconesJson.icones.length <= 110, `${iconesJson.icones.length} ícones`);
    const ids = iconesJson.icones.map((i) => i.id);
    assert.equal(new Set(ids).size, ids.length, 'ids únicos');
    const health = iconesJson.icones.filter((i) => i.svg.fino.includes('viewBox="0 0 48 48"')).length;
    assert.ok(health >= 8 && health <= 14, `${health} Healthicons`);
    for (const i of iconesJson.icones) {
      assert.match(i.id, /^[a-z0-9-]+$/);
      assert.ok(i.nome && CATEGORIAS.includes(i.categoria), i.id);
      assert.ok(i.palavras.length >= 3, `${i.id}: poucas palavras`);
      for (const p of i.palavras) assert.equal(normalizar(p), p, `${i.id}: palavra não normalizada "${p}"`);
    }
  });

  test('palavras-chave não se repetem entre ícones', () => {
    const dono = new Map();
    for (const i of iconesJson.icones) {
      for (const p of i.palavras) {
        assert.ok(!dono.has(p), `"${p}" em ${dono.get(p)} e ${i.id}`);
        dono.set(p, i.id);
      }
    }
  });

  test('utilitários do §3.7 presentes', () => {
    assert.deepEqual(Object.keys(iconesJson.utilitarios).sort(), [...UTILITARIOS].sort());
  });

  test('SVG normalizado nos três pesos (sem width/height, viewBox, currentColor, aria-hidden, sem title/on*)', () => {
    const todos = [...iconesJson.icones, ...Object.values(iconesJson.utilitarios)];
    for (const i of todos) {
      assert.deepEqual(Object.keys(i.svg).sort(), ['duotone', 'fino', 'preenchido'], i.id);
      for (const [peso, svg] of Object.entries(i.svg)) assert.deepEqual(problemasSvg(svg), [], `${i.id}/${peso}`);
    }
    assert.ok(problemasSvg('<svg width="24" viewBox="0 0 1 1" onload="x()"><title>a</title></svg>').length >= 3);
  });

  test('nenhum ícone fica inalcançável: alguma palavra dele, sozinha, escolhe o próprio ícone', () => {
    for (const i of iconesJson.icones) {
      assert.ok(i.palavras.some((p) => escolherIcone(p, iconesJson) === i.id), `${i.id} nunca é escolhido automaticamente`);
    }
  });

  test('tabela "Resultados testados" do PDF §8.2', () => {
    const esperado = {
      'Direito Trabalhista': 'Crachá',
      'Direito Previdenciário': 'Documento pessoal',
      'Planejamento tributário': 'Gráfico em alta',
      'Contador dedicado': 'Pessoa',
      'Projetos elétricos': 'Raio',
      'Energia solar': 'Painel solar',
      'Plantão 24 horas': 'Relógio',
      Ortodontia: 'Aparelho dental',
      Implantes: 'Implante',
      'Harmonização facial': 'Brilho',
    };
    for (const [titulo, nome] of Object.entries(esperado)) assert.equal(nomeIcone(escolherIcone(titulo, iconesJson)), nome, titulo);
  });

  test('regras do algoritmo: a mais longa vence; palavras de até 3 letras só inteiras', () => {
    assert.equal(escolherIcone('Consultoria técnica', iconesJson), 'clipboard-text', '"consultoria tecnica" (prancheta) vence "consultoria"');
    assert.equal(escolherIcone('Consultoria', iconesJson), 'briefcase');
    assert.equal(nomeIcone('briefcase'), 'Maleta');
    assert.equal(nomeIcone('clipboard-text'), 'Prancheta');
    assert.equal(escolherIcone('Emissão de ART', iconesJson), 'file-text');
    assert.equal(escolherIcone('(ART)', iconesJson), 'file-text');
    assert.equal(escolherIcone('Arte e design', iconesJson), null, '"art" não casa dentro de "arte"');
    assert.equal(escolherIcone('Parte elétrica', iconesJson), 'lightning', '"art" não casa dentro de "parte"');
    assert.equal(escolherIcone('', iconesJson), null);
  });

  test('dezenas de títulos de serviço dos 4 nichos recebem um ícone sensato', () => {
    const casos = {
      // advocacia
      'Família e Sucessões': 'users-three', 'Direito de Família': 'users-three', 'Divórcio e guarda': 'users-three',
      'Direito Civil e Contratos': 'file-text', 'Direito Empresarial': 'buildings', 'Direito do Consumidor': 'shopping-cart',
      'Direito Imobiliário': 'house-line', 'Direito Penal': 'gavel', 'Direito Criminal': 'gavel', 'Direito Tributário': 'receipt',
      'Direito Bancário': 'bank', 'Inventário e partilha': 'scroll', 'Planejamento sucessório': 'scroll',
      'Planejamento previdenciário': 'identification-card', Aposentadoria: 'identification-card',
      'Revisão de benefícios': 'identification-card', 'Acidentes de trânsito': 'car', 'Revisão de contratos': 'file-magnifying-glass',
      'Atendimento personalizado': 'handshake', 'Honorários transparentes': 'receipt', 'Online e presencial': 'globe',
      'Orientação jurídica': 'scales', 'Consulta jurídica': 'scales', 'Mediação e conciliação': 'handshake',
      // finanças
      'Abertura de empresa': 'rocket', 'Contabilidade mensal': 'calculator', 'Folha de pagamento': 'coins',
      'Imposto de Renda': 'receipt', 'Regularização fiscal': 'seal-check', 'BPO financeiro': 'chart-pie',
      'Consultoria financeira': 'chart-line-up', 'Crédito consignado': 'bank', 'Financiamento imobiliário': 'bank',
      'Educação financeira': 'piggy-bank', 'Previdência privada': 'piggy-bank', 'Departamento pessoal': 'identification-badge',
      'Simples Nacional': 'receipt', 'Documentos pelo celular': 'device-mobile', 'Relatórios mensais': 'chart-pie',
      'Reforma tributária': 'receipt', 'Assessoria contábil': 'calculator',
      // empresas
      'Manutenção preventiva': 'wrench', 'Manutenção predial': 'wrench', 'Laudos e inspeções': 'file-magnifying-glass',
      'Instalações elétricas': 'lightning', 'Automação industrial': 'gear', Climatização: 'snowflake',
      'Prevenção de incêndio': 'fire-extinguisher', 'CFTV e alarmes': 'security-camera', 'Segurança do trabalho': 'hard-hat',
      'Obras e reformas': 'hammer', 'Construção civil': 'hard-hat', 'Projeto arquitetônico': 'ruler', 'Projetos hidráulicos': 'drop',
      'Cabine primária': 'lightning', 'SPDA e aterramento': 'lightning', 'Engenharia elétrica': 'lightning',
      'Logística e transporte': 'truck', 'Limpeza e conservação': 'broom', 'Gestão de resíduos': 'leaf', 'Suporte técnico': 'headset',
      'Equipe própria': 'users', 'Normas de segurança': 'shield-check', 'Usinagem e caldeiraria': 'factory',
      'Marketing digital': 'megaphone', 'Treinamentos NR': 'chalkboard-teacher',
      // clínicas
      'Implante dentário': 'implant', 'Limpeza e prevenção': 'dental-hygiene', 'Clareamento dental': 'tooth',
      Odontopediatria: 'pediatrics', 'Lentes de contato dental': 'tooth', 'Tratamento de canal': 'tooth', Periodontia: 'mouth',
      'Radiografia digital': 'x-ray', 'Atendimento acolhedor': 'hand-heart', 'Horários flexíveis': 'clock',
      Biossegurança: 'sterilization', 'Toxina botulínica': 'syringe', 'Preenchimento labial': 'mouth',
      'Limpeza de pele': 'sparkle', 'Drenagem linfática': 'flower-lotus', 'Fisioterapia ortopédica': 'physical-therapy',
      'Pilates clínico': 'physical-therapy', RPG: 'physical-therapy', 'Psicoterapia individual': 'brain',
      'Psicologia infantil': 'brain', 'Clínica geral': 'stethoscope', Cardiologia: 'heartbeat', Nutrição: 'nutrition',
      'Emergência odontológica': 'first-aid-kit', Convênios: 'identification-card', 'Avaliação completa': 'clipboard-text',
    };
    assert.ok(Object.keys(casos).length >= 80);
    for (const [titulo, id] of Object.entries(casos)) assert.equal(escolherIcone(titulo, iconesJson), id, titulo);
  });

  test('títulos padrão de serviços e diferenciais dos nichos têm ícone automático; iconesPadrao existem', () => {
    const esperado = {
      advocacia: { dif: ['handshake', 'receipt', 'globe'], serv: ['identification-badge', 'identification-card', 'users-three', 'file-text', 'buildings', 'shopping-cart'] },
      financas: { dif: ['user-circle', 'device-mobile', 'seal-check'], serv: ['rocket', 'chart-line-up', 'calculator', 'coins', 'receipt', 'seal-check'] },
      empresas: { dif: ['users', 'file-text', 'shield-check'], serv: ['lightning', 'solar-panel', 'wrench', 'clock', 'file-magnifying-glass', 'clipboard-text'] },
      clinicas: { dif: ['hand-heart', 'x-ray', 'clock'], serv: ['braces', 'implant', 'sparkle', 'dental-hygiene', 'tooth', 'pediatrics'] },
    };
    for (const [id, n] of Object.entries(nichos)) {
      for (const lista of ['dif', 'serv']) {
        const obtido = listaPadrao(n, lista).map((item) => escolherIcone(textoPadrao(n, `${lista}.${item}.t`), iconesJson));
        assert.deepEqual(obtido, esperado[id][lista], `${id}.${lista}`);
      }
      assert.deepEqual(Object.keys(n.iconesPadrao).sort(), ['dif', 'serv'], `${id}: iconesPadrao só para listas com ícone`);
      for (const ids of Object.values(n.iconesPadrao)) for (const i of ids) assert.ok(idsIcones.has(i), `${id}: ícone padrão "${i}" não existe`);
    }
  });
});

// ================================================================== fontes

describe('fontes (§3.8, [M16])', () => {
  test('pares do contrato, na ordem, com arquivos de cada família', () => {
    assert.deepEqual(Object.keys(fontes.pares), ['classica', 'editorial', 'moderna', 'amigavel']);
    const familias = {
      classica: ['Libre Caslon Text', 'Source Sans 3'], editorial: ['DM Serif Display', 'DM Sans'],
      moderna: ['Manrope', 'Manrope'], amigavel: ['Nunito', 'Nunito'],
    };
    for (const [id, par] of Object.entries(fontes.pares)) {
      assert.deepEqual([par.titulos, par.texto], familias[id], id);
      assert.ok(par.nome && par.descricao, id);
      for (const f of [par.titulos, par.texto]) assert.ok(fontes.arquivos.some((a) => a.familia === f), `${id}: sem arquivo de ${f}`);
    }
  });

  test('arquivos woff2 latin copiados, com peso e estilo', () => {
    const esperados = ['libre-caslon-text-latin-400-normal.woff2', 'libre-caslon-text-latin-700-normal.woff2',
      'source-sans-3-latin-wght-normal.woff2', 'dm-serif-display-latin-400-normal.woff2', 'dm-sans-latin-wght-normal.woff2',
      'manrope-latin-wght-normal.woff2', 'nunito-latin-wght-normal.woff2'];
    assert.deepEqual(fontes.arquivos.map((a) => a.arquivo).sort(), [...esperados].sort());
    for (const a of fontes.arquivos) {
      const buf = fs.readFileSync(path.join(BIB, 'fontes', a.arquivo));
      assert.equal(buf.toString('latin1', 0, 4), 'wOF2', a.arquivo);
      assert.match(a.peso, /^\d{3,4}( \d{3,4})?$/, a.arquivo);
      assert.equal(a.estilo, 'normal');
      assert.match(a.unicodeRange, /^U\+0000-00FF/);
    }
    assert.equal(fontes.arquivos.find((a) => a.familia === 'Manrope').peso, '200 800');
  });

  test('reserva com ajustes métricos para fonte de sistema serifada e sem serifa', () => {
    const familias = [...new Set(fontes.arquivos.map((a) => a.familia))];
    assert.deepEqual(Object.keys(fontes.reserva).sort(), familias.sort());
    for (const [familia, r] of Object.entries(fontes.reserva)) {
      assert.equal(r.familia, `${familia} Reserva`);
      assert.ok(['serifada', 'sem-serifa'].includes(r.base));
      assert.ok(r.local.length >= 2);
      assert.deepEqual(Object.keys(r.ajustes), ['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override']);
      for (const v of Object.values(r.ajustes)) assert.match(v, /^\d{1,3}\.\d{2}%$/);
      const tamanho = parseFloat(r.ajustes['size-adjust']);
      assert.ok(tamanho > 70 && tamanho < 140, `${familia}: size-adjust ${tamanho}`);
    }
    assert.equal(fontes.reserva['Libre Caslon Text'].base, 'serifada');
    assert.equal(fontes.reserva.Manrope.base, 'sem-serifa');
    assert.equal(fontes.sistema.serifada.local[0], 'Times New Roman');
    assert.equal(fontes.sistema['sem-serifa'].local[0], 'Arial');
  });
});

// ================================================================== integração com o preparo (se já existir)

describe('integração com o preparo compartilhado', { skip: !existe('public_html/editor/js/compartilhado/preparo.mjs') }, () => {
  test('todo nicho × modelo × especialidade gera o site, sem variável sobrando e sem texto vazio', async () => {
    const { carregarBiblioteca } = await import(pathToFileURL(path.join(RAIZ, 'tests/paridade/carregar-biblioteca.mjs')).href);
    const { criarDocumento, registroCampos, registroListas } = await import(pathToFileURL(path.join(RAIZ, 'public_html/editor/js/compartilhado/documento.mjs')).href);
    const { prepararSite } = await import(pathToFileURL(path.join(RAIZ, 'public_html/editor/js/compartilhado/preparo.mjs')).href);
    const lib = carregarBiblioteca(BIB);
    // Só confere chaves cujo manifesto dono já existe (seções de outros módulos podem faltar).
    const campos = registroCampos(lib);
    const listas = registroListas(lib);
    const registrada = (chave) => {
      const p = chave.split('.');
      return p.length === 2 ? chave in campos : p.length === 3 && Boolean(listas[p[0]]?.campos?.[p[2]]);
    };
    const opcoes = { modo: 'editor', urlMidia: '/api/media/{id}/{w}', midia: {}, ano: 2026 };
    let combinacoes = 0;
    for (const n of NICHOS) {
      for (const m of MODELOS) {
        for (const esp of nichos[n].especialidades) {
          const doc = criarDocumento({ nicho: n, modelo: m, especialidade: esp.id, dados: { whatsapp: nichos[n].exemplo.whatsapp } }, lib);
          assert.equal(doc.estilo.cor, nichos[n].padroesPorModelo[m].cor);
          const site = prepararSite(doc, lib, opcoes);
          assert.ok(site.html.length > 0, `${n}/${m}/${esp.id}`);
          assert.ok(!/\{(nome|cidade|segmento)\}/.test(site.html), `${n}/${m}/${esp.id}: variável sem trocar`);
          assert.ok(site.html.includes(nichos[n].exemplo.nome.replace(/&/g, '&amp;')), `${n}/${m}/${esp.id}: nome do exemplo`);
          for (const [, chave] of site.html.matchAll(/data-k="([a-z]+\.[a-z0-9.]+)">\s*<\//g)) {
            if (registrada(chave)) assert.fail(`${n}/${m}/${esp.id}: texto vazio em ${chave}`);
          }
          combinacoes += 1;
        }
      }
    }
    assert.equal(combinacoes, 3 * (1 + 3 + 3 + 5));
  });
});
