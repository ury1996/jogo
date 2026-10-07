// Utilitários dos testes ponta a ponta: estado do global-setup, cliente da API (sessão + CSRF),
// documento completo pronto para publicar, envio de fotos e acesso ao banco de teste via PHP.
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/** Grava o estado do ambiente e devolve o caminho do arquivo. */
export function salvarEstado(estado) {
  const arq = path.join(estado.dir, 'estado.json');
  writeFileSync(arq, JSON.stringify(estado, null, 2));
  return arq;
}

/** Estado gravado pelo global-setup (portas, usuário, fotos…). */
export function estado() {
  const arq = process.env.RANKLY_E2E_ESTADO;
  if (!arq) throw new Error('RANKLY_E2E_ESTADO ausente: rode pelo playwright.config.mjs (global-setup).');
  return JSON.parse(readFileSync(arq, 'utf8'));
}

/** Endereço público de um site publicado no servidor de teste. */
export function urlSite(slug) {
  return `http://${slug}.localhost:${estado().portaSites}`;
}

/** Roda uma ação do tests/e2e/apoio/preparar.php no banco de teste e devolve o JSON. */
export function preparar(...args) {
  const saida = execFileSync('php', ['tests/e2e/apoio/preparar.php', ...args.map(String)], {
    cwd: RAIZ, env: { ...process.env, RANKLY_CONFIG: estado().arqConfig }, encoding: 'utf8',
  });
  return JSON.parse(saida);
}

/** Dados completos de um negócio por nicho (passam na validação de publicação). */
export function dadosDoNegocio(nicho, sufixo = '') {
  const nomes = {
    advocacia: 'Moraes Advocacia',
    financas: 'Contábil Horizonte',
    empresas: 'Engenharia Alicerce',
    clinicas: 'Clínica Sorriso Vivo',
  };
  return {
    nome: `${nomes[nicho] || 'Negócio Teste'}${sufixo ? ' ' + sufixo : ''}`,
    cidade: 'Jundiaí',
    uf: 'SP',
    whatsapp: '(11) 98765-4321',
    telefone: '(11) 4521-3080',
    email: 'contato@exemplo.com.br',
    endereco: { cep: '13201-005', logradouro: 'Rua Barão de Jundiaí', numero: '1100', complemento: 'Sala 4', bairro: 'Centro' },
    horarios: {
      seg: ['08:00', '18:00'], ter: ['08:00', '18:00'], qua: ['08:00', '18:00'], qui: ['08:00', '18:00'],
      sex: ['08:00', '17:00'], sab: ['08:00', '12:00'], dom: null,
    },
    registro: { numero: '123456', uf: 'SP', responsavel: 'Ana Lima' },
    redes: { instagram: '@exemplo', facebook: 'https://www.facebook.com/exemplo', linkedin: '', youtube: '', google: '' },
  };
}

/** Lista efetiva (§2.3): doc → nicho → comum. */
export function itensLista(doc, lib, lista) {
  return doc.listas?.[lista] ?? lib.nichos[doc.nicho]?.listas?.[lista] ?? lib.comum?.listas?.[lista] ?? [];
}

/** Todas as chaves de imagem exibidas pelas seções do documento (escalares e itens de lista). */
export function chavesDeImagem(doc, lib) {
  const chaves = [];
  for (const s of doc.secoes) {
    const m = lib.secoes[s.tipo]?.manifest;
    if (!m) continue;
    for (const [chave, def] of Object.entries(m.campos || {})) if (def.tipo === 'imagem') chaves.push(chave);
    for (const [lista, def] of Object.entries(m.listas || {})) {
      const campos = Object.entries(def.campos || {}).filter(([, c]) => c.tipo === 'imagem').map(([c]) => c);
      if (!campos.length) continue;
      const max = def.repete?.[1] ?? 99;
      for (const id of itensLista(doc, lib, lista).slice(0, max)) for (const c of campos) chaves.push(`${lista}.${id}.${c}`);
    }
  }
  return [...new Set(chaves)];
}

/**
 * Documento pronto para publicar: depoimentos reais, números/clientes/nota confirmados,
 * todas as fotos preenchidas (ciclando as mídias) e o logo, se houver.
 */
export function completarDocumento(doc, lib, { fotos = [], logo = null, rastreamento = null } = {}) {
  const d = structuredClone(doc);
  d.textos ||= {};
  d.imagens ||= {};
  itensLista(d, lib, 'dep').forEach((id, i) => {
    d.textos[`dep.${id}.t`] = `Fui muito bem atendido do começo ao fim; explicaram cada etapa com calma (${i + 1}).`;
    d.textos[`dep.${id}.n`] = ['Marina Souza', 'Carlos Lima', 'Patrícia Alves', 'Roberto Dias'][i % 4];
    d.textos[`dep.${id}.c`] = 'Cliente desde 2021';
  });
  d.confirmados = ['num', 'cli', 'aval'];
  if (fotos.length) chavesDeImagem(d, lib).forEach((k, i) => { d.imagens[k] = fotos[i % fotos.length]; });
  if (logo) d.dados.logo = logo;
  if (rastreamento) d.rastreamento = { gtm: '', ga4: '', metaPixel: '', ...rastreamento };
  return d;
}

/** Cliente da API autenticado (cookie de sessão + X-CSRF-Token). */
export class Api {
  constructor(ctx, csrf) {
    this.ctx = ctx;
    this.csrf = csrf;
  }

  /** @param {import('@playwright/test').APIRequest} request */
  static async entrar(request) {
    const e = estado();
    const ctx = await request.newContext({ baseURL: e.api });
    const r = await ctx.post('/api/auth/login', { data: { email: e.usuario.email, senha: e.usuario.senha } });
    if (!r.ok()) throw new Error(`Login falhou: ${r.status()} ${await r.text()}`);
    return new Api(ctx, (await r.json()).csrf);
  }

  async chamar(metodo, caminho, opcoes = {}) {
    const r = await this.ctx.fetch(caminho, { method: metodo, headers: { 'X-CSRF-Token': this.csrf }, ...opcoes });
    const texto = await r.text();
    let corpo = null;
    try { corpo = JSON.parse(texto); } catch { corpo = texto; }
    return { status: r.status(), corpo };
  }

  async exigir(metodo, caminho, opcoes = {}, esperado = [200, 201]) {
    const r = await this.chamar(metodo, caminho, opcoes);
    if (!esperado.includes(r.status)) {
      throw new Error(`${metodo} ${caminho} → ${r.status}: ${typeof r.corpo === 'string' ? r.corpo.slice(0, 500) : JSON.stringify(r.corpo).slice(0, 1500)}`);
    }
    return r.corpo;
  }

  biblioteca() { return this.exigir('GET', '/api/biblioteca'); }

  criarSite(dados) { return this.exigir('POST', '/api/sites', { data: dados }); }

  obterSite(id) { return this.exigir('GET', `/api/sites/${id}`); }

  salvarDocumento(id, revisao, documento) { return this.exigir('PUT', `/api/sites/${id}`, { data: { revisao, documento } }); }

  async enviarImagem(siteId, arquivo, tipo = 'foto') {
    const nome = path.basename(arquivo);
    const r = await this.exigir('POST', '/api/media', {
      multipart: {
        site_id: String(siteId),
        tipo,
        arquivo: { name: nome, mimeType: nome.endsWith('.png') ? 'image/png' : 'image/jpeg', buffer: readFileSync(arquivo) },
      },
    });
    return r.midia;
  }

  validar(id) { return this.exigir('POST', `/api/sites/${id}/validar`, { data: {} }); }

  publicar(id) { return this.exigir('POST', `/api/sites/${id}/publicar`, { data: {} }); }

  leads(id) { return this.exigir('GET', `/api/sites/${id}/leads`); }

  async encerrar() { await this.ctx.dispose(); }
}

/**
 * Cria, completa (fotos, logo, depoimentos) e publica um site pela API.
 * @returns {{site, url, versao, documento}}
 */
export async function publicarSiteCompleto(api, lib, { nicho, modelo, sufixo = '', comFotos = true, comLogo = false, rastreamento = null, estilo }) {
  const e = estado();
  const { site } = await api.criarSite({ nicho, modelo, dados: dadosDoNegocio(nicho, sufixo), ...(estilo ? { estilo } : {}) });
  const fotos = [];
  if (comFotos) for (const f of e.fotos) fotos.push((await api.enviarImagem(site.id, f)).id);
  const logo = comLogo ? (await api.enviarImagem(site.id, e.logo, 'logo')).id : null;
  const atual = await api.obterSite(site.id);
  const documento = completarDocumento(atual.site.documento, lib, { fotos, logo, rastreamento });
  await api.salvarDocumento(site.id, atual.site.revisao, documento);
  const validacao = await api.validar(site.id);
  if (validacao.erros.length) throw new Error(`Validação de ${nicho}/${modelo}: ${JSON.stringify(validacao.erros)}`);
  const pub = await api.publicar(site.id);
  return { site: (await api.obterSite(site.id)).site, url: pub.url, versao: pub.versao, documento, validacao };
}

/**
 * Observadores de uma página: erros de console/página, violações de CSP e requisições externas
 * (respondidas localmente — nada sai para a internet).
 */
export async function vigiar(contexto, pagina) {
  const r = { erros: [], csp: [], externas: [] };
  await contexto.route(/^https?:\/\/(?!(?:[a-z0-9-]+\.)?localhost[:/]|127\.0\.0\.1[:/])/, (rota) => {
    const url = rota.request().url();
    r.externas.push(url);
    const js = /\.js(\?|$)|gtm\.js|gtag\/js|fbevents/.test(url);
    rota.fulfill({ status: 200, contentType: js ? 'text/javascript' : 'text/html', body: js ? '' : '<!doctype html><title>externo</title>' });
  });
  await contexto.addInitScript(() => {
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', (ev) => {
      window.__csp.push(`${ev.violatedDirective} ${ev.blockedURI}`);
    });
  });
  pagina.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') r.erros.push(`${m.type()}: ${m.text()}`); });
  pagina.on('pageerror', (err) => r.erros.push(`pageerror: ${err.message}`));
  r.cspDaPagina = async () => pagina.evaluate(() => window.__csp || []);
  return r;
}

/**
 * Requisição HTTP direta ao servidor de sites (sem navegador), com o Host do subdomínio:
 * o Node não resolve *.localhost sozinho.
 * @returns {Promise<{status: number, cabecalhos: object, corpo: string}>}
 */
export function httpSite(slug, caminho, { metodo = 'GET', corpo = null, cabecalhos = {} } = {}) {
  const e = estado();
  return new Promise((ok, falha) => {
    const req = http.request({
      host: '127.0.0.1', port: e.portaSites, method: metodo, path: caminho,
      headers: { Host: `${slug}.localhost:${e.portaSites}`, ...cabecalhos },
    }, (res) => {
      const partes = [];
      res.on('data', (p) => partes.push(p));
      res.on('end', () => ok({ status: res.statusCode, cabecalhos: res.headers, corpo: Buffer.concat(partes).toString('utf8') }));
    });
    req.on('error', falha);
    if (corpo !== null) req.write(corpo);
    req.end();
  });
}
