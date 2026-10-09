// Teste de estresse de layout das seções da biblioteca.
//
// Uso: npm run estresse   (ou: node ferramentas/estresse-layout.mjs [tipo[/opcao] …] [opções])
//   --capturas               salva a captura de cada variação com problema em var/estresse/
//   --galeria=DIR            salva a captura de TODA variação em DIR (para comparar antes/depois)
//   --variacoes=a,b          só essas variações (padrao, curto, longo, sem-fotos, faq=7…)
//   --larguras=1280,900,390  larguras de tela
//   --servir                 só sobe o servidor e lista as URLs de cada variação (para abrir no navegador)
//
// Listas que correm como texto (chips, nomes) declaram no CSS `--rk-lista: fluxo` (ou `texto`):
// a última linha incompleta é natural e não conta como linha órfã.
//
// Para cada tipo × opção de seção, monta variações que o cliente pode produzir sem mexer na
// estrutura — quantidade de itens (do mínimo ao máximo de cada lista), textos curtos, no limite de
// caracteres e com palavras compridas, com e sem fotos, dados completos ou mínimos, sem WhatsApp —
// renderiza cada uma (o mesmo preparo da prévia do editor) no Chromium em várias larguras e mede:
//
//   vazamento        algo sai da largura da página / da seção (rolagem horizontal)
//   texto-cortado    texto escondido por overflow (sem ser um corte de propósito com line-clamp)
//   sobreposicao     dois textos (ou dois itens de lista) um em cima do outro
//   linha-orfa       grade de itens com a última linha incompleta e desalinhada (ex.: 3 + 3 + 1)
//   espaco-vazio     item esticado pela linha com um buraco grande sem conteúdo
//   foto-distorcida  foto esticada (proporção diferente sem object-fit)
//   foto-gigante     foto mais alta que a tela
//
// Saída: resumo no terminal e var/estresse/relatorio.json (+ capturas em var/estresse/ com --capturas).
// Sai com código 1 se houver problema (serve de teste automático).

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { carregarBiblioteca } from '../tests/paridade/carregar-biblioteca.mjs';
import { criarDocumento, registroCampos, registroListas } from '../public_html/editor/js/compartilhado/documento.mjs';
import { comFotosDeExemplo } from '../public_html/editor/js/compartilhado/fotos.mjs';
import { prepararSite } from '../public_html/editor/js/compartilhado/preparo.mjs';
import { cssSite } from '../public_html/editor/js/biblioteca.mjs';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SAIDA = path.join(RAIZ, 'var/estresse');
const args = process.argv.slice(2);
const filtros = args.filter((a) => !a.startsWith('--'));
const comCapturas = args.includes('--capturas');
// --galeria=DIR: salva a captura de TODA variação (com ou sem problema) em DIR, para comparar antes/depois.
const valor = (nome) => args.find((a) => a.startsWith(`--${nome}=`))?.slice(nome.length + 3) ?? null;
const galeria = valor('galeria');
const soVariacoes = valor('variacoes')?.split(',') ?? null;
const LARGURAS = (args.find((a) => a.startsWith('--larguras='))?.split('=')[1] ?? '1280,900,390').split(',').map(Number);

const lib = carregarBiblioteca(path.join(RAIZ, 'biblioteca'));
const campos = registroCampos(lib);
const listas = registroListas(lib);

/* ------------------------------------------------------------------ textos de mentira, mas realistas */

const PALAVRAS = ('atendimento cuidadoso planejamento individual tratamento acompanhamento próximo orientação clara '
  + 'segurança família rotina consulta avaliação detalhada equipe preparada horário flexível documentos processo etapa '
  + 'simples conforto confiança explicação cada passo resultado natural agenda rápida conversa inicial').split(' ');
const LONGAS = ['Otorrinolaringologia', 'responsabilidades', 'Fisioterapêutico', 'Previdenciário'];

function frase(alvo, { inicio = 0, final = '', longa = false } = {}) {
  const comprida = LONGAS[inicio % LONGAS.length];
  const partes = longa && comprida.length + final.length <= alvo ? [comprida] : [];
  let i = inicio;
  while ((partes.join(' ') + ' ' + PALAVRAS[i % PALAVRAS.length]).length + final.length <= alvo) partes.push(PALAVRAS[i++ % PALAVRAS.length]);
  if (partes.length === 0) partes.push(PALAVRAS[inicio % PALAVRAS.length].slice(0, Math.max(1, alvo - final.length)));
  const t = partes.join(' ');
  return t.charAt(0).toUpperCase() + t.slice(1) + final;
}

/** Texto para um campo: "curto", "medio" ou "longo" (no limite de caracteres). */
function textoPara(def, tamanho, semente) {
  const max = Number(def.max ?? 80);
  const longo = def.tipo === 'texto-longo';
  const alvo = tamanho === 'longo' ? max : tamanho === 'medio' ? Math.round(max * 0.6) : Math.min(max, longo ? 40 : 14);
  return frase(alvo, { inicio: semente, final: longo ? '.' : '', longa: tamanho === 'longo' });
}

/* ------------------------------------------------------------------ variações */

const DADOS_COMPLETOS = {
  nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321', telefone: '(11) 4521-3080',
  email: 'contato@sorrisovivo.com.br',
  endereco: { cep: '13201-005', logradouro: 'Rua Barão de Jundiaí', numero: '1100', complemento: 'Sala 4', bairro: 'Centro' },
  horarios: { seg: ['08:00', '18:00'], ter: ['08:00', '18:00'], qua: ['08:00', '18:00'], qui: ['08:00', '18:00'], sex: ['08:00', '17:00'], sab: ['08:00', '12:00'], dom: null },
  registro: { numero: '123456', uf: 'SP', responsavel: 'Ana Lima' },
  redes: { instagram: '@sorrisovivo', facebook: 'https://www.facebook.com/sorrisovivo', linkedin: '', youtube: '', google: '' },
};
const DADOS_LONGOS = {
  ...DADOS_COMPLETOS,
  nome: 'Clínica Odontológica Integrada Sorriso Vivo de Jundiaí Ltda',
  cidade: 'São José dos Campos do Rio Pardo Paulista',
  email: 'atendimento.agendamentos@clinicasorrisovivojundiai.com.br',
  endereco: { cep: '13201-005', logradouro: 'Avenida Doutor Antônio Frederico Ozanam', numero: '12345', complemento: 'Bloco B, Sala 1204, Torre Empresarial', bairro: 'Jardim das Hortênsias Paulistanas' },
  redes: { instagram: '@sorrisovivo', facebook: 'https://www.facebook.com/sorrisovivo', linkedin: 'https://www.linkedin.com/company/sorrisovivo', youtube: 'https://www.youtube.com/@sorrisovivo', google: 'https://g.page/sorrisovivo' },
};
const DADOS_MINIMOS = { nome: 'Sorriso', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' };
const DADOS_SEM_WHATS = { ...DADOS_COMPLETOS, whatsapp: '' };

const FONTES = ['editorial', 'geometrica', 'nobre', 'clara', 'classica', 'moderna', 'amigavel'];
const ACABAMENTOS = ['moderno', 'classico', 'direto', 'elegante', 'suave', 'impacto'];

function listasDaSecao(tipo) {
  return Object.entries(listas).filter(([, l]) => l.dono === tipo).map(([nome, l]) => ({ nome, min: l.repete[0], max: l.repete[1], campos: l.campos }));
}

/** Documento só com a seção pedida, já com textos/listas/fotos da variação. */
function montar(tipo, opcao, v, n) {
  const dados = v.dados ?? DADOS_COMPLETOS;
  let doc = criarDocumento({ nicho: v.nicho ?? 'clinicas', modelo: 'moderno', dados }, lib);
  doc.secoes = [{ tipo, opcao }];
  doc.estilo = { ...doc.estilo, fonte: v.fonte ?? 'editorial', acabamento: v.acabamento ?? 'moderno' };
  doc.textos = {};
  doc.listas = {};
  let semente = n;
  if (v.texto) {
    for (const [chave, def] of Object.entries(campos)) {
      if (def.dono !== tipo || !def.grupo || !['texto', 'texto-longo'].includes(def.tipo ?? 'texto')) continue;
      doc.textos[chave] = textoPara(def, v.texto, semente++);
    }
  }
  for (const l of listasDaSecao(tipo)) {
    const qtd = v.qtd?.[l.nome] ?? (v.texto ? (v.texto === 'curto' ? l.min : l.max) : null);
    if (qtd === null) continue;
    const ids = Array.from({ length: qtd }, (_, i) => `t${i + 1}`);
    doc.listas[l.nome] = ids;
    for (const id of ids) {
      for (const [campo, def] of Object.entries(l.campos ?? {})) {
        if (!['texto', 'texto-longo'].includes(def.tipo ?? 'texto')) continue;
        doc.textos[`${l.nome}.${id}.${campo}`] = textoPara(def, v.texto ?? 'medio', semente++);
      }
    }
  }
  let midia = {};
  if (v.fotos !== false) ({ doc, midia } = comFotosDeExemplo(doc, lib, '/fotos/'));
  return prepararSite(doc, lib, { modo: 'editor', midia, ano: 2026 });
}

function variacoes(tipo) {
  const vs = [
    { nome: 'padrao' },
    { nome: 'curto', texto: 'curto' },
    { nome: 'longo', texto: 'longo', dados: DADOS_LONGOS, fonte: 'geometrica' },
    { nome: 'longo-serifa', texto: 'longo', dados: DADOS_LONGOS, fonte: 'nobre', acabamento: 'elegante' },
    { nome: 'sem-fotos', fotos: false },
    { nome: 'dados-minimos', dados: DADOS_MINIMOS },
    { nome: 'sem-whatsapp', dados: DADOS_SEM_WHATS, acabamento: 'impacto' },
  ];
  for (const l of listasDaSecao(tipo)) {
    if (l.min === l.max) continue;
    for (let q = l.min; q <= l.max; q++) vs.push({ nome: `${l.nome}=${q}`, texto: 'medio', qtd: { [l.nome]: q } });
  }
  return vs;
}

const casos = [];
for (const [tipo, sec] of Object.entries(lib.secoes)) {
  for (const op of sec.manifest.opcoes) {
    if (filtros.length && !filtros.some((f) => f === tipo || f === `${tipo}/${op.id}`)) continue;
    variacoes(tipo).forEach((v, i) => (soVariacoes && !soVariacoes.includes(v.nome)) || casos.push({ id: casos.length, tipo, opcao: op.id, variacao: v.nome, r: montar(tipo, op.id, v, i * 7) }));
  }
}

/* ------------------------------------------------------------------ servidor local */

const css = cssSite(lib).replaceAll('/api/fontes/', '/fontes/');
function paginaDoCaso(c) {
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/site.css"><style>body{margin:0}</style></head>
<body><div class="${c.r.classesRaiz}" data-previa="p"><style>${c.r.cssPaleta.replace(/^\.rk\{/, '.rk[data-previa="p"]{')}</style>${c.r.html}</div></body></html>`;
}
const servidor = http.createServer((req, res) => {
  const url = decodeURIComponent(req.url.split('?')[0]);
  const enviar = (tipo, corpo) => { res.writeHead(200, { 'Content-Type': tipo, 'Cache-Control': 'max-age=3600' }); res.end(corpo); };
  if (url === '/site.css') return enviar('text/css', css);
  const m = url.match(/^\/caso\/(\d+)$/);
  if (m) return enviar('text/html; charset=utf-8', paginaDoCaso(casos[Number(m[1])]));
  const arq = url.startsWith('/fontes/') ? path.join(RAIZ, 'biblioteca/fontes', path.basename(url))
    : url.startsWith('/fotos/') ? path.join(RAIZ, 'biblioteca/fotos', path.basename(url)) : null;
  if (arq && fs.existsSync(arq)) return enviar(arq.endsWith('.woff2') ? 'font/woff2' : 'image/webp', fs.readFileSync(arq));
  res.writeHead(404); res.end();
});
await new Promise((ok) => servidor.listen(0, '127.0.0.1', ok));
const base = `http://127.0.0.1:${servidor.address().port}`;

/* ------------------------------------------------------------------ medições (no navegador) */

function medir() {
  const problemas = [];
  const add = (tipo, detalhe) => problemas.push({ tipo, detalhe });
  const visivel = (el) => {
    const fechado = el.closest('details:not([open])');
    if (fechado && fechado !== el && !el.closest('summary')) return false; // resposta fechada não aparece
    if (el.checkVisibility && !el.checkVisibility({ opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true })) return false;
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const nomeDe = (el) => {
    const k = el.closest('[data-k]')?.getAttribute('data-k');
    return (k ? k + ' ' : '') + el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : '');
  };
  const sec = document.querySelector('.rk-sec') ?? document.querySelector('[data-previa] > :not(style)');
  const largura = document.documentElement.clientWidth;
  if (document.documentElement.scrollWidth > largura + 1) add('vazamento', `página com ${document.documentElement.scrollWidth}px em ${largura}px`);

  const todos = [...sec.querySelectorAll('*')].filter(visivel);
  const temTexto = (el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
  // Texto só para leitor de tela (escondido de propósito: .rk-sr ou a mesma técnica, 1 px recortado).
  const soLeitor = (el) => el.closest('.rk-sr') !== null || el.getBoundingClientRect().width <= 1.5 || getComputedStyle(el).clipPath.startsWith('inset(50%');
  // Decorativos (aria-hidden: números e letras "fantasma", iniciais) e o aviso "sem foto" do editor.
  const decorativo = (el) => el.closest('[aria-hidden="true"], .rk-foto__vazio') !== null;
  const textos = todos.filter((el) => temTexto(el) && !soLeitor(el));
  const textosReais = textos.filter((el) => !decorativo(el));

  // Algum ancestral (até a seção) corta o que passa da caixa dele? Então não vaza para a página.
  const cortado = (el) => {
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const s = getComputedStyle(a);
      if (s.overflowX !== 'visible' || s.clipPath !== 'none') {
        const ra = a.getBoundingClientRect();
        if (ra.left >= -1.5 && ra.right <= largura + 1.5) return true;
      }
    }
    return false;
  };
  for (const el of todos) {
    const r = el.getBoundingClientRect();
    if ((r.right > largura + 1.5 || r.left < -1.5) && !cortado(el)) {
      // Só conta o mais externo (os filhos vazam junto).
      if (!el.parentElement || !(el.parentElement.getBoundingClientRect().right > largura + 1.5 || el.parentElement.getBoundingClientRect().left < -1.5)) {
        const s = getComputedStyle(el);
        if (s.position !== 'fixed') add('vazamento', `${nomeDe(el)} sai da tela (${Math.round(r.left)}…${Math.round(r.right)})`);
      }
    }
  }
  for (const el of textosReais) {
    const s = getComputedStyle(el);
    const clamp = s.webkitLineClamp && s.webkitLineClamp !== 'none';
    if (clamp || s.textOverflow === 'ellipsis') continue;
    if ((s.overflowX !== 'visible' && el.scrollWidth > el.clientWidth + 2) || (s.overflowY !== 'visible' && el.scrollHeight > el.clientHeight + 2)) {
      add('texto-cortado', `${nomeDe(el)}: "${el.textContent.trim().slice(0, 40)}"`);
    }
  }
  // Texto que passa da caixa de um ancestral que esconde o excesso (overflow/clip-path): some em parte.
  for (const el of textosReais) {
    const r = el.getBoundingClientRect();
    if (el.closest('.rk-pular')) continue; // "Pular para o conteúdo": fora da tela até receber foco
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const s = getComputedStyle(a);
      // Rolável (carrossel) não esconde: dá para chegar rolando.
      if (/auto|scroll/.test(s.overflowX + s.overflowY)) break;
      const cortaX = s.clipPath !== 'none' || s.overflowX === 'hidden' || s.overflowX === 'clip';
      const cortaY = s.clipPath !== 'none' || s.overflowY === 'hidden' || s.overflowY === 'clip';
      if (!cortaX && !cortaY) continue;
      if (s.textOverflow === 'ellipsis') break; // reticências de propósito ("Rua Barão de…")
      const ra = a.getBoundingClientRect();
      if ((cortaX && (r.left < ra.left - 2 || r.right > ra.right + 2)) || (cortaY && (r.top < ra.top - 2 || r.bottom > ra.bottom + 2))) {
        add('texto-cortado', `${nomeDe(el)} passa da caixa de ${nomeDe(a)}: "${el.textContent.trim().slice(0, 30)}"`);
        break;
      }
    }
  }
  // Texto sobre texto (exceto ancestral/descendente).
  const caixas = textosReais.map((el) => ({ el, r: el.getBoundingClientRect() }));
  for (let i = 0; i < caixas.length; i++) {
    for (let j = i + 1; j < caixas.length; j++) {
      const a = caixas[i]; const b = caixas[j];
      if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
      const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
      const h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
      if (w > 4 && h > 4 && w * h > 0.15 * Math.min(a.r.width * a.r.height, b.r.width * b.r.height)) {
        add('sobreposicao', `${nomeDe(a.el)} × ${nomeDe(b.el)}`);
      }
    }
  }
  // Listas: itens sobrepostos, linha órfã e espaço vazio.
  for (const lista of sec.querySelectorAll('[data-li]')) {
    const itens = [...lista.children].filter((el) => el.hasAttribute('data-it') && visivel(el));
    if (itens.length < 2) continue;
    const rs = itens.map((el) => el.getBoundingClientRect());
    for (let i = 0; i < rs.length; i++) {
      for (let j = i + 1; j < rs.length; j++) {
        const w = Math.min(rs[i].right, rs[j].right) - Math.max(rs[i].left, rs[j].left);
        const h = Math.min(rs[i].bottom, rs[j].bottom) - Math.max(rs[i].top, rs[j].top);
        if (w > 6 && h > 6) add('sobreposicao', `itens ${i + 1} e ${j + 1} de ${lista.dataset.li}`);
      }
    }
    // Colunas de texto (CSS columns) se equilibram pela altura: não há "linhas" de itens.
    if (getComputedStyle(lista).columnCount !== 'auto' && Number(getComputedStyle(lista).columnCount) > 1) continue;
    // Lista que corre como texto (chips, nomes em lista): a última linha incompleta é natural.
    const fluxo = ['fluxo', 'texto'].includes(getComputedStyle(lista).getPropertyValue('--rk-lista').trim());
    const linhas = [];
    for (const r of rs) {
      const l = linhas.find((x) => Math.abs(x.top - r.top) < 8);
      if (l) l.rs.push(r); else linhas.push({ top: r.top, rs: [r] });
    }
    const porLinha = Math.max(...linhas.map((l) => l.rs.length));
    const ultima = linhas[linhas.length - 1];
    if (linhas.length > 1 && porLinha > 1 && ultima.rs.length < porLinha) {
      const caixa = lista.getBoundingClientRect();
      const esq = Math.min(...ultima.rs.map((r) => r.left)) - caixa.left;
      const dir = caixa.right - Math.max(...ultima.rs.map((r) => r.right));
      const sobra = Math.max(esq, dir);
      const centrada = Math.abs(esq - dir) < 12;
      // "Cheia": a última linha ocupa a mesma largura da linha mais larga (item que estica).
      const larguraLinha = (l) => Math.max(...l.rs.map((r) => r.right)) - Math.min(...l.rs.map((r) => r.left));
      const cheia = larguraLinha(ultima) > Math.max(...linhas.map(larguraLinha)) * 0.9;
      if (!fluxo && !centrada && !cheia && sobra > caixa.width * 0.3) add('linha-orfa', `${lista.dataset.li}: ${linhas.map((l) => l.rs.length).join(' + ')}`);
    }
    for (const [i, el] of itens.entries()) {
      const r = rs[i];
      let fundo = r.top;
      for (const d of el.querySelectorAll('*')) {
        if (!visivel(d)) continue;
        fundo = Math.max(fundo, d.getBoundingClientRect().bottom);
      }
      const s = getComputedStyle(el);
      const vazio = r.bottom - fundo - parseFloat(s.paddingBottom) - parseFloat(s.borderBottomWidth);
      if (vazio > Math.max(56, r.height * 0.3) && linhas.length > 0 && linhas.find((l) => l.rs.includes(r))?.rs.length > 1) {
        add('espaco-vazio', `${lista.dataset.li} item ${i + 1}: ${Math.round(vazio)}px vazios`);
      }
    }
  }
  for (const img of sec.querySelectorAll('img')) {
    if (!visivel(img)) continue;
    const r = img.getBoundingClientRect();
    const w = Number(img.getAttribute('width')); const h = Number(img.getAttribute('height'));
    const s = getComputedStyle(img);
    if (w && h && s.objectFit === 'fill' && Math.abs(r.width / r.height - w / h) > 0.04 * (w / h)) add('foto-distorcida', `${nomeDe(img)} ${Math.round(r.width)}×${Math.round(r.height)} (original ${w}×${h})`);
    // Foto recortada (object-fit: cover) num espaço alto é fundo/coluna, não foto esticada.
    if (s.objectFit !== 'cover' && r.height > innerHeight * 1.15 && r.height > 700) add('foto-gigante', `${nomeDe(img)} com ${Math.round(r.height)}px de altura`);
  }
  // Um registro por (tipo, detalhe).
  const vistos = new Set();
  return problemas.filter((p) => { const k = p.tipo + p.detalhe; if (vistos.has(k)) return false; vistos.add(k); return true; });
}

/* ------------------------------------------------------------------ execução */

fs.mkdirSync(SAIDA, { recursive: true });
if (args.includes('--servir')) {
  for (const c of casos) console.log(`${base}/caso/${c.id}  ${c.tipo}/${c.opcao} ${c.variacao}`);
  await new Promise(() => {});
}
const navegador = await chromium.launch();
const resultados = [];
const POR_VEZ = 6;
const filas = Array.from({ length: POR_VEZ }, () => []);
casos.forEach((c, i) => filas[i % POR_VEZ].push(c));
let feitos = 0;
await Promise.all(filas.map(async (fila) => {
  const ctx = await navegador.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  const pagina = await ctx.newPage();
  for (const c of fila) {
    for (const largura of LARGURAS) {
      await pagina.setViewportSize({ width: largura, height: largura < 500 ? 844 : 900 });
      await pagina.goto(`${base}/caso/${c.id}`, { waitUntil: 'load' });
      await pagina.evaluate(async () => {
        // Fotos preguiçosas carregam já (medição e capturas estáveis).
        for (const img of document.images) img.loading = 'eager';
        await Promise.all([document.fonts.ready, ...[...document.images].map((i) => i.decode().catch(() => null))]);
      });
      const problemas = await pagina.evaluate(medir);
      if (galeria) {
        fs.mkdirSync(galeria, { recursive: true });
        await pagina.screenshot({ path: path.join(galeria, `${c.tipo}-${c.opcao}-${c.variacao.replace(/[^a-z0-9=-]/gi, '_')}-${largura}.png`), fullPage: true, animations: 'disabled' });
      }
      if (problemas.length) {
        let captura = null;
        if (comCapturas) {
          captura = `${c.tipo}-${c.opcao}-${c.variacao.replace(/[^a-z0-9=-]/gi, '_')}-${largura}.png`;
          await pagina.screenshot({ path: path.join(SAIDA, captura), fullPage: true, animations: 'disabled' });
        }
        resultados.push({ tipo: c.tipo, opcao: c.opcao, variacao: c.variacao, largura, problemas, captura });
      }
    }
    feitos++;
    if (feitos % 50 === 0) process.stderr.write(`  ${feitos}/${casos.length} variações\n`);
  }
  await ctx.close();
}));
await navegador.close();
servidor.close();

fs.writeFileSync(path.join(SAIDA, 'relatorio.json'), JSON.stringify(resultados, null, 1));
const resumo = {};
for (const r of resultados) {
  for (const p of r.problemas) {
    const k = `${r.tipo}/${r.opcao} · ${p.tipo}`;
    (resumo[k] ??= new Set()).add(`${r.variacao}@${r.largura}`);
  }
}
const chaves = Object.keys(resumo).sort();
console.log(`${casos.length} variações × ${LARGURAS.length} larguras; ${chaves.length} tipos de problema.`);
for (const k of chaves) console.log(`  ${k}: ${[...resumo[k]].slice(0, 8).join(', ')}${resumo[k].size > 8 ? ` (+${resumo[k].size - 8})` : ''}`);
process.exit(chaves.length ? 1 : 0);
