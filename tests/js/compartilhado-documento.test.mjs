import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { documentoV1 } from '../paridade/funcoes.mjs';
import {
  aplicarModelo, completarDados, converterChaveV1, criarDocumento, definicaoCampo, especialidade, migrar, receitaModelo,
  registroCampos, registroListas,
} from '../../public_html/editor/js/compartilhado/documento.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../fixtures/biblioteca-mini', import.meta.url)));

const CHAVES_DOC = ['versaoEsquema', 'nicho', 'especialidade', 'modelo', 'estilo', 'dados', 'secoes', 'textos', 'listas', 'imagens',
  'icones', 'confirmados', 'rastreamento', 'seo'];

test('criarDocumento: estilo padrão do nicho + acabamento do modelo + WhatsApp flutuante; dados completos', () => {
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'classico', dados: { nome: 'X' } }, lib);
  assert.deepEqual(Object.keys(doc), CHAVES_DOC);
  assert.equal(doc.versaoEsquema, 2);
  assert.deepEqual(doc.estilo, { cor: '#2a7f86', fonte: 'amigavel', acabamento: 'classico', whatsappFlutuante: true });
  assert.equal(doc.especialidade, 'odontologia', 'a primeira do nicho');
  assert.deepEqual(doc.dados, {
    nome: 'X', cidade: '', uf: '', whatsapp: '', telefone: '', email: '', logo: null,
    endereco: { cep: '', logradouro: '', numero: '', complemento: '', bairro: '' },
    horarios: {},
    registro: { numero: '', uf: '', responsavel: '' },
    redes: { instagram: '', facebook: '', linkedin: '', youtube: '', google: '' },
  });
  assert.deepEqual(doc.secoes.map((s) => `${s.tipo}/${s.opcao}`), ['header/barra', 'hero/formulario', 'servicos/cards', 'faq/foto-ajuda', 'rodape/completo']);
  assert.deepEqual(doc.rastreamento, { gtm: '', ga4: '', metaPixel: '' });
  assert.deepEqual(doc.seo, { titulo: null, descricao: null });
});

test('criarDocumento: estilo recebido sobrescreve (só valores válidos); especialidade escolhida', () => {
  const doc = criarDocumento({
    nicho: 'clinicas', modelo: 'moderno', especialidade: 'estetica',
    estilo: { cor: '#ABC', fonte: 'moderna', acabamento: 'direto', whatsappFlutuante: false },
  }, lib);
  assert.deepEqual(doc.estilo, { cor: '#aabbcc', fonte: 'moderna', acabamento: 'direto', whatsappFlutuante: false });
  assert.equal(doc.especialidade, 'estetica');
  const ruim = criarDocumento({ nicho: 'clinicas', modelo: 'moderno', especialidade: 'x', estilo: { cor: 'azul', fonte: 'x', acabamento: 'x', whatsappFlutuante: 'sim' } }, lib);
  assert.deepEqual(ruim.estilo, { cor: '#c23b6e', fonte: 'editorial', acabamento: 'moderno', whatsappFlutuante: true });
  assert.equal(ruim.especialidade, 'odontologia');
  assert.throws(() => criarDocumento({ nicho: 'x', modelo: 'moderno' }, lib), /Nicho desconhecido/);
  assert.throws(() => criarDocumento({ nicho: 'clinicas', modelo: 'x' }, lib), /Modelo desconhecido/);
});

test('receitaModelo filtra por so/exceto; criarDocumento descarta seções que a biblioteca não tem', () => {
  const modelo = { secoes: [['header', 'simples'], ['clientes', 'faixa', { so: ['empresas'] }], ['faq', 'centralizada', { exceto: ['clinicas'] }], { tipo: 'rodape', opcao: 'simples' }, 'lixo', ['']] };
  assert.deepEqual(receitaModelo(lib, modelo, 'clinicas'), [{ tipo: 'header', opcao: 'simples' }, { tipo: 'rodape', opcao: 'simples' }]);
  assert.deepEqual(receitaModelo(lib, modelo, 'empresas').map((s) => s.tipo), ['header', 'clientes', 'faq', 'rodape']);
  const libExtra = JSON.parse(JSON.stringify(lib));
  libExtra.modelos.moderno.secoes.splice(2, 0, ['depoimentos', 'cards-nota']);
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'moderno' }, libExtra);
  assert.equal(doc.secoes.some((s) => s.tipo === 'depoimentos'), false);
  assert.deepEqual(receitaModelo(lib, 'nao-existe', 'clinicas'), []);
});

test('aplicarModelo troca seções, acabamento e fonte; mantém o resto (inclusive a cor)', () => {
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'moderno', estilo: { cor: '#123456' } }, lib);
  doc.textos = { 'hero.titulo': 'T' };
  doc.imagens = { 'hero.img': 'm_00000001' };
  doc.icones = { 'serv.1': 'dente' };
  doc.listas = { serv: ['2', '1', '3'] };
  doc.confirmados = ['num'];
  doc.rastreamento.gtm = 'GTM-1';
  doc.seo.titulo = 'S';
  const novo = aplicarModelo(doc, lib, 'classico');
  assert.equal(novo.modelo, 'classico');
  assert.deepEqual(novo.estilo, { cor: '#123456', fonte: 'amigavel', acabamento: 'classico', whatsappFlutuante: true });
  assert.deepEqual(novo.secoes.map((s) => s.opcao), ['barra', 'formulario', 'cards', 'foto-ajuda', 'completo']);
  for (const k of ['textos', 'imagens', 'icones', 'listas', 'confirmados', 'rastreamento', 'seo', 'dados', 'nicho', 'especialidade']) {
    assert.deepEqual(novo[k], doc[k], k);
  }
  assert.equal(doc.modelo, 'moderno', 'não altera o original');
  assert.throws(() => aplicarModelo(doc, lib, 'x'), /Modelo desconhecido/);
});

test('migrar v1 → v2: opção por id, listas base 0 → ids, ícones/imagens idem, dados completos', () => {
  const v2 = migrar(documentoV1(), lib);
  assert.deepEqual(Object.keys(v2), CHAVES_DOC);
  assert.equal(v2.versaoEsquema, 2);
  assert.deepEqual(v2.secoes, [
    { tipo: 'header', opcao: 'simples' }, { tipo: 'hero', opcao: 'cards-flutuantes' }, { tipo: 'servicos', opcao: 'cards' },
    { tipo: 'faq', opcao: 'centralizada' }, { tipo: 'rodape', opcao: 'completo' },
  ], 'servicos 3 não existe na biblioteca de teste (2 opções) → primeira; faq 9 → primeira; tipo desconhecido sai');
  const semLib = migrar(documentoV1());
  assert.deepEqual(semLib.secoes[2], { tipo: 'servicos', opcao: 'blocos' }, 'sem biblioteca: catálogo §3.2 (3 = Blocos)');
  assert.deepEqual(v2.textos, {
    'hero.titulo': 'Cuidado com o seu sorriso…', 'serv.1.t': 'Ortodontia', 'serv.2.d': 'Desc', 'sobrel.1.t': 'Item',
    'cli.3.t': 'ACME', 'cli.titulo': 'Clientes', 'contato.end': 'Rua X',
  });
  assert.deepEqual(v2.imagens, { 'hero.img': 'm_91c0de', 'serv.1.img': 'm_0a1b2c', 'equipe.3.f': 'm_ffffff' });
  assert.deepEqual(v2.icones, { 'serv.2': 'h-odontology', 'dif.1': 'heart' });
  assert.equal(v2.estilo.cor, '#c23b6e');
  assert.equal(v2.especialidade, 'odontologia');
  assert.equal(v2.dados.logo, 'm_8f3a2c');
  assert.equal(v2.dados.endereco.cep, '');
  assert.deepEqual(v2.rastreamento, { gtm: 'GTM-XXXXXXX', ga4: '', metaPixel: '' });
  assert.deepEqual(migrar(v2, lib), v2, 'idempotente');
  assert.equal(converterChaveV1('hero.titulo'), 'hero.titulo');
});

test('migrar normaliza documentos v2 malformados sem lançar', () => {
  const r = migrar({ versaoEsquema: 2, textos: [], listas: { serv: ['1', 2, 'x y', '1'], X: ['1'] }, secoes: [{ tipo: 'hero' }, 'x', { tipo: 'hero', opcao: 'a' }], confirmados: ['num', 'num', 3] }, lib);
  assert.deepEqual(r.textos, {});
  assert.deepEqual(r.listas, { serv: ['1', '2'] });
  assert.deepEqual(r.secoes, [{ tipo: 'hero', opcao: 'a' }]);
  assert.deepEqual(r.confirmados, ['num']);
  assert.deepEqual(Object.keys(migrar(null)), CHAVES_DOC);
});

test('completarDados mantém só o esquema e normaliza horários', () => {
  const d = completarDados({ nome: 7, extra: 'x', horarios: { seg: ['08:00', 1], ter: 'x', dom: false, xyz: [] } });
  assert.equal(d.nome, '7');
  assert.equal('extra' in d, false);
  assert.deepEqual(d.horarios, { seg: ['08:00', '1'], dom: null });
});

test('registroCampos: chave → definição + dono; listas como "serv.*.t"', () => {
  const r = registroCampos(lib);
  assert.deepEqual(r['serv.titulo'], { tipo: 'texto', max: 80, dono: 'servicos', grupo: 'serv', campo: 'titulo' });
  assert.deepEqual(r['serv.*.ic'], { tipo: 'icone', automatico: 't', dono: 'servicos', lista: 'serv', campo: 'ic' });
  assert.equal(r['form.botao'].dono, 'hero');
  assert.equal(definicaoCampo(r, 'serv.nk3f.d').max, 160);
  assert.equal(definicaoCampo(r, 'hero.titulo').max, 90);
  assert.equal(definicaoCampo(r, 'x.y'), null);
  assert.equal(definicaoCampo(r, 'a.b.c.d'), null);
  assert.deepEqual(registroListas(lib).faq.repete, [3, 10]);
  assert.equal(registroListas(lib).serv.dono, 'servicos');
});

test('especialidade(doc, lib)', () => {
  assert.equal(especialidade({ nicho: 'clinicas', especialidade: 'estetica' }, lib).segmento, 'Clínica de estética');
  assert.equal(especialidade({ nicho: 'clinicas', especialidade: 'x' }, lib).id, 'odontologia');
  assert.equal(especialidade({ nicho: 'x' }, lib), null);
});
