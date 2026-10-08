// Copia as fontes (subconjunto latin, woff2) dos pacotes @fontsource para biblioteca/fontes/
// e escreve biblioteca/fontes/fontes.json (contrato §3.8, [M16]).
//
// Uso: node ferramentas/copiar-fontes.mjs
//
// "reserva": para cada família, a fonte de sistema que entra enquanto o woff2 carrega,
// com size-adjust / ascent-override / descent-override / line-gap-override calculados das
// métricas reais do arquivo (mesma fórmula do next/font). Isso reduz o salto de layout (CLS)
// quando a fonte troca. O gerador declara, por exemplo:
//   @font-face{font-family:"Manrope Reserva";src:local("Arial"),local("Liberation Sans"),local("Arimo");
//              size-adjust:…;ascent-override:…;descent-override:…;line-gap-override:…}

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const NODE_MODULES = path.join(RAIZ, 'node_modules');
export const DIR_SAIDA = path.join(RAIZ, 'biblioteca/fontes');

/** Pares do contrato §3.8 (a ordem importa: o primeiro é o padrão de reserva do documento). */
export const PARES = {
  classica: { nome: 'Clássica', titulos: 'Libre Caslon Text', texto: 'Source Sans 3', descricao: 'Serifada tradicional', indicado: 'Advocacia, finanças tradicionais' },
  editorial: { nome: 'Editorial', titulos: 'DM Serif Display', texto: 'DM Sans', descricao: 'Serifa marcante', indicado: 'Estética, consultorias, marcas sofisticadas' },
  moderna: { nome: 'Moderna', titulos: 'Manrope', texto: 'Manrope', descricao: 'Sem serifa, firme', indicado: 'Empresas, tecnologia, conversão' },
  amigavel: { nome: 'Amigável', titulos: 'Nunito', texto: 'Nunito', descricao: 'Arredondada e leve', indicado: 'Clínicas, saúde, serviços para família' },
  nobre: { nome: 'Nobre', titulos: 'Cormorant Garamond', texto: 'Mulish', descricao: 'Serifa fina e elegante', indicado: 'Advocacia, finanças, estética de alto padrão' },
  clara: { nome: 'Clara', titulos: 'Plus Jakarta Sans', texto: 'Plus Jakarta Sans', descricao: 'Limpa e acolhedora', indicado: 'Clínicas, saúde, laboratórios' },
  geometrica: { nome: 'Geométrica', titulos: 'Sora', texto: 'Sora', descricao: 'Larga e tecnológica', indicado: 'Empresas, tecnologia, agências' },
};

/** Arquivos copiados (só estilo normal; o editor não oferece itálico). */
export const ORIGENS = [
  { familia: 'Libre Caslon Text', pacote: '@fontsource/libre-caslon-text', arquivo: 'libre-caslon-text-latin-400-normal.woff2', peso: '400' },
  { familia: 'Libre Caslon Text', pacote: '@fontsource/libre-caslon-text', arquivo: 'libre-caslon-text-latin-700-normal.woff2', peso: '700' },
  { familia: 'Source Sans 3', pacote: '@fontsource-variable/source-sans-3', arquivo: 'source-sans-3-latin-wght-normal.woff2' },
  { familia: 'DM Serif Display', pacote: '@fontsource/dm-serif-display', arquivo: 'dm-serif-display-latin-400-normal.woff2', peso: '400' },
  { familia: 'DM Sans', pacote: '@fontsource-variable/dm-sans', arquivo: 'dm-sans-latin-wght-normal.woff2' },
  { familia: 'Manrope', pacote: '@fontsource-variable/manrope', arquivo: 'manrope-latin-wght-normal.woff2' },
  { familia: 'Nunito', pacote: '@fontsource-variable/nunito', arquivo: 'nunito-latin-wght-normal.woff2' },
  { familia: 'Cormorant Garamond', pacote: '@fontsource-variable/cormorant-garamond', arquivo: 'cormorant-garamond-latin-wght-normal.woff2' },
  { familia: 'Mulish', pacote: '@fontsource-variable/mulish', arquivo: 'mulish-latin-wght-normal.woff2' },
  { familia: 'Plus Jakarta Sans', pacote: '@fontsource-variable/plus-jakarta-sans', arquivo: 'plus-jakarta-sans-latin-wght-normal.woff2' },
  { familia: 'Sora', pacote: '@fontsource-variable/sora', arquivo: 'sora-latin-wght-normal.woff2' },
];

/**
 * Fontes de sistema usadas como reserva. Larguras médias medidas (com a mesma amostra de
 * letras de mediaLarguras) em Liberation Sans/Serif, que têm métricas idênticas a Arial e
 * Times New Roman — por isso os três nomes funcionam em local().
 */
export const SISTEMA = {
  'sem-serifa': { nome: 'Arial', local: ['Arial', 'Liberation Sans', 'Arimo'], mediaLargura: 934.5116279069767, unidadesPorEm: 2048 },
  serifada: { nome: 'Times New Roman', local: ['Times New Roman', 'Liberation Serif', 'Tinos'], mediaLargura: 854.3953488372093, unidadesPorEm: 2048 },
};

// Amostra de largura média (frequência aproximada de letras + espaços), a mesma do next/font.
export const AMOSTRA = 'aaabcdeeeefghiijklmnnoopqrrssttuvwxyz      ';

// ------------------------------------------------------------------ leitura de métricas (TTF/OTF e WOFF2)

const TAGS_WOFF2 = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca',
  'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF',
  'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc',
  'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak',
  'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill'];

function lerBase128(buf, pos) {
  let valor = 0;
  for (let i = 0; i < 5; i++) {
    const b = buf[pos.v++];
    valor = valor * 128 + (b & 0x7f);
    if ((b & 0x80) === 0) return valor;
  }
  throw new Error('UIntBase128 inválido');
}

/** Tabelas de um WOFF2: { tag: { dados: Buffer, transformada: bool } }. */
function tabelasWoff2(buf) {
  if (buf.toString('latin1', 0, 4) !== 'wOF2') throw new Error('não é WOFF2');
  const numTabelas = buf.readUInt16BE(12);
  const tamanhoComprimido = buf.readUInt32BE(20);
  const pos = { v: 48 };
  const dir = [];
  for (let i = 0; i < numTabelas; i++) {
    const flags = buf[pos.v++];
    const indice = flags & 0x3f;
    const versao = (flags >> 6) & 0x03;
    let tag;
    if (indice === 63) {
      tag = buf.toString('latin1', pos.v, pos.v + 4);
      pos.v += 4;
    } else {
      tag = TAGS_WOFF2[indice];
    }
    const tamanhoOriginal = lerBase128(buf, pos);
    const ehGlyfLoca = tag === 'glyf' || tag === 'loca';
    const transformada = ehGlyfLoca ? versao !== 3 : versao !== 0;
    const tamanho = transformada ? lerBase128(buf, pos) : tamanhoOriginal;
    dir.push({ tag, tamanho, transformada });
  }
  const dados = zlib.brotliDecompressSync(buf.subarray(pos.v, pos.v + tamanhoComprimido));
  const tabelas = {};
  let inicio = 0;
  for (const t of dir) {
    tabelas[t.tag] = { dados: dados.subarray(inicio, inicio + t.tamanho), transformada: t.transformada };
    inicio += t.tamanho;
  }
  return tabelas;
}

/** Tabelas de um TTF/OTF. */
function tabelasSfnt(buf) {
  const numTabelas = buf.readUInt16BE(4);
  const tabelas = {};
  for (let i = 0; i < numTabelas; i++) {
    const r = 12 + i * 16;
    const tag = buf.toString('latin1', r, r + 4);
    const offset = buf.readUInt32BE(r + 8);
    const tamanho = buf.readUInt32BE(r + 12);
    tabelas[tag] = { dados: buf.subarray(offset, offset + tamanho), transformada: false };
  }
  return tabelas;
}

/** Função código → glifo a partir da tabela cmap (formatos 4 e 12). */
function mapaCaracteres(cmap) {
  const n = cmap.readUInt16BE(2);
  let melhor = null;
  for (let i = 0; i < n; i++) {
    const r = 4 + i * 8;
    const plataforma = cmap.readUInt16BE(r);
    const codificacao = cmap.readUInt16BE(r + 2);
    const offset = cmap.readUInt32BE(r + 4);
    const formato = cmap.readUInt16BE(offset);
    const unicode = plataforma === 0 || (plataforma === 3 && (codificacao === 1 || codificacao === 10));
    if (!unicode || (formato !== 4 && formato !== 12)) continue;
    if (melhor === null || formato === 12) melhor = { offset, formato };
  }
  if (melhor === null) throw new Error('cmap sem subtabela Unicode');
  const o = melhor.offset;
  if (melhor.formato === 12) {
    const grupos = cmap.readUInt32BE(o + 12);
    return (c) => {
      for (let g = 0; g < grupos; g++) {
        const r = o + 16 + g * 12;
        const ini = cmap.readUInt32BE(r);
        const fim = cmap.readUInt32BE(r + 4);
        if (c >= ini && c <= fim) return cmap.readUInt32BE(r + 8) + (c - ini);
      }
      return 0;
    };
  }
  const segmentos = cmap.readUInt16BE(o + 6) / 2;
  const fins = o + 14;
  const inicios = fins + segmentos * 2 + 2;
  const deltas = inicios + segmentos * 2;
  const deslocamentos = deltas + segmentos * 2;
  return (c) => {
    for (let s = 0; s < segmentos; s++) {
      const fim = cmap.readUInt16BE(fins + s * 2);
      if (c > fim) continue;
      const ini = cmap.readUInt16BE(inicios + s * 2);
      if (c < ini) return 0;
      const delta = cmap.readInt16BE(deltas + s * 2);
      const desl = cmap.readUInt16BE(deslocamentos + s * 2);
      if (desl === 0) return (c + delta) & 0xffff;
      const g = cmap.readUInt16BE(deslocamentos + s * 2 + desl + 2 * (c - ini));
      return g === 0 ? 0 : (g + delta) & 0xffff;
    }
    return 0;
  };
}

/** Métricas da instância padrão: unidadesPorEm, ascent, descent, lineGap (hhea) e larguras. */
export function lerMetricas(caminho) {
  const buf = fs.readFileSync(caminho);
  const tabelas = buf.toString('latin1', 0, 4) === 'wOF2' ? tabelasWoff2(buf) : tabelasSfnt(buf);
  for (const t of ['head', 'hhea', 'hmtx', 'cmap']) if (!tabelas[t]) throw new Error(`${path.basename(caminho)}: sem tabela ${t}`);
  const head = tabelas.head.dados;
  const hhea = tabelas.hhea.dados;
  const numMetricas = hhea.readUInt16BE(34);
  // hmtx transformado (WOFF2): 1 byte de flags e depois as larguras; senão pares (largura, lsb).
  const hmtx = tabelas.hmtx;
  const largura = (g) => {
    const i = Math.min(g, numMetricas - 1);
    return hmtx.transformada ? hmtx.dados.readUInt16BE(1 + i * 2) : hmtx.dados.readUInt16BE(i * 4);
  };
  const glifo = mapaCaracteres(tabelas.cmap.dados);
  return {
    unidadesPorEm: head.readUInt16BE(18),
    ascent: hhea.readInt16BE(4),
    descent: hhea.readInt16BE(6),
    lineGap: hhea.readInt16BE(8),
    largura: (caractere) => largura(glifo(caractere.codePointAt(0))),
    temCaractere: (caractere) => glifo(caractere.codePointAt(0)) !== 0,
  };
}

/** Largura média da AMOSTRA (unidades da fonte), ou null se faltar algum caractere. */
export function mediaLarguras(metricas) {
  const chars = [...AMOSTRA];
  if (!chars.every((c) => metricas.temCaractere(c))) return null;
  return chars.reduce((s, c) => s + metricas.largura(c), 0) / chars.length;
}

const pct = (x) => `${(Math.abs(x) * 100).toFixed(2)}%`;

/** Ajustes da fonte de reserva (descritores CSS de @font-face). */
export function ajustesReserva(metricas, base) {
  const media = mediaLarguras(metricas);
  const tamanho = media === null ? 1 : (media / metricas.unidadesPorEm) / (base.mediaLargura / base.unidadesPorEm);
  const em = metricas.unidadesPorEm * tamanho;
  return {
    'size-adjust': pct(tamanho),
    'ascent-override': pct(metricas.ascent / em),
    'descent-override': pct(metricas.descent / em),
    'line-gap-override': pct(metricas.lineGap / em),
  };
}

// ------------------------------------------------------------------ cópia e fontes.json

function lerJson(caminho) {
  return JSON.parse(fs.readFileSync(caminho, 'utf8'));
}

function faixaDePeso(origem, meta) {
  if (origem.peso) return origem.peso;
  const w = meta?.variable?.wght;
  if (!w) throw new Error(`${origem.familia}: peso não informado e pacote sem eixo wght`);
  return `${w.min} ${w.max}`;
}

export function copiarFontes({ saida = DIR_SAIDA, silencioso = false } = {}) {
  fs.mkdirSync(saida, { recursive: true });
  const arquivos = [];
  const reserva = {};
  const licencas = new Map();
  for (const origem of ORIGENS) {
    const dirPacote = path.join(NODE_MODULES, origem.pacote);
    const fonte = path.join(dirPacote, 'files', origem.arquivo);
    if (!fs.existsSync(fonte)) throw new Error(`Fonte não encontrada: ${path.relative(RAIZ, fonte)} (rode npm install)`);
    const meta = lerJson(path.join(dirPacote, 'metadata.json'));
    const unicode = lerJson(path.join(dirPacote, 'unicode.json'));
    fs.copyFileSync(fonte, path.join(saida, origem.arquivo));
    arquivos.push({
      familia: origem.familia,
      arquivo: origem.arquivo,
      peso: faixaDePeso(origem, meta),
      estilo: 'normal',
      unicodeRange: unicode.latin,
      licenca: meta?.license?.type ?? 'OFL-1.1',
    });
    if (!reserva[origem.familia]) {
      const categoria = meta.category === 'serif' ? 'serifada' : 'sem-serifa';
      const base = SISTEMA[categoria];
      reserva[origem.familia] = {
        familia: `${origem.familia} Reserva`,
        base: categoria,
        local: base.local,
        ajustes: ajustesReserva(lerMetricas(fonte), base),
      };
    }
    const arqLicenca = path.join(dirPacote, 'LICENSE');
    if (fs.existsSync(arqLicenca) && !licencas.has(origem.familia)) licencas.set(origem.familia, fs.readFileSync(arqLicenca, 'utf8').trim());
  }
  for (const par of Object.values(PARES)) {
    for (const familia of [par.titulos, par.texto]) {
      if (!arquivos.some((a) => a.familia === familia)) throw new Error(`Par de fontes sem arquivo para "${familia}"`);
    }
  }
  const dados = {
    versao: 1,
    pares: PARES,
    arquivos,
    reserva,
    sistema: Object.fromEntries(Object.entries(SISTEMA).map(([k, v]) => [k, { nome: v.nome, local: v.local }])),
  };
  fs.writeFileSync(path.join(saida, 'fontes.json'), `${JSON.stringify(dados, null, 2)}\n`);
  const textoLicencas = [...licencas.entries()]
    .map(([familia, texto]) => `==== ${familia} (SIL Open Font License 1.1) ====\n\n${texto}\n`).join('\n');
  fs.writeFileSync(path.join(saida, 'LICENCAS.txt'), `Fontes distribuídas sob a SIL Open Font License 1.1 (pacotes @fontsource).\n\n${textoLicencas}`);
  // Remove woff2 antigos que não fazem mais parte da lista.
  const validos = new Set(ORIGENS.map((o) => o.arquivo));
  for (const nome of fs.readdirSync(saida)) {
    if (nome.endsWith('.woff2') && !validos.has(nome)) fs.unlinkSync(path.join(saida, nome));
  }
  if (!silencioso) {
    const kb = arquivos.reduce((s, a) => s + fs.statSync(path.join(saida, a.arquivo)).size, 0) / 1024;
    console.log(`fontes: ${arquivos.length} arquivos woff2 (${kb.toFixed(0)} KB) + fontes.json → ${path.relative(RAIZ, saida)}`);
  }
  return dados;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    copiarFontes();
  } catch (erro) {
    console.error(erro.message);
    process.exit(1);
  }
}
