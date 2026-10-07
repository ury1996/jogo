import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { acharIcone, escolherIcone, iconeDoItem, svg } from '../../public_html/editor/js/compartilhado/icones.mjs';
import { criarDocumento } from '../../public_html/editor/js/compartilhado/documento.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../fixtures/biblioteca-mini', import.meta.url)));
const nomeDe = (id) => acharIcone(lib, id)?.nome ?? null;

test('exemplos da tabela do PDF §8.2', () => {
  const esperado = {
    'Direito Trabalhista': 'Crachá',
    'Direito Previdenciário': 'Documento pessoal',
    'Planejamento tributário': 'Gráfico em alta',
    'Contador dedicado': 'Pessoa',
    'Energia solar': 'Painel solar',
    Ortodontia: 'Aparelho dental',
    Implantes: 'Implante',
    'Harmonização facial': 'Brilho',
    'Projetos elétricos': 'Raio',
    'Plantão 24 horas': 'Relógio',
  };
  for (const [titulo, nome] of Object.entries(esperado)) assert.equal(nomeDe(escolherIcone(titulo, lib.icones)), nome, titulo);
});

test('a palavra mais longa vence: "consultoria tecnica" (prancheta) ganha de "consultoria" (maleta)', () => {
  assert.equal(escolherIcone('Consultoria técnica', lib.icones), 'prancheta');
  assert.equal(escolherIcone('Consultoria', lib.icones), 'maleta');
  assert.equal(escolherIcone('CONSULTORIA    TÉCNICA', lib.icones), 'prancheta', 'normaliza caixa, acento e espaços');
});

test('palavras de até 3 letras casam só inteiras (limites [^a-z0-9])', () => {
  assert.equal(escolherIcone('Emissão de ART', lib.icones), 'documento');
  assert.equal(escolherIcone('(ART)', lib.icones), 'documento');
  assert.equal(escolherIcone('art.', lib.icones), 'documento');
  assert.equal(escolherIcone('Parte elétrica', lib.icones), 'raio', '"art" dentro de "parte" não casa');
  assert.equal(escolherIcone('Arte', lib.icones), null);
  assert.equal(escolherIcone('art2', lib.icones), null, 'dígito também é parte da palavra');
});

test('empate = ordem do arquivo; sem casamento ou vazio → null; aceita a lista direto', () => {
  const lista = [
    { id: 'a', palavras: ['abcd'] },
    { id: 'b', palavras: ['wxyz'] },
  ];
  assert.equal(escolherIcone('abcd wxyz', lista), 'a');
  assert.equal(escolherIcone('wxyz abcd', [...lista].reverse()), 'b');
  assert.equal(escolherIcone('nada a ver', lib.icones), null);
  assert.equal(escolherIcone('', lib.icones), null);
  assert.equal(escolherIcone('abcd', null), null);
});

test('iconeDoItem: manual → automático → padrão do nicho (cíclico) → "circulo"', () => {
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'moderno' }, lib);
  assert.equal(iconeDoItem(doc, lib, 'serv', '1', 0), 'aparelho', 'automático por "Ortodontia"');
  doc.icones = { 'serv.1': 'dente', 'serv.2': 'nao-existe' };
  assert.equal(iconeDoItem(doc, lib, 'serv', '1', 0), 'dente', 'manual');
  assert.equal(iconeDoItem(doc, lib, 'serv', '2', 1), 'implante', 'manual inexistente → automático');
  doc.textos = { 'serv.5.t': 'Sem palavra conhecida', 'serv.6.t': 'Outra coisa' };
  assert.equal(iconeDoItem(doc, lib, 'serv', '5', 4), 'dente', 'padrão do nicho: posição 4 % 4 = 0');
  assert.equal(iconeDoItem(doc, lib, 'serv', '6', 5), 'aparelho', 'posição 5 % 4 = 1');
  assert.equal(iconeDoItem(doc, lib, 'faq', '1', 0), 'circulo', 'sem padrão para a lista');
  const semNicho = { ...doc, nicho: 'nao-existe' };
  assert.equal(iconeDoItem(semNicho, lib, 'serv', '5', 0), 'circulo');
});

test('iconeDoItem usa o texto efetivo, com variáveis trocadas', () => {
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'moderno', dados: { nome: 'Implantes Já' } }, lib);
  doc.textos = { 'serv.1.t': 'Serviço da {nome}' };
  assert.equal(iconeDoItem(doc, lib, 'serv', '1', 0), 'implante', '"{nome}" vira "Implantes Já"');
  doc.textos = { 'serv.1.t': 'Atendimento na {nome}' };
  assert.equal(iconeDoItem(doc, lib, 'serv', '1', 0), 'pessoa', '"atendimento" (11) é mais longa que "implante" (8)');
});

test('svg por acabamento: classico→fino, moderno→duotone, direto→preenchido (utilitários idem)', () => {
  const tooth = acharIcone(lib, 'dente').svg;
  assert.equal(svg(lib, 'dente', 'classico'), tooth.fino);
  assert.equal(svg(lib, 'dente', 'moderno'), tooth.duotone);
  assert.equal(svg(lib, 'dente', 'direto'), tooth.preenchido);
  assert.equal(svg(lib, 'dente', 'outro'), tooth.fino);
  const seta = lib.icones.utilitarios.seta.svg;
  assert.equal(svg(lib, 'seta', 'moderno'), seta.duotone);
  assert.equal(svg(lib, 'seta', 'direto'), seta.preenchido);
  assert.equal(svg(lib, 'aparelho', 'moderno'), acharIcone(lib, 'aparelho').svg.fino, 'Healthicons: duotone = traço');
  assert.equal(svg(lib, 'nao-existe', 'moderno'), '');
  const parcial = { icones: { icones: [{ id: 'x', svg: { preenchido: '<svg/>' } }] } };
  assert.equal(svg(parcial, 'x', 'classico'), '<svg/>', 'peso ausente cai no que existir');
});
