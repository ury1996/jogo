// Lógica pura do editor (public_html/editor/js/editor/operacoes.mjs) com a biblioteca real.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { criarDocumento } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { itensLista, textoEfetivo } from '../../public_html/editor/js/compartilhado/textos.mjs';
import * as op from '../../public_html/editor/js/editor/operacoes.mjs';

const lib = carregarBiblioteca(fileURLToPath(new URL('../../biblioteca', import.meta.url)));
const novoDoc = (extra = {}) => criarDocumento({
  nicho: 'clinicas', modelo: 'moderno', dados: { nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', uf: 'SP' }, ...extra,
}, lib);
const tipos = (doc) => doc.secoes.map((s) => s.tipo);

/* ------------------------------------------------------------------ seções */

test('seções: cabeçalho e rodapé são fixos (não se movem nem saem)', () => {
  const doc = novoDoc();
  assert.equal(tipos(doc)[0], 'header');
  assert.equal(tipos(doc).at(-1), 'rodape');
  const ultimo = doc.secoes.length - 1;
  assert.equal(op.podeMoverSecao(doc, lib, 0, 1), false);
  assert.equal(op.podeMoverSecao(doc, lib, ultimo, -1), false);
  assert.equal(op.moverSecao(doc, lib, 0, 3), doc, 'header não se move');
  assert.equal(op.moverSecao(doc, lib, ultimo, 2), doc, 'rodapé não se move');
  assert.equal(op.podeRemoverSecao(doc, lib, 0), false);
  assert.equal(op.removerSecao(doc, lib, ultimo), doc);
  assert.deepEqual(op.faixaMovel(doc, lib), { min: 1, max: ultimo - 1 });
});

test('seções: mover respeita a faixa entre cabeçalho e rodapé', () => {
  const doc = novoDoc();
  const ultimo = doc.secoes.length - 1;
  assert.equal(op.podeMoverSecao(doc, lib, 1, -1), false, 'hero não passa o header');
  assert.equal(op.podeMoverSecao(doc, lib, ultimo - 1, 1), false, 'contato não passa o rodapé');
  const a = op.moverSecao(doc, lib, 1, 0);
  assert.equal(a.secoes[0].tipo, 'header');
  assert.equal(a.secoes[1].tipo, 'hero', 'preso no mínimo: não mudou');
  const b = op.moverSecao(doc, lib, 1, 99);
  assert.equal(b.secoes.at(-1).tipo, 'rodape');
  assert.equal(b.secoes.at(-2).tipo, 'hero', 'preso no máximo: logo antes do rodapé');
  const c = op.moverSecaoDelta(doc, lib, 2, 1);
  assert.equal(c.secoes[3].tipo, doc.secoes[2].tipo);
  assert.equal(c.secoes[2].tipo, doc.secoes[3].tipo);
  assert.notEqual(c, doc);
  assert.equal(c.textos, doc.textos, 'camadas intocadas continuam as mesmas');
  assert.equal(doc.secoes[2].tipo, 'diferenciais', 'documento original intacto');
});

test('seções: posição de soltura do arrastar', () => {
  assert.equal(op.posicaoSoltura(2, 5, true), 4, 'de cima para baixo, antes do alvo');
  assert.equal(op.posicaoSoltura(2, 5, false), 5, 'de cima para baixo, depois do alvo');
  assert.equal(op.posicaoSoltura(5, 2, true), 2, 'de baixo para cima, antes do alvo');
  assert.equal(op.posicaoSoltura(5, 2, false), 3, 'de baixo para cima, depois do alvo');
});

test('seções: adicionar entra após a selecionada ou antes do rodapé; cada tipo uma vez', () => {
  const doc = novoDoc();
  const ausentes = op.tiposAusentes(doc, lib);
  assert.ok(ausentes.includes('numeros'));
  assert.ok(ausentes.includes('clientes'));
  assert.ok(!ausentes.includes('hero'));
  assert.ok(!ausentes.includes('header'));

  const r1 = op.adicionarSecao(doc, lib, 'numeros');
  assert.equal(r1.indice, doc.secoes.length - 1, 'sem seleção: antes do rodapé');
  assert.equal(r1.doc.secoes.at(-1).tipo, 'rodape');
  assert.equal(r1.doc.secoes.at(-2).tipo, 'numeros');
  assert.equal(r1.doc.secoes.at(-2).opcao, 'faixa-clara', 'primeira opção');

  const r2 = op.adicionarSecao(doc, lib, 'clientes', 'faixa', 1);
  assert.equal(r2.indice, 2, 'logo depois da selecionada');
  assert.equal(r2.doc.secoes[2].tipo, 'clientes');

  const r3 = op.adicionarSecao(doc, lib, 'numeros', null, doc.secoes.length - 1);
  assert.equal(r3.doc.secoes.at(-1).tipo, 'rodape', 'rodapé selecionado: entra antes dele');

  const r4 = op.adicionarSecao(doc, lib, 'numeros', null, 0);
  assert.equal(r4.indice, 1, 'cabeçalho selecionado: entra logo depois dele');

  assert.equal(op.adicionarSecao(doc, lib, 'hero').indice, null, 'tipo repetido');
  assert.equal(op.adicionarSecao(doc, lib, 'inexistente').indice, null);
  assert.equal(op.adicionarSecao(doc, lib, 'numeros', 'nao-existe').doc.secoes.at(-2).opcao, 'faixa-clara');
});

test('seções: remover (textos ficam guardados) e trocar opção', () => {
  let doc = novoDoc();
  doc = { ...doc, textos: { 'faq.titulo': 'Dúvidas' } };
  const i = doc.secoes.findIndex((s) => s.tipo === 'faq');
  const sem = op.removerSecao(doc, lib, i);
  assert.ok(!tipos(sem).includes('faq'));
  assert.equal(sem.textos['faq.titulo'], 'Dúvidas', 'textos continuam no documento');
  const volta = op.adicionarSecao(sem, lib, 'faq').doc;
  assert.equal(textoEfetivo(volta, lib, 'faq.titulo'), 'Dúvidas');

  const h = doc.secoes.findIndex((s) => s.tipo === 'hero');
  const t = op.trocarOpcao(doc, lib, h, 'centralizado');
  assert.equal(t.secoes[h].opcao, 'centralizado');
  assert.equal(op.trocarOpcao(doc, lib, h, 'nao-existe'), doc);
  assert.equal(op.trocarOpcao(doc, lib, h, doc.secoes[h].opcao), doc);

  assert.deepEqual(op.opcaoVizinha(lib, 'hero', 'cards-flutuantes', 1), { id: 'fundo-cards', posicao: 2, total: 8 });
  assert.deepEqual(op.opcaoVizinha(lib, 'hero', 'cards-flutuantes', -1), { id: 'titulo-gigante', posicao: 8, total: 8 }, 'circular');
  assert.deepEqual(op.posicaoOpcao(lib, 'servicos', 'blocos'), { posicao: 4, total: 7 });
  assert.equal(op.nomeSecao(lib, 'hero'), 'Destaque');
  assert.equal(op.nomeOpcao(lib, 'hero', 'centralizado'), 'Centralizado');
});

test('seções: trocar modelo é Documento.aplicarModelo (textos ficam)', () => {
  const doc = { ...novoDoc(), textos: { 'hero.titulo': 'Meu título' } };
  const novo = op.trocarModelo(doc, lib, 'direto');
  assert.equal(novo.modelo, 'direto');
  assert.equal(novo.estilo.acabamento, 'direto');
  assert.equal(novo.textos['hero.titulo'], 'Meu título');
  assert.equal(op.trocarModelo(doc, lib, 'moderno'), doc, 'mesmo modelo: nada muda');
});

/* ------------------------------------------------------------------ listas */

test('listas: adicionar/remover respeitando repete; ids novos "n…"; ordem em doc.listas', () => {
  let doc = novoDoc();
  assert.equal(op.rotuloItem(lib, 'serv'), 'serviço');
  assert.equal(op.listaVariavel(lib, 'serv'), true);
  assert.equal(op.listaVariavel(lib, 'dif'), false, 'dif é 3–3');
  const inicial = itensLista(doc, lib, 'serv');
  assert.equal(inicial.length, 6, 'clínicas: 6 serviços padrão');

  const r = op.adicionarItem(doc, lib, 'serv', inicial[0]);
  assert.match(r.id, /^n[0-9a-z]{4}$/);
  assert.deepEqual(r.doc.listas.serv, [inicial[0], r.id, ...inicial.slice(1)], 'entra depois do item pedido');
  doc = r.doc;
  doc = op.adicionarItem(doc, lib, 'serv').doc;
  assert.equal(itensLista(doc, lib, 'serv').length, 8);
  assert.equal(op.podeAdicionarItem(doc, lib, 'serv'), false, 'máximo 8');
  const cheio = op.adicionarItem(doc, lib, 'serv');
  assert.equal(cheio.id, null);
  assert.equal(cheio.doc, doc);

  let menor = novoDoc();
  menor = op.removerItem(menor, lib, 'serv', '2');
  assert.deepEqual(menor.listas.serv, ['1', '3', '4', '5', '6']);
  menor = op.removerItem(op.removerItem(menor, lib, 'serv', '5'), lib, 'serv', '6');
  assert.deepEqual(menor.listas.serv, ['1', '3', '4']);
  assert.equal(op.podeRemoverItem(menor, lib, 'serv'), false, 'mínimo 3');
  assert.equal(op.removerItem(menor, lib, 'serv', '1'), menor);
  assert.equal(op.removerItem(menor, lib, 'serv', 'nada'), menor);
  assert.equal(textoEfetivo(menor, lib, 'serv.3.t'), textoEfetivo(novoDoc(), lib, 'serv.3.t'), 'item 3 continua o mesmo [M2]');

  const comTexto = { ...novoDoc(), textos: { 'serv.2.t': 'X' }, icones: { 'serv.2': 'tooth' }, imagens: { 'serv.2.img': 'm_1' } };
  const sem2 = op.removerItem(comTexto, lib, 'serv', '2');
  assert.equal(sem2.textos['serv.2.t'], undefined);
  assert.equal(sem2.icones['serv.2'], undefined);
  assert.equal(sem2.imagens['serv.2.img'], undefined);

  assert.deepEqual(op.listasDoTipo(lib, 'servicos'), ['serv']);
  assert.deepEqual(op.listasDoTipo(lib, 'sobre'), ['sobrel']);
});

/* ------------------------------------------------------------------ textos */

test('textos: corte no limite conta caracteres (não unidades UTF-16)', () => {
  assert.deepEqual(op.cortarNoLimite('abcdef', 4), { texto: 'abcd', cortado: true });
  assert.deepEqual(op.cortarNoLimite('abc', 4), { texto: 'abc', cortado: false });
  assert.deepEqual(op.cortarNoLimite('😀😀😀', 2), { texto: '😀😀', cortado: true });
  assert.equal(op.contarCaracteres('ação😀'), 5);
  assert.equal(op.linhaUnica('a\nb\r\nc\td'), 'a b c d');
});

test('textos: colar no meio da seleção corta no que sobra do limite', () => {
  const r = op.inserirNoLimite('Olá mundo', 4, 9, 'Brasil\ninteiro', 12);
  assert.deepEqual(r, { texto: 'Olá Brasil i', cursor: 12, cortado: true, inserido: 'Brasil i' });
  const s = op.inserirNoLimite('abc', 3, 3, 'de', 10);
  assert.deepEqual(s, { texto: 'abcde', cursor: 5, cortado: false, inserido: 'de' });
  const cheio = op.inserirNoLimite('abcd', 4, 4, 'x', 4);
  assert.equal(cheio.texto, 'abcd');
  assert.equal(cheio.cortado, true);
  assert.equal(cheio.inserido, '');
  const semLimite = op.inserirNoLimite('a', 0, 1, 'b\nc', 0);
  assert.equal(semLimite.texto, 'b c');
});

test('textos: contador aparece a partir de 80% e marca o limite', () => {
  assert.deepEqual(op.estadoContador(31, 40), { mostrar: false, limite: false, texto: '31/40' });
  assert.deepEqual(op.estadoContador(32, 40), { mostrar: true, limite: false, texto: '32/40' });
  assert.deepEqual(op.estadoContador(40, 40), { mostrar: true, limite: true, texto: '40/40' });
  assert.equal(op.estadoContador(5, 0).mostrar, false);
  assert.equal(op.limiteDaChave(lib, 'hero.titulo'), 90);
  assert.equal(op.limiteDaChave(lib, 'serv.nk3f.t'), 40);
  assert.equal(op.limiteDaChave(lib, 'nada.x'), 0);
});

test('textos: fluxo de confirmação — retokeniza, guarda a caixa original, remove se igual ao padrão', () => {
  const doc = novoDoc();
  // Caixa alta é só CSS: o documento guarda o que foi digitado (lido por textContent).
  const a = op.confirmarTexto(doc, lib, 'hero.eyebrow', 'Clínica odontológica em Jundiaí desde 2010');
  assert.equal(a.doc.textos['hero.eyebrow'], 'Clínica odontológica em {cidade} desde 2010', 'cidade vira {cidade}');
  assert.equal(a.restaurado, false);
  assert.equal(a.texto, 'Clínica odontológica em Jundiaí desde 2010');

  const b = op.confirmarTexto(doc, lib, 'hero.cta', `  ${textoEfetivo(doc, lib, 'hero.cta')}  `);
  assert.equal('hero.cta' in b.doc.textos, false, 'igual ao padrão (aparado) → chave removida');

  const nome = op.confirmarTexto(doc, lib, 'cta.titulo', 'Agende na Clínica Sorriso Vivo');
  assert.equal(nome.doc.textos['cta.titulo'], 'Agende na {nome}');

  const editado = { ...doc, textos: { 'hero.titulo': 'Antigo' } };
  const c = op.confirmarTexto(editado, lib, 'hero.titulo', '   ');
  assert.equal(c.restaurado, true, 'apagado → volta ao padrão');
  assert.equal('hero.titulo' in c.doc.textos, false);
  assert.equal(c.texto, textoEfetivo(doc, lib, 'hero.titulo'));
});

test('textos: durante a digitação o vazio fica vazio (Desfazer da restauração volta a ele)', () => {
  const doc = novoDoc();
  const vazio = op.textoDigitado(doc, lib, 'hero.titulo', '');
  assert.equal(vazio.textos['hero.titulo'], '');
  assert.equal(textoEfetivo(vazio, lib, 'hero.titulo'), '');
  const meio = op.textoDigitado(doc, lib, 'hero.titulo', 'Sorrisos em Jundiaí\n');
  assert.equal(meio.textos['hero.titulo'], 'Sorrisos em {cidade}');
  const restaurado = op.confirmarTexto(vazio, lib, 'hero.titulo', '');
  assert.equal(restaurado.restaurado, true);
  assert.equal('hero.titulo' in restaurado.doc.textos, false);
});

/* ------------------------------------------------------------------ ícones e imagens */

test('ícones: categoria do negócio primeiro, busca sem acento, grava/remove a escolha', () => {
  const doc = novoDoc();
  assert.equal(op.categoriaIcones(doc, lib), 'saude');
  const todos = op.listarIcones(lib, { categoria: 'saude' });
  assert.equal(todos[0].categoria, 'saude');
  const primeiroOutro = todos.findIndex((i) => i.categoria !== 'saude');
  assert.ok(todos.slice(primeiroOutro).every((i) => i.categoria !== 'saude'), 'saúde toda antes');
  assert.equal(todos[primeiroOutro].categoria, 'geral', 'depois os gerais');
  const dente = op.listarIcones(lib, { categoria: 'juridico', busca: 'DENTE' });
  assert.ok(dente.some((i) => i.id === 'tooth'));
  assert.equal(op.listarIcones(lib, { busca: 'zzzz nada' }).length, 0);
  // a busca casa começo de palavra: "dente" não acha "acidente" nem "independente"
  for (const i of dente) {
    const palavras = [i.id, i.nome, ...(i.palavras ?? [])].join(' ').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().split(/[^a-z0-9]+/);
    assert.ok(palavras.some((p) => p.startsWith('dente')), `${i.id} casa por começo de palavra`);
  }

  const com = op.definirIcone(doc, 'serv.1', 'tooth');
  assert.equal(com.icones['serv.1'], 'tooth');
  assert.equal(op.definirIcone(com, 'serv.1', 'tooth'), com);
  const auto = op.definirIcone(com, 'serv.1', null);
  assert.equal('serv.1' in auto.icones, false, 'Automático remove a chave');
  assert.equal(op.definirIcone(auto, 'serv.1', null), auto);

  // Iconify: o desenho vai para iconesExtras e sai quando nenhum item usa mais.
  const svg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M1 1"/></svg>';
  const comExtra = op.definirIcone(auto, 'serv.1', 'mdi:tooth', { nome: 'tooth', svg: { fino: svg } });
  assert.equal(comExtra.icones['serv.1'], 'mdi:tooth');
  assert.deepEqual(comExtra.iconesExtras, { 'mdi:tooth': { nome: 'tooth', svg: { fino: svg } } });
  const dois = op.definirIcone(comExtra, 'serv.2', 'mdi:tooth');
  assert.deepEqual(Object.keys(dois.iconesExtras), ['mdi:tooth'], 'segundo item reaproveita o desenho');
  const troca = op.definirIcone(dois, 'serv.1', 'tooth');
  assert.deepEqual(Object.keys(troca.iconesExtras), ['mdi:tooth'], 'ainda usado no serv.2');
  const nenhum = op.definirIcone(troca, 'serv.2', null);
  assert.deepEqual(nenhum.iconesExtras, {}, 'ninguém usa: sai do documento');
  assert.deepEqual(op.definirIcone(auto, 'serv.1', 'Ruim:X', { svg: { fino: svg } }).iconesExtras, {}, 'id inválido não guarda desenho');
});

test('imagens: liga/desliga mídia; redução para 2400 px; HEIC com mensagem clara', () => {
  const doc = novoDoc();
  const com = op.definirImagem(doc, 'hero.img', 'm_0a1b2c3d');
  assert.equal(com.imagens['hero.img'], 'm_0a1b2c3d');
  assert.equal('hero.img' in op.definirImagem(com, 'hero.img', null).imagens, false);
  assert.deepEqual(op.dimensoesReduzidas(4800, 3600), { largura: 2400, altura: 1800 });
  assert.deepEqual(op.dimensoesReduzidas(3000, 4000), { largura: 1800, altura: 2400 });
  assert.deepEqual(op.dimensoesReduzidas(800, 600), { largura: 800, altura: 600 }, 'nunca amplia');
  assert.match(op.problemaArquivoFoto({ name: 'IMG_0001.HEIC', type: '', size: 10 }), /HEIC/);
  assert.match(op.problemaArquivoFoto({ name: 'a.pdf', type: 'application/pdf', size: 10 }), /não é uma imagem/);
  assert.equal(op.problemaArquivoFoto({ name: 'a.jpg', type: 'image/jpeg', size: 10 }), null);
});

/* ------------------------------------------------------------------ dados, SEO e rastreamento */

test('dados: CEP, ViaCEP tolerante, horários, redes', () => {
  assert.equal(op.mascaraCep('13201000'), '13201-000');
  assert.equal(op.mascaraCep('132'), '132');
  assert.equal(op.cepCompleto('13201-000'), true);
  assert.deepEqual(op.enderecoDoViaCep({ cep: '13201-000', logradouro: 'Rua X', bairro: 'Centro', localidade: 'Jundiaí', uf: 'SP' }),
    { logradouro: 'Rua X', bairro: 'Centro', cidade: 'Jundiaí', uf: 'SP' });
  assert.equal(op.enderecoDoViaCep({ erro: true }), null);
  assert.equal(op.enderecoDoViaCep({ erro: 'true' }), null);
  assert.equal(op.enderecoDoViaCep('lixo'), null);

  const h = op.copiarSegundaParaUteis({ dom: null, seg: ['08:00', '18:00'], sab: ['08:00', '12:00'] });
  assert.deepEqual(Object.keys(h), ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom']);
  assert.deepEqual(h.qui, ['08:00', '18:00']);
  assert.deepEqual(h.sab, ['08:00', '12:00'], 'sábado não muda');
  assert.equal(op.problemaHorario(['18:00', '08:00']) !== null, true);
  assert.equal(op.problemaHorario(['08:00', '18:00']), null);
  assert.equal(op.problemaHorario(null), null);

  assert.equal(op.normalizarRede('instagram', '@clinica'), 'https://www.instagram.com/clinica');
  assert.equal(op.normalizarRede('facebook', 'http://facebook.com/x'), 'https://facebook.com/x');
  assert.equal(op.problemaRede('google', 'nada disso'), 'Use o endereço completo do perfil, começando com https://');
  assert.equal(op.problemaRede('google', 'https://g.page/x'), null);
  assert.equal(op.problemaEmail('a@b'), 'Confira o e-mail (ex.: contato@empresa.com.br).');
  assert.equal(op.problemaEmail(''), null);
});

test('rastreamento e SEO: mesmas regras do servidor', () => {
  assert.equal(op.problemaRastreamento('gtm', ' gtm-abc123 '), null);
  assert.match(op.problemaRastreamento('gtm', 'GTM'), /GTM-XXXXXXX/);
  assert.equal(op.problemaRastreamento('ga4', 'G-ABCD1234'), null);
  assert.match(op.problemaRastreamento('ga4', 'UA-1234'), /G-XXXX/);
  assert.equal(op.problemaRastreamento('metaPixel', '123456789012345'), null);
  assert.match(op.problemaRastreamento('metaPixel', '12ab'), /só números/);
  assert.equal(op.normalizarRastreamento(' gtm-x1 '), 'GTM-X1');

  const doc = novoDoc();
  assert.equal(op.tituloSeoPadrao(doc, lib), 'Clínica Sorriso Vivo · Dentista em Jundiaí');
  const desc = op.descricaoSeoPadrao(doc, lib);
  assert.ok([...desc].length <= 156);
  const proprio = op.seoEfetivo({ ...doc, seo: { titulo: '{nome} em {cidade}', descricao: null } }, lib);
  assert.equal(proprio.titulo, 'Clínica Sorriso Vivo em Jundiaí');
  assert.equal(op.cortarSemQuebrar('palavra '.repeat(40), 20), 'palavra palavra…');
});

/* ------------------------------------------------------------------ publicação */

test('publicação: checklist separa bloqueios, alegações confirmáveis, avisos e textos', () => {
  const r = op.organizarValidacao({
    erros: [
      { codigo: 'registro_obrigatorio', mensagem: 'x', chave: 'dados.registro.numero' },
      { codigo: 'alegacao_padrao', grupo: 'num', confirmavel: true, mensagem: 'n', secao: 1, chave: 'num.1.v' },
      { codigo: 'alegacao_padrao', grupo: 'dep', confirmavel: false, mensagem: 'd', secao: 7 },
      { codigo: 'alegacao_padrao', grupo: 'dep', confirmavel: true, mensagem: 'd2', secao: 7 },
    ],
    avisos: [{ codigo: 'foto_vazia', mensagem: 'a' }],
    textosPadraoAlterados: [{ chave: 'hero.titulo', antes: 'A', depois: 'B' }],
  });
  assert.equal(r.bloqueios.length, 3, 'depoimento de exemplo sempre bloqueia');
  assert.deepEqual(r.alegacoes.map((a) => a.grupo), ['num']);
  assert.equal(r.avisos.length, 1);
  assert.equal(r.textos.length, 1);
  assert.equal(op.podePublicar({ ...r, bloqueios: [] }, ['num']), true);
  assert.equal(op.podePublicar({ ...r, bloqueios: [] }, []), false);
  assert.equal(op.podePublicar(r, ['num']), false);

  const doc = novoDoc();
  const conf = op.confirmarAlegacoes(doc, ['num', 'dep', 'num', 'aval']);
  assert.deepEqual(conf.confirmados, ['num', 'aval'], 'dep nunca é confirmado');
  assert.equal(op.confirmarAlegacoes(conf, ['num', 'dep']), conf);

  assert.equal(op.textoConfirmacao('aval'), 'Confirmo que a nota do Google é verdadeira');
  assert.match(op.textoConfirmacao('x'), /^Confirmo que/);
  assert.deepEqual(op.destinoDoProblema({ chave: 'dados.registro.numero' }), { aba: 'dados', campo: 'dados.registro.numero' });
  assert.deepEqual(op.destinoDoProblema({ chave: 'rastreamento.gtm' }), { aba: 'config', campo: 'rastreamento.gtm' });
  assert.deepEqual(op.destinoDoProblema({ secao: 3, chave: 'num.1.v' }), { secao: 3, chave: 'num.1.v' });
  assert.deepEqual(op.destinoDoProblema({ secao: 0 }), { secao: 0, chave: null });
  assert.equal(op.destinoDoProblema({}), null);
});

test('atalhos de desfazer/refazer', () => {
  assert.equal(op.atalhoHistorico({ ctrlKey: true, key: 'z' }), 'desfazer');
  assert.equal(op.atalhoHistorico({ metaKey: true, key: 'Z', shiftKey: true }), 'refazer');
  assert.equal(op.atalhoHistorico({ ctrlKey: true, key: 'y' }), 'refazer');
  assert.equal(op.atalhoHistorico({ key: 'z' }), null);
  assert.equal(op.atalhoHistorico({ ctrlKey: true, altKey: true, key: 'z' }), null);
});
