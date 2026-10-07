// Testes das funções puras de public_html/editor/js/ui.mjs, biblioteca.mjs e previa.mjs:
// máscara e validação de WhatsApp, cor dominante do logo, tempo relativo, arquivo de logo,
// CSS dos sites no editor (fontes via /api/fontes) e paleta escopada por prévia.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import {
  mascaraTelefone, problemaWhatsapp, corDominante, tempoRelativo, problemaArquivoLogo, corClaraDemais,
  formatarTamanho, CORES_SUGERIDAS, UFS,
} from '../../public_html/editor/js/ui.mjs';
import { cssSite, cssFontes, nichosOrdenados, modelosOrdenados, tiposOrdenados } from '../../public_html/editor/js/biblioteca.mjs';
import { paletaEscopada, opcoesPreparo } from '../../public_html/editor/js/previa.mjs';
import { validarWhatsapp, formatarTelefone } from '../../public_html/editor/js/compartilhado/dados.mjs';
import { carregarBiblioteca } from '../paridade/carregar-biblioteca.mjs';

const dir = (rel) => fileURLToPath(new URL(rel, import.meta.url));

/* ------------------------------------------------------------------ WhatsApp */

test('máscara progressiva de celular: (11) 98765-4321', () => {
  const passos = ['1', '11', '119', '11987', '119876', '1198765', '11987654', '119876543', '1198765432', '11987654321'];
  assert.deepEqual(passos.map(mascaraTelefone), [
    '(1', '(11', '(11) 9', '(11) 987', '(11) 9876', '(11) 98765', '(11) 98765-4', '(11) 98765-43', '(11) 98765-432', '(11) 98765-4321',
  ]);
});

test('máscara de fixo: (11) 3456-7890, sem reorganizar enquanto digita', () => {
  assert.equal(mascaraTelefone('113456'), '(11) 3456');
  assert.equal(mascaraTelefone('1134567'), '(11) 3456-7');
  assert.equal(mascaraTelefone('1134567890'), '(11) 3456-7890');
});

test('máscara aceita colar com +55, zero de operadora, letras e excesso', () => {
  assert.equal(mascaraTelefone('+55 (11) 98765-4321'), '(11) 98765-4321');
  assert.equal(mascaraTelefone('011 98765 4321'), '(11) 98765-4321');
  assert.equal(mascaraTelefone('tel: 11 3456 7890'), '(11) 3456-7890');
  assert.equal(mascaraTelefone('119876543219999'), '(11) 98765-4321');
  assert.equal(mascaraTelefone(''), '');
  assert.equal(mascaraTelefone('abc'), '');
  // DDD 55 (RS) não é confundido com o código do país.
  assert.equal(mascaraTelefone('55999998888'), '(55) 99999-8888');
});

test('validação de WhatsApp: DDD, tamanho e primeiro dígito, com mensagens claras', () => {
  assert.equal(problemaWhatsapp(''), null, 'vazio é permitido (usa o exemplo)');
  assert.equal(problemaWhatsapp('(11) 98765-4321'), null);
  assert.equal(problemaWhatsapp('(11) 3456-7890'), null);
  assert.equal(problemaWhatsapp('(55) 99999-8888'), null);
  assert.match(problemaWhatsapp('(11) 9876'), /completo com DDD/);
  assert.match(problemaWhatsapp('(20) 98765-4321'), /DDD 20 não existe/);
  assert.match(problemaWhatsapp('(10) 3456-7890'), /DDD 10 não existe/);
  assert.match(problemaWhatsapp('(11) 88765-4321'), /começa com 9/);
  assert.match(problemaWhatsapp('(11) 9456-7890'), /fixo começa com 2, 3, 4 ou 5/);
});

test('máscara + validação concordam com o servidor (dados.mjs) e com formatarTelefone', () => {
  const ddds = ['11', '19', '21', '31', '41', '51', '61', '71', '81', '91', '20', '23', '29', '30', '39', '50', '56', '70', '80', '90'];
  for (const ddd of ddds) {
    for (const numero of [`${ddd}987654321`, `${ddd}34567890`, `${ddd}87654321`, `${ddd}912345678`]) {
      const mascarado = mascaraTelefone(numero);
      assert.equal(problemaWhatsapp(mascarado) === null, validarWhatsapp(mascarado), `discordância em ${mascarado}`);
      if (validarWhatsapp(mascarado)) assert.equal(mascarado, formatarTelefone(numero));
    }
  }
});

test('UFs e cores sugeridas', () => {
  assert.equal(UFS.length, 27);
  assert.ok(UFS.includes('DF'));
  assert.equal(CORES_SUGERIDAS.length, 9);
  for (const { cor, nome } of CORES_SUGERIDAS) {
    assert.match(cor, /^#[0-9a-f]{6}$/);
    assert.ok(nome);
    assert.equal(corClaraDemais(cor), false, `${cor} não deveria ser clara demais`);
  }
  assert.equal(corClaraDemais('#fff3a0'), true);
  assert.equal(corClaraDemais('#f5e663'), false, 'amarelo médio (L 67%) ainda passa');
  assert.equal(corClaraDemais('#ffffff'), true);
  assert.equal(corClaraDemais('nada'), false);
});

/* ------------------------------------------------------------------ cor dominante do logo */

/** Monta RGBA a partir de [[r,g,b,a,quantidade], …]. */
function pixels(grupos) {
  const lista = [];
  for (const [r, g, b, a, n] of grupos) for (let i = 0; i < n; i++) lista.push(r, g, b, a);
  return new Uint8ClampedArray(lista);
}

test('cor dominante ignora o fundo branco e o transparente e pega a cor de marca', () => {
  const p = pixels([
    [255, 255, 255, 255, 600], // fundo branco
    [0, 0, 0, 0, 300], // transparente
    [194, 59, 110, 255, 80], // magenta da marca
    [30, 30, 30, 255, 40], // texto quase preto
  ]);
  assert.equal(corDominante(p), '#c23b6e');
});

test('cor dominante: com duas cores, vence a mais presente; tons vizinhos somam e viram a média', () => {
  const p = pixels([
    [42, 127, 134, 255, 50],
    [40, 125, 132, 255, 50], // mesma caixa da anterior
    [194, 83, 27, 255, 70],
  ]);
  assert.equal(corDominante(p), '#297e85');
  // Mesma quantidade: a mais saturada desempata.
  assert.equal(corDominante(pixels([[120, 140, 150, 255, 50], [194, 59, 110, 255, 50]])), '#c23b6e');
});

test('cor dominante: logo só em preto/cinza devolve o escuro; nada aproveitável → null', () => {
  assert.equal(corDominante(pixels([[255, 255, 255, 255, 100], [20, 22, 26, 255, 30]])), '#14161a');
  assert.equal(corDominante(pixels([[255, 255, 255, 255, 100], [240, 240, 240, 255, 50]])), null);
  assert.equal(corDominante(pixels([[194, 59, 110, 10, 100]])), null, 'quase transparente não conta');
  assert.equal(corDominante(new Uint8ClampedArray(0)), null);
  assert.equal(corDominante([194, 59, 110, 255]), '#c23b6e', 'aceita array comum');
});

/* ------------------------------------------------------------------ tempo, tamanho, arquivo */

test('tempo relativo em português', () => {
  const agora = Date.parse('2026-10-06T12:00:00Z');
  const antes = (s) => new Date(agora - s * 1000).toISOString();
  assert.equal(tempoRelativo(antes(10), agora), 'agora há pouco');
  assert.equal(tempoRelativo(antes(60), agora), 'há 1 minuto');
  assert.equal(tempoRelativo(antes(60 * 59), agora), 'há 59 minutos');
  assert.equal(tempoRelativo(antes(3600), agora), 'há 1 hora');
  assert.equal(tempoRelativo(antes(3600 * 5), agora), 'há 5 horas');
  assert.equal(tempoRelativo(antes(86400 * 1.5), agora), 'ontem');
  assert.equal(tempoRelativo(antes(86400 * 3), agora), 'há 3 dias');
  assert.equal(tempoRelativo(antes(86400 * 45), agora), 'há 1 mês');
  assert.equal(tempoRelativo(antes(86400 * 200), agora), 'há 6 meses');
  assert.equal(tempoRelativo(antes(86400 * 364), agora), 'há 11 meses');
  assert.equal(tempoRelativo(antes(86400 * 800), agora), 'há 2 anos');
  assert.equal(tempoRelativo(new Date(agora + 5000).toISOString(), agora), 'agora há pouco', 'relógio adiantado');
  assert.equal(tempoRelativo(null, agora), '');
  assert.equal(tempoRelativo('quebrado', agora), '');
});

test('arquivo de logo: PNG/JPG/SVG/WebP até 5 MB', () => {
  assert.equal(problemaArquivoLogo({ name: 'logo.png', type: 'image/png', size: 1000 }), null);
  assert.equal(problemaArquivoLogo({ name: 'logo.svg', type: '', size: 1000 }), null, 'tipo pela extensão');
  assert.equal(problemaArquivoLogo({ name: 'logo.webp', type: 'image/webp', size: 5 * 1024 * 1024 }), null);
  assert.match(problemaArquivoLogo({ name: 'logo.gif', type: 'image/gif', size: 1000 }), /PNG, JPG, SVG ou WebP/);
  assert.match(problemaArquivoLogo({ name: 'logo.pdf', type: 'application/pdf', size: 1000 }), /PNG, JPG, SVG ou WebP/);
  assert.match(problemaArquivoLogo({ name: 'logo.jpg', type: 'image/jpeg', size: 6 * 1024 * 1024 }), /no máximo 5 MB \(este tem 6 MB\)/);
  assert.match(problemaArquivoLogo({ name: 'logo.png', type: 'image/png', size: 0 }), /vazio/);
  assert.equal(formatarTamanho(1536), '1,5 KB');
});

/* ------------------------------------------------------------------ biblioteca e prévia */

test('CSS dos sites no editor: fontes servidas pela API, base e todas as seções na ordem', () => {
  const lib = carregarBiblioteca(dir('../../biblioteca'));
  const css = cssSite(lib);
  assert.ok(css.includes('src:url(/api/fontes/manrope-latin-wght-normal.woff2) format("woff2")'));
  assert.ok(css.includes('font-family:"Manrope Reserva";src:local("Arial")'));
  assert.ok(css.includes(lib.baseCss.slice(0, 200)));
  const tipos = tiposOrdenados(lib);
  assert.equal(tipos[0], 'header');
  assert.equal(tipos.at(-1), 'rodape');
  let pos = -1;
  for (const tipo of tipos) {
    const p = css.indexOf(`/* seção: ${tipo} */`);
    assert.ok(p > pos, `seção ${tipo} fora de ordem`);
    pos = p;
  }
  // Nomes de família/arquivo inválidos não entram (sem injeção de CSS).
  const ruim = cssFontes({ fontes: { arquivos: [{ familia: 'X"}body{', arquivo: 'a.woff2' }, { familia: 'Ok', arquivo: '../x.woff2' }] } });
  assert.equal(ruim, '');
  assert.deepEqual(nichosOrdenados(lib).map((n) => n.id), ['advocacia', 'financas', 'empresas', 'clinicas']);
  assert.deepEqual(modelosOrdenados(lib).map((m) => m.id), ['classico', 'moderno', 'direto']);
});

test('paleta escopada por prévia e opções do preparo no modo editor', () => {
  assert.equal(paletaEscopada('.rk{--p:#c23b6e;--on-p:#ffffff}', 'p3'), '.rk[data-previa="p3"]{--p:#c23b6e;--on-p:#ffffff}');
  const o = opcoesPreparo({ midia: { m_1: {} } });
  assert.equal(o.modo, 'editor');
  assert.equal(o.urlMidia, '/api/media/{id}/{w}');
  assert.deepEqual(o.midia, { m_1: {} });
  assert.equal(o.ano, new Date().getFullYear());
});
