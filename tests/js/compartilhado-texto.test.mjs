import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  aparar, arred, codificarUri, colapsarEspacos, comoLista, comoMapa, ehMapa, escapeHtml, normalizar, pegar, semAcentos,
  temChave, textoDe,
} from '../../public_html/editor/js/compartilhado/texto.mjs';

test('semAcentos usa o mapa explícito (minúsculas e maiúsculas) e remove acentos combinantes', () => {
  assert.equal(semAcentos('áàâãäå éèêë íìîï óòôõö úùûü ç ñ ý ÿ'), 'aaaaaa eeee iiii ooooo uuuu c n y y');
  assert.equal(semAcentos('ÁÀÂÃÄÅ ÉÈÊË ÍÌÎÏ ÓÒÔÕÖ ÚÙÛÜ Ç Ñ Ý Ÿ'), 'AAAAAA EEEE IIII OOOOO UUUU C N Y Y');
  assert.equal(semAcentos('Café'), 'Cafe');
  assert.equal(semAcentos('ŁØ'), 'ŁØ', 'fora do mapa fica como está');
  assert.equal(semAcentos(null), '');
});

test('normalizar: minúsculas + sem acento + espaços colapsados', () => {
  assert.equal(normalizar('  Consultoria   TÉCNICA\t\n'), 'consultoria tecnica');
  assert.equal(normalizar('Plantão 24 horas'), 'plantao 24 horas');
  assert.equal(normalizar(''), '');
});

test('colapsarEspacos e aparar usam a mesma classe de espaços do trim do JS', () => {
  assert.equal(colapsarEspacos('﻿ a 　 b  '), 'a b');
  assert.equal(aparar('  a  b \n'), 'a  b');
  const todos = ' \t\n\v\f\r        　﻿';
  assert.equal(aparar(`${todos}x${todos}`), `${todos}x${todos}`.trim());
});

test('escapeHtml escapa só & < > " \' (não / ` =) [M8]', () => {
  assert.equal(escapeHtml(`& < > " ' / \` =`), '&amp; &lt; &gt; &quot; &#39; / ` =');
  assert.equal(escapeHtml('&amp;'), '&amp;amp;', 'não deixa entidades passarem');
  assert.equal(escapeHtml(null), '');
  assert.equal(escapeHtml(undefined), '');
  assert.equal(escapeHtml(2026), '2026');
  assert.equal(escapeHtml(true), 'true');
});

test('codificarUri = encodeURIComponent (mantém !*\'())', () => {
  assert.equal(codificarUri("Olá! (teste) *a* 'b' ~-_."), "Ol%C3%A1!%20(teste)%20*a*%20'b'%20~-_.");
  assert.equal(codificarUri('a&b=c?d#e/f+g'), 'a%26b%3Dc%3Fd%23e%2Ff%2Bg');
  assert.equal(codificarUri('😀'), '%F0%9F%98%80');
  assert.equal(codificarUri('\ud800'), '%EF%BF%BD', 'surrogate solto não lança');
});

test('arred = floor(x + 0.5), diferente de Math.round só nos negativos .5', () => {
  assert.equal(arred(0.5), 1);
  assert.equal(arred(1.5), 2);
  assert.equal(arred(2.5), 3);
  assert.equal(arred(-0.5), 0);
  assert.equal(arred(-1.5), -1);
  assert.equal(arred(254.49999999999997), 254);
});

test('utilitários de tipo tratam [] do PHP como mapa vazio', () => {
  assert.equal(textoDe(5), '5');
  assert.equal(textoDe(1.5), '');
  assert.equal(textoDe(true), '');
  assert.deepEqual(comoMapa([]), {});
  assert.deepEqual(comoMapa(null), {});
  assert.deepEqual(comoLista({}), []);
  assert.equal(ehMapa({}), true);
  assert.equal(ehMapa([]), false);
  assert.equal(temChave({ a: 1 }, 'a'), true);
  assert.equal(temChave({}, 'toString'), false, 'nunca do protótipo');
  assert.equal(temChave(['x'], '0'), false, 'listas não são mapas');
  assert.equal(pegar({ a: 1 }, 'constructor'), undefined);
});
