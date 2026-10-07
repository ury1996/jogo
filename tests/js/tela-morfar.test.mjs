// Atualização mínima do DOM da prévia (public_html/editor/js/editor/morfar.mjs), num
// navegador de verdade (Chromium do Playwright). Sem navegador instalado o teste é pulado.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const codigo = readFileSync(fileURLToPath(new URL('../../public_html/editor/js/editor/morfar.mjs', import.meta.url)), 'utf8')
  .replace(/^export /gm, '');

let chromium = null;
try {
  ({ chromium } = await import('@playwright/test'));
} catch {
  chromium = null;
}

async function abrirNavegador() {
  if (!chromium) return null;
  try {
    return await chromium.launch();
  } catch {
    return null;
  }
}

const navegador = await abrirNavegador();
const pular = navegador ? false : 'Chromium do Playwright indisponível';

test('morfar: só muda o que mudou; seções casadas pelo id; estado do editor preservado', { skip: pular }, async () => {
  const page = await navegador.newPage();
  try {
    await page.setContent('<div id="raiz"></div>');
    await page.addScriptTag({ content: `${codigo}\nwindow.morfar = morfar;` });
    const r = await page.evaluate(() => {
      const raiz = document.getElementById('raiz');
      const html = (titulo, ordem = ['a', 'b', 'c'], aberto = '') => ordem.map((id) => {
        if (id === 'a') return `<div class="rk-sec" id="a" data-sec="0"><h1 data-k="hero.titulo">${titulo}</h1><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt=""></div>`;
        if (id === 'b') return `<div class="rk-sec" id="b" data-sec="1"><details${aberto}><summary><h3 data-k="faq.1.q">Pergunta</h3></summary><p data-k="faq.1.a">Resposta</p></details></div>`;
        return '<div class="rk-sec" id="c" data-sec="2"><p data-k="cta.texto">Fim</p></div>';
      }).join('\n');

      raiz.innerHTML = html('Título');
      const h1 = raiz.querySelector('h1');
      const img = raiz.querySelector('img');
      const secB = raiz.querySelector('#b');
      // decoração do editor
      h1.setAttribute('contenteditable', 'plaintext-only');
      h1.setAttribute('role', 'textbox');
      h1.classList.add('ed-editando');
      raiz.querySelector('details').open = true;

      const res = {};
      window.morfar(raiz, html('Novo título'), null);
      res.mesmoH1 = raiz.querySelector('h1') === h1;
      res.texto = h1.textContent;
      res.editavel = h1.getAttribute('contenteditable');
      res.classeEditor = h1.classList.contains('ed-editando');
      res.mesmaImg = raiz.querySelector('img') === img;
      res.detalhesAbertos = raiz.querySelector('details').open;

      // foco: o campo em edição não perde o que o usuário digitou
      h1.focus();
      h1.textContent = 'Digitando…';
      window.morfar(raiz, html('Outro'), h1);
      res.digitacaoMantida = h1.textContent;

      // reordenar: os nós das seções são movidos, não recriados
      window.morfar(raiz, html('Outro', ['c', 'a', 'b']), null);
      res.ordem = [...raiz.children].map((e) => e.id).join(',');
      res.mesmaSecaoB = raiz.querySelector('#b') === secB;
      res.mesmoH1DepoisDeMover = raiz.querySelector('h1') === h1;
      res.textoDepoisDeMover = h1.textContent;

      // seção removida e atributo removido
      window.morfar(raiz, html('Outro', ['a', 'c']).replace(' data-sec="2"', ''), null);
      res.semB = !raiz.querySelector('#b');
      res.semAtributo = !raiz.querySelector('#c').hasAttribute('data-sec');
      return res;
    });
    assert.deepEqual(r, {
      mesmoH1: true,
      texto: 'Novo título',
      editavel: 'plaintext-only',
      classeEditor: true,
      mesmaImg: true,
      detalhesAbertos: true,
      digitacaoMantida: 'Digitando…',
      ordem: 'c,a,b',
      mesmaSecaoB: true,
      mesmoH1DepoisDeMover: true,
      textoDepoisDeMover: 'Outro',
      semB: true,
      semAtributo: true,
    });
  } finally {
    await page.close();
  }
});

test.after(async () => {
  await navegador?.close();
});
