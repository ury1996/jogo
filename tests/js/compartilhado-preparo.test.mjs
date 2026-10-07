import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import Mustache from '../../public_html/editor/js/vendor/mustache.mjs';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';
import { DADOS_COMPLETOS } from '../paridade/casos.mjs';
import { midiaDeTeste } from '../paridade/funcoes.mjs';
import { criarDocumento } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { imagemHtml, logoHtml, montarDados, prepararSite } from '../../public_html/editor/js/compartilhado/preparo.mjs';

const dir = (rel) => fileURLToPath(new URL(rel, import.meta.url));
const lib = carregarBiblioteca(dir('../fixtures/biblioteca-mini'));
const midia = midiaDeTeste();
const PUBLICAR = { modo: 'publicar', urlMidia: 'img/{id}-{w}.{ext}', ano: 2026, midia };
const copia = (x) => JSON.parse(JSON.stringify(x));
const libCom = (tipo, opcao, template) => {
  const l = copia(lib);
  l.secoes[tipo].templates[opcao] = template;
  return l;
};
const docBase = (extra = {}) => criarDocumento({ nicho: 'clinicas', modelo: 'moderno', dados: { nome: 'Odonto Mais', cidade: 'Campinas', uf: 'SP', whatsapp: '(19) 99876-5432' }, ...extra }, lib);

test('instantâneo simples (o mesmo do PreparoTest.php)', () => {
  const libMini = JSON.parse(fs.readFileSync(dir('../paridade/lib-instantaneo.json'), 'utf8'));
  const esperado = fs.readFileSync(dir('../paridade/instantaneo.html'), 'utf8');
  const doc = criarDocumento({ nicho: 'n', modelo: 'm', dados: {} }, libMini);
  doc.imagens = { 'hero.img': 'm_00000001' };
  const r = prepararSite(doc, libMini, { modo: 'publicar', ano: 2026, midia: { m_00000001: { largura: 2000, altura: 1000, variantes: [480, 960, 1600] } } });
  assert.equal(r.html, esperado);
  assert.equal(r.classesRaiz, 'rk k-direto f-moderna');
  assert.match(r.cssPaleta, /^\.rk\{--p:#2e6fd1;--on-p:#ffffff;/);
  assert.deepEqual(r.secoes.map(({ html, ...s }) => s), [
    { indice: 0, tipo: 'header', opcao: 'a', fundo: 'branco', ancora: 'topo' },
    { indice: 1, tipo: 'hero', opcao: 'a', fundo: 'tom', ancora: 'inicio' },
    { indice: 2, tipo: 'servicos', opcao: 'a', fundo: 'branco', ancora: 'servicos' },
  ]);
  assert.equal(r.alvoPular, 'inicio');
  assert.deepEqual(r.avisos, []);
});

test('imagemHtml: atributos na ordem do §5.5, só variantes existentes, maior ≤ 960 no src', () => {
  const op = PUBLICAR;
  assert.equal(
    imagemHtml({ chave: 'hero.img', midiaId: 'm_00000001', rotulo: 'Foto', alt: 'Alt', sizes: '50vw', lcp: false }, op).html,
    '<img src="img/m_00000001-960.webp" srcset="img/m_00000001-480.webp 480w, img/m_00000001-960.webp 960w, img/m_00000001-1600.webp 1600w" sizes="50vw" width="1600" height="1067" alt="Alt" loading="lazy" decoding="async">',
  );
  assert.equal(
    imagemHtml({ midiaId: 'm_00000002', alt: 'ignorado', lcp: true }, op).html,
    '<img src="img/m_00000002-960.webp" srcset="img/m_00000002-480.webp 480w, img/m_00000002-960.webp 960w, img/m_00000002-1200.webp 1200w" sizes="100vw" width="1200" height="900" alt="Recepção &quot;nova&quot; &amp; &lt;ampla&gt;" loading="eager" decoding="async" fetchpriority="high">',
    'midia.alt tem prioridade; variantes ordenadas; lcp',
  );
  assert.equal(imagemHtml({ midiaId: 'm_00000003', alt: 'A' }, op).html,
    '<img src="img/m_00000003-400.webp" srcset="img/m_00000003-400.webp 400w" sizes="100vw" width="400" height="300" alt="A" loading="lazy" decoding="async">',
    'só variante menor que 960: a menor no src');
  assert.equal(imagemHtml({ midiaId: 'm_00000004', alt: '' }, op).html.includes('width="1001" height="667"'), true);
  assert.equal(imagemHtml({ midiaId: 'm_00000005', alt: 'S' }, op).html,
    '<img src="img/m_00000005-orig.svg" width="800" height="800" alt="S" loading="lazy" decoding="async">');
  assert.deepEqual(imagemHtml({ midiaId: null, rotulo: 'Foto & equipe' }, op), { html: '<span class="rk-foto__vazio" aria-hidden="true"></span>', vazio: true });
  assert.deepEqual(imagemHtml({ midiaId: 'm_ffffffff', rotulo: 'Foto & equipe' }, { modo: 'editor', midia }), { html: '<span class="rk-foto__vazio">Foto &amp; equipe · enviar</span>', vazio: true });
  assert.equal(imagemHtml({ midiaId: 'm_00000001', alt: 'A' }, { modo: 'editor', midia }).html.startsWith('<img src="/api/media/m_00000001/960" srcset="/api/media/m_00000001/480 480w'), true);
  assert.equal(imagemHtml({ midiaId: 'm_0000000d', alt: 'A' }, { modo: 'editor', midia }).html,
    '<img src="blob:http://x/1" width="1600" height="1200" alt="A" loading="lazy" decoding="async">', 'prévia local sem srcset');
});

test('logoHtml: raster 320/640, SVG original, inicial sem logo', () => {
  assert.deepEqual(logoHtml({ midiaId: 'm_0000000a', nome: 'A & B', inicial: 'A' }, PUBLICAR), {
    html: '<img class="rk-logo__img" src="img/m_0000000a-320.webp" srcset="img/m_0000000a-320.webp 1x, img/m_0000000a-640.webp 2x" width="320" height="80" alt="A &amp; B">',
    temLogo: true,
  });
  assert.equal(logoHtml({ midiaId: 'm_0000000c', nome: 'N' }, PUBLICAR).html,
    '<img class="rk-logo__img" src="img/m_0000000c-300.webp" srcset="img/m_0000000c-300.webp 1x" width="300" height="91" alt="N">');
  assert.equal(logoHtml({ midiaId: 'm_0000000b', nome: 'N' }, PUBLICAR).html,
    '<img class="rk-logo__img" src="img/m_0000000b-orig.svg" width="120" height="40" alt="N">');
  assert.deepEqual(logoHtml({ midiaId: null, nome: 'N', inicial: '<' }, PUBLICAR), { html: '<span class="rk-logo__ini" aria-hidden="true">&lt;</span>', temLogo: false });
});

test('invólucro, fundos alternados, header com id "topo", alvo do "pular" e botão flutuante', () => {
  const r = prepararSite(docBase(), lib, PUBLICAR);
  assert.match(r.html, /^<div class="rk-sec rk-sec--header rk-op--header-simples rk-bg--branco" id="topo" data-sec="0">/);
  assert.deepEqual(r.secoes.map((s) => s.fundo), ['branco', 'tom', 'branco', 'tom', 'escuro']);
  assert.equal(r.alvoPular, 'inicio');
  assert.ok(r.html.endsWith('aria-label="Conversar no WhatsApp">' + lib.icones.utilitarios.whatsapp.svg.duotone + '</a>'));
  assert.equal(r.html.split('\n<div class="rk-sec ').length, 5);
  const sem = docBase();
  sem.estilo.whatsappFlutuante = false;
  assert.equal(prepararSite(sem, lib, PUBLICAR).html.includes('rk-wa'), false);
  const invalido = docBase({ dados: { whatsapp: '123' } });
  assert.equal(prepararSite(invalido, lib, PUBLICAR).html.includes('class="rk-wa"'), false, 'WhatsApp inválido: sem botão');
  assert.equal(r.classesRaiz, 'rk k-moderno f-editorial');
});

test('lcp só na primeira seção depois do header; sizes vêm da opção da seção', () => {
  const doc = docBase();
  doc.imagens = { 'hero.img': 'm_00000001', 'serv.1.img': 'm_00000001', 'faq.img': 'm_00000002' };
  doc.secoes = [{ tipo: 'header', opcao: 'simples' }, { tipo: 'hero', opcao: 'cards-flutuantes' }, { tipo: 'servicos', opcao: 'cards' }, { tipo: 'faq', opcao: 'foto-ajuda' }];
  const r = prepararSite(doc, lib, PUBLICAR);
  const [, hero, serv, faq] = r.secoes.map((s) => s.html);
  assert.match(hero, /sizes="\(max-width: 760px\) 100vw, 50vw"[^>]*loading="eager" decoding="async" fetchpriority="high"/);
  assert.match(serv, /sizes="\(max-width: 760px\) 100vw, 33vw"[^>]*alt="Ortodontia — Odonto Mais" loading="lazy" decoding="async">/);
  assert.match(faq, /sizes="\(max-width: 760px\) 100vw, 40vw"[^>]*loading="lazy"/);
  const semHeader = { ...doc, secoes: doc.secoes.slice(2) };
  assert.match(prepararSite(semHeader, lib, PUBLICAR).secoes[0].html, /fetchpriority="high"/, 'sem header: a primeira seção é a do LCP');
});

test('menu: ordem da página, rótulo do nicho, só seções com menu, no máximo 5', () => {
  const r = prepararSite(docBase(), lib, PUBLICAR);
  assert.match(r.secoes[0].html, /<li><a href="#servicos">Tratamentos<\/a><\/li>\s*<li><a href="#perguntas">Dúvidas<\/a><\/li>/);
  const muitos = copia(lib);
  for (const t of ['a1', 'a2', 'a3', 'a4', 'a5', 'a6']) {
    muitos.secoes[t] = { manifest: { tipo: t, ancora: t, menu: t.toUpperCase(), opcoes: [{ id: 'x', tom: 'claro' }] }, templates: { x: '' }, css: '' };
  }
  muitos.secoes.header.templates.simples = '{{#menu}}[{{rotulo}}]{{/menu}}';
  const doc = docBase();
  doc.secoes = [{ tipo: 'header', opcao: 'simples' }, ...['a1', 'a2', 'a3', 'a4', 'a5', 'a6'].map((tipo) => ({ tipo, opcao: 'x' }))];
  assert.match(prepararSite(doc, muitos, PUBLICAR).secoes[0].html, />\[A1\]\[A2\]\[A3\]\[A4\]\[A5\]<\/div>$/);
});

test('view: todos os grupos/listas de todos os manifestos em c, itens completos, p1..p9, qtd/tem, apelidos', () => {
  const template = '{{c.serv.titulo}}|{{c.faq.qtd}}|{{#c.faq.tem}}T{{/c.faq.tem}}|{{c.faq.p2.q}}|{{c.faq.p9.q}}|{{#c.serv.p1}}{{k}}:{{i}}:{{primeiro}}:{{ultimo}}:{{par}}:{{img.vazio}}:{{ic.id}}{{/c.serv.p1}}|{{c.hero.img.vazio}}|{{c.rodape.texto}}|{{conteudo.serv.p2.t}}|{{s.tipo}}/{{s.opcao}}/{{s.fundo}}/{{s.indice}}|{{e.acabamento}}{{#e.moderno}}!{{/e.moderno}}|{{#modo.publicar}}P{{/modo.publicar}}';
  const r = prepararSite(docBase(), libCom('hero', 'cards-flutuantes', template), PUBLICAR);
  assert.equal(r.secoes[1].html.replace(/^<div[^>]*>|<\/div>$/g, ''),
    'Tudo o que o seu sorriso precisa na Odonto Mais|4|T|A primeira consulta é paga?||serv.1:1:true:false:false:true:aparelho|true|Todos os direitos reservados.|Implantes|hero/cards-flutuantes/tom/1|moderno!|P');
  const vazio = docBase();
  vazio.listas = { serv: [], faq: [] };
  const r2 = prepararSite(vazio, libCom('hero', 'cards-flutuantes', '{{c.serv.qtd}}{{^c.serv.tem}}vazia{{/c.serv.tem}}{{#c.serv.p1}}X{{/c.serv.p1}}'), PUBLICAR);
  assert.equal(r2.secoes[1].html.replace(/^<div[^>]*>|<\/div>$/g, ''), '0vazia');
  const longo = docBase();
  longo.listas = { serv: ['1', '2', '3', '4', 'n1', 'n2', 'n3', 'n4', 'n5', 'n6'] };
  const r3 = prepararSite(longo, libCom('hero', 'cards-flutuantes', '{{c.serv.qtd}}:{{#c.serv.itens}}{{id}},{{/c.serv.itens}}:{{#c.serv.p8}}{{ultimo}}{{/c.serv.p8}}'), PUBLICAR);
  assert.equal(r3.secoes[1].html.replace(/^<div[^>]*>|<\/div>$/g, ''), '8:1,2,3,4,n1,n2,n3,n4,:true', 'corta no máximo do manifesto');
});

test('busca de nomes igual ao mustache.php: campo "d" do item esconde a raiz; use "dados"', () => {
  const t = '{{#c.serv.p1}}[{{d.whatsappLink}}][{{dados.whatsappLink}}][{{c.hero.cta}}]{{/c.serv.p1}}';
  const r = prepararSite(docBase(), libCom('hero', 'cards-flutuantes', t), PUBLICAR);
  assert.equal(r.secoes[1].html.replace(/^<div[^>]*>|<\/div>$/g, ''),
    '[][https://wa.me/5519998765432?text=Ol%C3%A1!%20Vi%20o%20site%20e%20gostaria%20de%20agendar%20uma%20avalia%C3%A7%C3%A3o.][Agendar avaliação]');
  assert.equal(Mustache.render('{{a}}', { a: '/ ` = &' }), '/ ` = &amp;', 'Mustache.escape sobrescrito');
});

test('d: dados derivados formatados', () => {
  const doc = criarDocumento({ nicho: 'clinicas', modelo: 'classico', dados: DADOS_COMPLETOS }, lib);
  const d = montarDados(doc, lib, PUBLICAR);
  assert.equal(d.nome, 'Clínica & Cia <Teste> "Aspas" \'Simples\'');
  assert.equal(d.cidadeUf, 'São José dos Campos - SP');
  assert.equal(d.inicial, 'C');
  assert.equal(d.whatsapp, '(12) 99876-5432');
  assert.equal(d.whatsappLink, 'https://wa.me/5512998765432?text=Ol%C3%A1!%20Vi%20o%20site%20e%20gostaria%20de%20agendar%20uma%20avalia%C3%A7%C3%A3o.');
  assert.equal(d.temWhatsapp, true);
  assert.equal(d.telefone, '(12) 3456-7890');
  assert.equal(d.telefoneLink, 'tel:+551234567890');
  assert.equal(d.emailLink, 'mailto:contato@clinica.com.br');
  assert.equal(d.endereco, 'Av. São João, 1.234 - Sala 5 & 6 - Jardim Esplanada, São José dos Campos - SP, CEP 12245-000');
  assert.deepEqual(d.enderecoLinhas, ['Av. São João, 1.234 - Sala 5 & 6', 'Jardim Esplanada, São José dos Campos - SP, CEP 12245-000']);
  assert.equal(d.mapaLink, `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.endereco)}`);
  assert.equal(d.mapaEmbed, `https://www.google.com/maps?q=${encodeURIComponent(d.endereco)}&output=embed`);
  assert.deepEqual(d.horarios, [{ dias: 'Seg a Qui', horas: '8h às 18h' }, { dias: 'Sex', horas: '8h às 17h30' }, { dias: 'Sáb', horas: '8h30 às 12h' }, { dias: 'Dom', horas: 'Fechado' }]);
  assert.equal(d.registro, 'Responsável técnico: Dra. Ana Lima · CRO-SP 12345');
  assert.deepEqual(d.redes.map((x) => x.rede), ['instagram', 'facebook', 'youtube', 'google']);
  assert.equal(d.logo.temLogo, true);
  assert.equal(d.ano, 2026);
  assert.equal(d.segmento, 'Dentista');
  const vazio = montarDados(criarDocumento({ nicho: 'clinicas', modelo: 'classico' }, lib), lib, {});
  assert.equal(vazio.nome, 'Clínica Sorriso Vivo', 'exemplo do nicho');
  assert.equal(vazio.cidadeUf, 'Jundiaí - SP');
  assert.equal(vazio.whatsapp, '(11) 98765-4321');
  assert.equal(vazio.temEndereco, false);
  assert.equal(vazio.mapaLink, 'https://www.google.com/maps/search/?api=1&query=Jundia%C3%AD%20-%20SP');
  assert.equal(vazio.temHorarios, false);
  assert.equal(vazio.temRegistro, false);
  assert.equal(vazio.temRedes, false);
  assert.equal(vazio.ano, null);
});

test('robustez: nunca lança; pula com aviso', () => {
  const doc = docBase();
  doc.secoes = [{ tipo: 'nao-existe', opcao: 'x' }, { tipo: 'hero', opcao: 'nao-existe' }, { tipo: 'header', opcao: 'simples' }, { tipo: 'header', opcao: 'barra' }, { tipo: 'faq', opcao: 'centralizada' }];
  doc.estilo.cor = 'azul';
  const semTemplate = copia(lib);
  delete semTemplate.secoes.faq.templates.centralizada;
  const r = prepararSite(doc, semTemplate, PUBLICAR);
  assert.deepEqual(r.avisos.map((a) => [a.codigo, a.indice ?? null]), [
    ['cor_invalida', null], ['secao_desconhecida', 0], ['opcao_desconhecida', 1], ['secao_duplicada', 3], ['template_ausente', 4],
  ]);
  assert.deepEqual(r.secoes.map((s) => s.indice), [2]);
  const quebrado = prepararSite(docBase(), libCom('hero', 'cards-flutuantes', '{{#aberta}}sem fechar'), PUBLICAR);
  assert.equal(quebrado.avisos[0].codigo, 'erro_template');
  assert.equal(quebrado.secoes.some((s) => s.tipo === 'hero'), false);
  for (const [d, l] of [[null, null], [{}, lib], [docBase(), {}], ['x', 5], [{ secoes: 'x', textos: 3, dados: [] }, lib]]) {
    const x = prepararSite(d, l, null);
    assert.equal(typeof x.html, 'string');
    assert.equal(typeof x.cssPaleta, 'string');
  }
  assert.equal(prepararSite({ ...docBase(), nicho: 'x' }, lib, PUBLICAR).avisos[0].codigo, 'nicho_desconhecido');
});

test('s.escuro: escuro sempre; cor só quando o texto sobre a cor é branco', () => {
  const t = '{{#s.escuro}}E{{/s.escuro}}{{^s.escuro}}C{{/s.escuro}}';
  const comCor = (cor) => {
    const doc = docBase();
    doc.estilo.cor = cor;
    doc.secoes = [{ tipo: 'hero', opcao: 'formulario' }];
    return prepararSite(doc, libCom('hero', 'formulario', t), PUBLICAR).secoes[0].html.replace(/^<div[^>]*>|<\/div>$/g, '');
  };
  assert.equal(comCor('#1b2a4a'), 'E');
  assert.equal(comCor('#f5d90a'), 'C');
});

test('u.* vem dos utilitários mesmo se a lista principal tiver um ícone com o mesmo id', () => {
  const l = libCom('hero', 'cards-flutuantes', '{{{u.relogio}}}|{{#c.serv.p1}}{{ic.id}}={{{ic.svg}}}{{/c.serv.p1}}');
  l.icones.icones.find((i) => i.id === 'relogio').svg = { fino: '<svg>lista</svg>', duotone: '<svg>lista</svg>', preenchido: '<svg>lista</svg>' };
  const doc = docBase();
  doc.icones = { 'serv.1': 'relogio' };
  const html = prepararSite(doc, l, PUBLICAR).secoes[1].html.replace(/^<div[^>]*>|<\/div>$/g, '');
  assert.equal(html, `${lib.icones.utilitarios.relogio.svg.duotone}|relogio=<svg>lista</svg>`);
});
