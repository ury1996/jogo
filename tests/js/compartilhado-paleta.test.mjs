import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  contraste, COR_PADRAO, cssPaleta, gerarPaleta, hexParaRgb, hslParaHex, luminancia, normalizarCor, rgbParaHsl,
} from '../../public_html/editor/js/compartilhado/paleta.mjs';

const ORDEM = ['--p', '--on-p', '--p-ink', '--p-dark', '--p-soft', '--p-soft2', '--p-tint', '--deep', '--ink', '--muted',
  '--line', '--p-fino', '--p-sobre-escuro', '--on-p-sobre-escuro'];

test('hexParaRgb aceita #rgb e #rrggbb em qualquer caixa; rejeita o resto', () => {
  assert.deepEqual(hexParaRgb('#C23B6E'), [194, 59, 110]);
  assert.deepEqual(hexParaRgb('abc'), [170, 187, 204]);
  assert.deepEqual(hexParaRgb(' #fff '), [255, 255, 255]);
  assert.equal(hexParaRgb('#12345'), null);
  assert.equal(hexParaRgb('red'), null);
  assert.equal(hexParaRgb(null), null);
  assert.equal(normalizarCor('#ABC'), '#aabbcc');
});

test('rgbParaHsl e hslParaHex fazem ida e volta em todas as cores da grade', () => {
  for (let r = 0; r < 256; r += 15) {
    for (let g = 0; g < 256; g += 15) {
      for (let b = 0; b < 256; b += 15) {
        const hex = `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
        const [h, s, l] = rgbParaHsl([r, g, b]);
        assert.ok(h >= 0 && h < 360 && s >= 0 && s <= 100 && l >= 0 && l <= 100);
        assert.equal(hslParaHex(h, s, l), hex);
      }
    }
  }
  assert.equal(hslParaHex(-120, 100, 50), hslParaHex(240, 100, 50));
  assert.equal(hslParaHex(720, 100, 50), '#ff0000');
});

test('luminância usa a tabela fixa (≈ fórmula WCAG) e o contraste vai de 1 a 21', () => {
  for (let i = 0; i < 256; i += 1) {
    const c = i / 255;
    const esperado = c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    const hex = `#${i.toString(16).padStart(2, '0').repeat(3)}`;
    assert.ok(Math.abs(luminancia(hex) - esperado) < 1e-15, hex);
  }
  assert.equal(contraste('#000000', '#ffffff'), 21);
  assert.equal(contraste('#ffffff', '#ffffff'), 1);
  assert.equal(luminancia('inválida'), 0);
});

test('gerarPaleta: tokens na ordem da tabela e fórmulas do §4.1', () => {
  const { tokens, claraDemais } = gerarPaleta('#C23B6E');
  assert.deepEqual(Object.keys(tokens), ORDEM);
  assert.equal(claraDemais, false);
  assert.equal(tokens['--p'], '#c23b6e', 'hex minúsculo');
  assert.equal(tokens['--on-p'], '#ffffff');
  const [H, S, L] = rgbParaHsl(hexParaRgb('#c23b6e'));
  assert.equal(tokens['--p-ink'], hslParaHex(H, S, Math.min(L, 36)));
  assert.equal(tokens['--p-dark'], hslParaHex(H, S, Math.max(L - 14, 10)));
  assert.equal(tokens['--p-soft'], hslParaHex(H, Math.min(S, 60), 93));
  assert.equal(tokens['--p-soft2'], hslParaHex(H, Math.min(S, 50), 84));
  assert.equal(tokens['--p-tint'], hslParaHex(H, Math.min(S, 35), 97));
  assert.equal(tokens['--deep'], hslParaHex(H, Math.min(S, 40), 12));
  assert.equal(tokens['--ink'], hslParaHex(H, 22, 13));
  assert.equal(tokens['--muted'], hslParaHex(H, 9, 40));
  assert.equal(tokens['--line'], hslParaHex(H, 16, 89));
  assert.equal(tokens['--p-fino'], '#c23b6e');
  assert.equal(tokens['--p-sobre-escuro'], '#c23b6e', 'contraste com --deep ≥ 3');
  for (const v of Object.values(tokens)) assert.match(v, /^#[0-9a-f]{6}$/);
});

test('cor clara demais (L > 75): aviso e --p-fino = --p-ink; texto escuro sobre a cor', () => {
  const { tokens, claraDemais } = gerarPaleta('#fff3b0');
  assert.equal(claraDemais, true);
  assert.equal(tokens['--p-fino'], tokens['--p-ink']);
  assert.notEqual(tokens['--p-fino'], tokens['--p']);
  assert.equal(tokens['--on-p'], '#14161a');
  assert.equal(gerarPaleta('#f5d90a').tokens['--on-p'], '#14161a', 'amarelo ganha texto escuro');
  assert.equal(gerarPaleta('#f5d90a').claraDemais, false, 'L = 50');
});

test('cor escura: --p-sobre-escuro clareia (S ≤ 80, L = 68) e o texto sobre ela é escuro', () => {
  const { tokens } = gerarPaleta('#1b2a4a');
  assert.equal(tokens['--on-p'], '#ffffff');
  assert.ok(contraste('#1b2a4a', tokens['--deep']) < 3);
  const [H, S] = rgbParaHsl(hexParaRgb('#1b2a4a'));
  assert.equal(tokens['--p-sobre-escuro'], hslParaHex(H, Math.min(S, 80), 68));
  assert.ok(contraste(tokens['--p-sobre-escuro'], tokens['--deep']) >= 3);
  assert.equal(tokens['--on-p-sobre-escuro'], '#14161a');
});

test('cinza (S = 0): tokens neutros, sem tingir de vermelho (H = 0)', () => {
  const { tokens } = gerarPaleta('#777777');
  for (const nome of ORDEM) {
    const [r, g, b] = hexParaRgb(tokens[nome]);
    if (nome !== '--on-p' && nome !== '--on-p-sobre-escuro') assert.ok(r === g && g === b, `${nome} = ${tokens[nome]}`);
  }
  assert.equal(tokens['--ink'], hslParaHex(0, 0, 13));
});

test('cor inválida cai na cor padrão; cssPaleta sem espaços na ordem recebida', () => {
  assert.deepEqual(gerarPaleta('zzz'), gerarPaleta(COR_PADRAO));
  assert.equal(cssPaleta({ '--p': '#000000', '--on-p': '#ffffff' }), '.rk{--p:#000000;--on-p:#ffffff}');
  assert.match(cssPaleta(gerarPaleta('#2a7f86').tokens), /^\.rk\{--p:#2a7f86;--on-p:#[0-9a-f]{6};--p-ink:/);
});
