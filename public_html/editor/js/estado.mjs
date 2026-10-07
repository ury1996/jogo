// Estado do editor — contrato §9 (e PDF §7.6/§7.7).
//
//   const estado = await EstadoEditor.carregar(siteId);          // GET /api/sites/{id} + instância
//   // ou: new EstadoEditor({ siteId, doc, revisao, midia })     // ou new EstadoEditor(respostaDoGet)
//   estado.doc / estado.revisao / estado.midia / estado.site / estado.status / estado.pendente
//   estado.aplicar(d => { d.textos['hero.titulo'] = 'Novo'; return d; }, { rotulo: 'Editar título', agrupar: 'hero.titulo' })
//   estado.desfazer() / estado.refazer()  → { rotulo } do passo, ou null
//   estado.podeDesfazer / estado.podeRefazer / estado.encerrarGrupo()
//   estado.assinar((evento, estado) => …)  → cancela; evento.tipo: "doc" | "status" | "midia" | "revisao"
//   estado.aoConflito(({ revisaoAtual, documento, origem, carregarNova, manterMinha }) => …)
//   estado.salvar() / estado.destruir()
//
// Regras:
// - NUNCA altere estado.doc diretamente: as camadas (textos, dados, secoes…) são compartilhadas
//   entre os passos do histórico. Use aplicar(): `fn` recebe uma cópia em que cada camada só é
//   clonada (structuredClone) quando é lida, e devolve o novo documento (pode devolver a própria
//   cópia, ou nada). Camadas que não mudaram continuam sendo o MESMO objeto do passo anterior,
//   então 80 passos custam pouco.
// - Histórico de 80 passos. `agrupar` (string, ou true = usar o rótulo) junta alterações seguidas
//   da mesma chave num só passo (ex.: digitação num campo). O grupo fecha com encerrarGrupo(),
//   com outra chave, ou ao desfazer/refazer. `historico: false` altera sem criar passo.
// - Salvamento automático: 1,5 s sem mudanças → PUT /api/sites/{id} {revisao, documento}.
//   Status: "salvando" | "salvo" | "offline" | "conflito" | "erro".
//   Sem conexão: cópia no IndexedDB, nova tentativa a cada 10 s e ao voltar a conexão.
//   409: chama aoConflito (padrão: janela "Carregar a versão mais nova" / "Manter a minha").
//   Ao esconder a aba salva na hora; ao fechar com alteração pendente o navegador pede confirmação.

import { api as apiPadrao } from './api.mjs';

export const PASSOS_HISTORICO = 80;
export const ESPERA_SALVAR_MS = 1500;
export const INTERVALO_NOVA_TENTATIVA_MS = 10000;
const ESPERA_COPIA_LOCAL_MS = 600;
const LIMITE_KEEPALIVE = 60 * 1024; // fetch keepalive aceita até 64 KB por requisição

export const STATUS = Object.freeze({
  SALVANDO: 'salvando', SALVO: 'salvo', OFFLINE: 'offline', CONFLITO: 'conflito', ERRO: 'erro',
});

export const TEXTO_STATUS = Object.freeze({
  salvando: 'Salvando…',
  salvo: 'Salvo',
  offline: 'Sem conexão, tentando de novo',
  conflito: 'Alterado em outra janela',
  erro: 'Não foi possível salvar',
});

const instanciasAtivas = new Map(); // siteId → EstadoEditor vivo
const sementes = new Map(); // siteId → { antes, revisao, rotulo } (troca de modelo feita fora do editor)

function clonar(v) {
  return typeof structuredClone === 'function' ? structuredClone(v) : JSON.parse(JSON.stringify(v));
}

function iguais(a, b) {
  if (a === b) return true;
  if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') return false;
  try {
    return JSON.stringify(a) === JSON.stringify(b);
  } catch {
    return false;
  }
}

/**
 * Cópia "preguiçosa" do documento: cada camada objeto só é clonada quando lida (ou trocada).
 * materializar(resultado) monta o documento novo reaproveitando as camadas intocadas.
 */
function copiaPreguicosa(doc) {
  const copia = {};
  const valores = new Map();
  for (const chave of Object.keys(doc)) {
    const original = doc[chave];
    if (original === null || typeof original !== 'object') {
      copia[chave] = original;
      continue;
    }
    Object.defineProperty(copia, chave, {
      enumerable: true,
      configurable: true,
      get() {
        if (!valores.has(chave)) valores.set(chave, clonar(original));
        return valores.get(chave);
      },
      set(v) {
        valores.set(chave, v);
      },
    });
  }
  return {
    copia,
    materializar() {
      const novo = {};
      for (const chave of Object.keys(copia)) {
        const desc = Object.getOwnPropertyDescriptor(copia, chave);
        if (desc.get) novo[chave] = valores.has(chave) ? valores.get(chave) : doc[chave];
        else novo[chave] = desc.value;
      }
      return novo;
    },
  };
}

/**
 * Executa `fn` sobre uma cópia de `doc` e devolve o novo documento com as camadas que não
 * mudaram compartilhadas com `doc` — ou null se nada mudou.
 */
export function calcularNovoDocumento(doc, fn) {
  const { copia, materializar } = copiaPreguicosa(doc);
  const r = fn(copia);
  if (r !== undefined && r !== null && typeof r === 'object' && typeof r.then === 'function') {
    throw new TypeError('aplicar(fn): fn deve ser síncrona e devolver o novo documento.');
  }
  const bruto = r === undefined || r === null || r === copia ? materializar() : { ...r };
  const novo = {};
  let mudou = Object.keys(bruto).length !== Object.keys(doc).length;
  for (const chave of Object.keys(bruto)) {
    let v = bruto[chave];
    if (v !== doc[chave] && Object.prototype.hasOwnProperty.call(doc, chave) && iguais(v, doc[chave])) v = doc[chave];
    if (v !== doc[chave] || !Object.prototype.hasOwnProperty.call(doc, chave)) mudou = true;
    novo[chave] = v;
  }
  return mudou ? novo : null;
}

/* ------------------------------------------------------------------ cópia local (IndexedDB) */

/** Armazenamento das cópias não enviadas no IndexedDB do navegador. */
export function armazenamentoIndexedDB(nomeBanco = 'rankly-editor', loja = 'pendentes') {
  let banco = null;
  function abrir() {
    if (!banco) {
      banco = new Promise((resolver, rejeitar) => {
        if (typeof indexedDB === 'undefined') {
          rejeitar(new Error('IndexedDB indisponível'));
          return;
        }
        const req = indexedDB.open(nomeBanco, 1);
        req.onupgradeneeded = () => req.result.createObjectStore(loja);
        req.onsuccess = () => resolver(req.result);
        req.onerror = () => rejeitar(req.error);
      }).catch((e) => {
        banco = null;
        throw e;
      });
    }
    return banco;
  }
  async function operar(modo, fn) {
    const db = await abrir();
    return new Promise((resolver, rejeitar) => {
      const tx = db.transaction(loja, modo);
      const req = fn(tx.objectStore(loja));
      tx.oncomplete = () => resolver(req?.result ?? null);
      tx.onerror = () => rejeitar(tx.error);
      tx.onabort = () => rejeitar(tx.error);
    });
  }
  return {
    guardar: (chave, valor) => operar('readwrite', (st) => st.put(valor, `site:${chave}`)),
    ler: (chave) => operar('readonly', (st) => st.get(`site:${chave}`)),
    apagar: (chave) => operar('readwrite', (st) => st.delete(`site:${chave}`)),
  };
}

/** Armazenamento em memória (testes e navegadores sem IndexedDB). */
export function armazenamentoMemoria() {
  const mapa = new Map();
  return {
    mapa,
    async guardar(chave, valor) {
      mapa.set(String(chave), clonar(valor));
    },
    async ler(chave) {
      return mapa.has(String(chave)) ? clonar(mapa.get(String(chave))) : null;
    },
    async apagar(chave) {
      mapa.delete(String(chave));
    },
  };
}

let armazenamentoCompartilhado = null;
function armazenamentoPadrao() {
  if (!armazenamentoCompartilhado) {
    armazenamentoCompartilhado = typeof indexedDB !== 'undefined' ? armazenamentoIndexedDB() : armazenamentoMemoria();
  }
  return armazenamentoCompartilhado;
}

/* ------------------------------------------------------------------ estado */

function normalizarEntrada(entrada) {
  const e = entrada && typeof entrada === 'object' ? entrada : {};
  if (e.site && typeof e.site === 'object' && e.site.documento) {
    // resposta do GET /api/sites/{id}: { site: {...}, midia }
    return { ...e, siteId: e.site.id, doc: e.site.documento, revisao: e.site.revisao, midia: e.midia ?? {}, site: e.site };
  }
  return {
    ...e,
    siteId: e.siteId ?? e.id,
    doc: e.doc ?? e.documento,
    revisao: e.revisao,
    midia: e.midia ?? {},
    site: e.site ?? null,
  };
}

export class EstadoEditor {
  /**
   * entrada: { siteId, doc, revisao, midia, site? } — ou a resposta do GET /api/sites/{id}.
   * opcoes (no mesmo objeto ou no segundo argumento): api, armazenamento, janela (window),
   * documento (document), conflitoPadrao (true = janela de conflito quando ninguém assinou).
   */
  constructor(entrada = {}, opcoes = {}) {
    const e = { ...normalizarEntrada(entrada), ...opcoes };
    if (e.siteId === undefined || e.siteId === null || e.siteId === '') throw new TypeError('EstadoEditor: siteId é obrigatório.');
    if (!e.doc || typeof e.doc !== 'object') throw new TypeError('EstadoEditor: documento ausente.');
    this.siteId = typeof e.siteId === 'number' ? e.siteId : (/^\d+$/.test(String(e.siteId)) ? Number(e.siteId) : e.siteId);
    this.site = e.site ?? null;
    this._doc = e.doc;
    this._revisao = Number.isInteger(e.revisao) ? e.revisao : Number(e.revisao) || 0;
    this._midia = e.midia && typeof e.midia === 'object' && !Array.isArray(e.midia) ? { ...e.midia } : {};
    this._api = e.api ?? apiPadrao;
    this._local = e.armazenamento ?? armazenamentoPadrao();
    this._janela = e.janela !== undefined ? e.janela : (typeof window !== 'undefined' ? window : null);
    this._documento = e.documento !== undefined ? e.documento : (typeof document !== 'undefined' ? document : null);
    this._conflitoPadrao = e.conflitoPadrao ?? (this._documento !== null);

    this._passado = [];
    this._futuro = [];
    this._grupo = null;
    this._ouvintes = new Set();
    this._ouvintesConflito = new Set();
    this._status = STATUS.SALVO;
    this._pendente = false;
    this._versaoLocal = 0;
    this._emVoo = null;
    this._salvarDeNovo = false;
    this._timerSalvar = null;
    this._timerTentativa = null;
    this._timerCopia = null;
    this._conflito = null;
    this._ultimoErro = null;
    this._destruido = false;

    // Troca de modelo feita no assistente (#/site/{id}/modelo) entra no histórico [PDF §6.2].
    const semente = sementes.get(String(this.siteId));
    if (semente) {
      sementes.delete(String(this.siteId));
      if (semente.revisao === this._revisao && semente.antes) this._passado.push({ doc: semente.antes, rotulo: semente.rotulo });
    }

    this._aoSairDaPagina = (ev) => {
      if (this._pendente || this._emVoo) {
        this._guardarCopiaLocal();
        ev.preventDefault();
        ev.returnValue = '';
        return '';
      }
      return undefined;
    };
    this._aoMudarVisibilidade = () => {
      if (this._documento?.visibilityState === 'hidden') this._salvarAgora();
    };
    this._aoEsconderPagina = () => this._salvarAgora();
    this._aoVoltarConexao = () => {
      if (this._pendente && (this._status === STATUS.OFFLINE || this._status === STATUS.ERRO)) this.salvar();
    };
    this._janela?.addEventListener?.('beforeunload', this._aoSairDaPagina);
    this._janela?.addEventListener?.('pagehide', this._aoEsconderPagina);
    this._janela?.addEventListener?.('online', this._aoVoltarConexao);
    this._documento?.addEventListener?.('visibilitychange', this._aoMudarVisibilidade);

    const anterior = instanciasAtivas.get(String(this.siteId));
    if (anterior && anterior !== this) anterior.destruir();
    instanciasAtivas.set(String(this.siteId), this);

    /** Promessa resolvida depois de conferir a cópia local (alterações que não chegaram ao servidor). */
    this.pronto = this._recuperarCopiaLocal();
  }

  /** GET /api/sites/{id} e cria o estado (reaproveita a instância viva do mesmo site, se houver). */
  static async carregar(siteId, opcoes = {}) {
    const viva = instanciasAtivas.get(String(siteId));
    if (viva && !viva._destruido && opcoes.reutilizar !== false) return viva;
    const cliente = opcoes.api ?? apiPadrao;
    const r = await cliente.get(`/sites/${encodeURIComponent(siteId)}`);
    const estado = new EstadoEditor(r, opcoes);
    await estado.pronto;
    return estado;
  }

  /** Instância viva (editor aberto) deste site, ou null. */
  static ativo(siteId) {
    const e = instanciasAtivas.get(String(siteId));
    return e && !e._destruido ? e : null;
  }

  /**
   * Registra um passo de histórico feito fora do editor (ex.: troca de modelo no assistente):
   * o próximo EstadoEditor deste site, se abrir na revisão `revisao`, começa podendo desfazê-lo.
   */
  static semearHistorico(siteId, { antes, revisao, rotulo = 'Trocar modelo' }) {
    sementes.set(String(siteId), { antes, revisao, rotulo });
  }

  /* ---------------------------------------------------------------- leitura */

  get doc() { return this._doc; }
  get revisao() { return this._revisao; }
  get midia() { return this._midia; }
  get status() { return this._status; }
  get textoStatus() { return TEXTO_STATUS[this._status] ?? ''; }
  get pendente() { return this._pendente; }
  get erro() { return this._ultimoErro; }
  get conflito() { return this._conflito; }
  get podeDesfazer() { return this._passado.length > 0; }
  get podeRefazer() { return this._futuro.length > 0; }
  get passosDesfazer() { return this._passado.length; }
  get passosRefazer() { return this._futuro.length; }
  get rotuloDesfazer() { return this._passado.length ? this._passado[this._passado.length - 1].rotulo : null; }
  get rotuloRefazer() { return this._futuro.length ? this._futuro[this._futuro.length - 1].rotulo : null; }

  /* ---------------------------------------------------------------- alterações */

  /**
   * Aplica uma alteração. fn(copia) → novo documento (ou a própria cópia alterada, ou nada).
   * opcoes: { rotulo, historico = true, agrupar = null }. Devolve true se algo mudou.
   */
  aplicar(fn, { rotulo = '', historico = true, agrupar = null } = {}) {
    const novo = calcularNovoDocumento(this._doc, fn);
    if (novo === null) return false;
    const chaveGrupo = agrupar === true ? (rotulo || null) : (agrupar || null);
    if (historico) {
      const continua = chaveGrupo !== null && this._grupo === chaveGrupo && this._passado.length > 0;
      if (!continua) {
        this._passado.push({ doc: this._doc, rotulo });
        if (this._passado.length > PASSOS_HISTORICO) this._passado.splice(0, this._passado.length - PASSOS_HISTORICO);
      }
      this._futuro = [];
      this._grupo = chaveGrupo;
    }
    this._doc = novo;
    this._alterado({ tipo: 'doc', rotulo, origem: 'aplicar' });
    return true;
  }

  /** Fecha o grupo atual: a próxima alteração vira um passo novo (ex.: ao sair de um campo). */
  encerrarGrupo() {
    this._grupo = null;
  }

  desfazer() {
    if (this._passado.length === 0) return null;
    const passo = this._passado.pop();
    this._futuro.push({ doc: this._doc, rotulo: passo.rotulo });
    this._doc = passo.doc;
    this._grupo = null;
    this._alterado({ tipo: 'doc', rotulo: passo.rotulo, origem: 'desfazer' });
    return { rotulo: passo.rotulo };
  }

  refazer() {
    if (this._futuro.length === 0) return null;
    const passo = this._futuro.pop();
    this._passado.push({ doc: this._doc, rotulo: passo.rotulo });
    if (this._passado.length > PASSOS_HISTORICO) this._passado.shift();
    this._doc = passo.doc;
    this._grupo = null;
    this._alterado({ tipo: 'doc', rotulo: passo.rotulo, origem: 'refazer' });
    return { rotulo: passo.rotulo };
  }

  limparHistorico() {
    this._passado = [];
    this._futuro = [];
    this._grupo = null;
    this._emitir({ tipo: 'doc', origem: 'historico' });
  }

  /** Atualiza (mescla) os dados de uma mídia — ex.: { local: "blob:…" } durante o envio. null remove. */
  definirMidia(id, dados) {
    const novo = { ...this._midia };
    if (dados === null) delete novo[id];
    else novo[id] = { ...(novo[id] ?? {}), ...dados };
    this._midia = novo;
    this._emitir({ tipo: 'midia', id });
  }

  /** Recebe avisos de mudança. Devolve a função que cancela a assinatura. */
  assinar(fn) {
    this._ouvintes.add(fn);
    return () => this._ouvintes.delete(fn);
  }

  /** Recebe os conflitos de salvamento (409). Devolve a função que cancela. */
  aoConflito(fn) {
    this._ouvintesConflito.add(fn);
    return () => this._ouvintesConflito.delete(fn);
  }

  /* ---------------------------------------------------------------- salvamento */

  /** Envia agora o que estiver pendente. Resolve true quando o servidor tem a versão atual. */
  salvar({ keepalive = false } = {}) {
    clearTimeout(this._timerSalvar);
    this._timerSalvar = null;
    if (!this._pendente) return Promise.resolve(true);
    if (this._status === STATUS.CONFLITO) return Promise.resolve(false);
    if (this._emVoo) {
      this._salvarDeNovo = true;
      return this._emVoo;
    }
    clearTimeout(this._timerTentativa);
    this._timerTentativa = null;
    const doc = this._doc;
    const versao = this._versaoLocal;
    if (this._status !== STATUS.OFFLINE) this._definirStatus(STATUS.SALVANDO);
    let usarKeepalive = false;
    if (keepalive) {
      try {
        usarKeepalive = JSON.stringify(doc).length < LIMITE_KEEPALIVE;
      } catch {
        usarKeepalive = false;
      }
    }
    this._emVoo = (async () => {
      try {
        const r = await this._api.put(`/sites/${encodeURIComponent(this.siteId)}`, { revisao: this._revisao, documento: doc }, usarKeepalive ? { keepalive: true } : undefined);
        if (r && Number.isInteger(r.revisao)) this._revisao = r.revisao;
        this._ultimoErro = null;
        if (this._versaoLocal === versao) {
          this._pendente = false;
          clearTimeout(this._timerCopia);
          this._timerCopia = null;
          this._definirStatus(STATUS.SALVO);
          this._apagarCopiaLocal();
        } else {
          this._definirStatus(STATUS.SALVANDO);
        }
        this._emitir({ tipo: 'revisao', revisao: this._revisao });
        return true;
      } catch (erro) {
        return this._tratarFalha(erro);
      } finally {
        this._emVoo = null;
        if (this._salvarDeNovo) {
          this._salvarDeNovo = false;
          if (this._pendente && this._status === STATUS.SALVANDO) this._agendarSalvar(0);
        }
      }
    })();
    return this._emVoo;
  }

  _tratarFalha(erro) {
    if (erro?.name === 'AbortError') {
      this._definirStatus(STATUS.ERRO);
      return false;
    }
    this._ultimoErro = erro;
    if (erro?.status === 409) {
      const dados = erro.dados && typeof erro.dados === 'object' ? erro.dados : {};
      this._conflito = {
        origem: 'servidor',
        revisaoAtual: Number.isInteger(dados.revisaoAtual) ? dados.revisaoAtual : null,
        documento: dados.documento && typeof dados.documento === 'object' ? dados.documento : null,
      };
      this._definirStatus(STATUS.CONFLITO);
      this._guardarCopiaLocal();
      this._avisarConflito();
      return false;
    }
    const offline = erro?.status === 0 || (typeof navigator !== 'undefined' && navigator.onLine === false);
    this._definirStatus(offline ? STATUS.OFFLINE : STATUS.ERRO);
    this._guardarCopiaLocal();
    // Repete sozinho quando faz sentido (rede, servidor, sessão, limite); 4xx de validação espera nova alteração.
    const st = erro?.status ?? 0;
    if (!this._destruido && (offline || st >= 500 || st === 401 || st === 408 || st === 429)) this._agendarNovaTentativa();
    return false;
  }

  _agendarSalvar(espera = ESPERA_SALVAR_MS) {
    clearTimeout(this._timerSalvar);
    this._timerSalvar = setTimeout(() => {
      this._timerSalvar = null;
      this.salvar();
    }, espera);
  }

  _agendarNovaTentativa() {
    clearTimeout(this._timerTentativa);
    this._timerTentativa = setTimeout(() => {
      this._timerTentativa = null;
      if (this._pendente && (this._status === STATUS.OFFLINE || this._status === STATUS.ERRO)) this.salvar();
    }, INTERVALO_NOVA_TENTATIVA_MS);
  }

  _salvarAgora() {
    if (!this._pendente) return;
    this._guardarCopiaLocal();
    if (this._status === STATUS.SALVANDO || this._status === STATUS.ERRO) this.salvar({ keepalive: true });
  }

  _alterado(evento) {
    this._versaoLocal += 1;
    this._pendente = true;
    if (this._status === STATUS.OFFLINE || this._status === STATUS.CONFLITO) {
      // O envio fica com a nova tentativa (offline) ou com a decisão do conflito; guarda a cópia.
      this._agendarCopiaLocal();
    } else {
      this._definirStatus(STATUS.SALVANDO);
      this._agendarSalvar();
    }
    this._emitir(evento);
  }

  _definirStatus(status) {
    if (this._status === status) return;
    this._status = status;
    this._emitir({ tipo: 'status', status });
  }

  _emitir(evento) {
    for (const fn of [...this._ouvintes]) {
      try {
        fn(evento, this);
      } catch (e) {
        console.error(e);
      }
    }
  }

  /* ---------------------------------------------------------------- conflito */

  _avisarConflito() {
    const c = this._conflito;
    // Depois de destruído (editor fechado), a cópia local fica para a próxima abertura.
    if (!c || this._destruido) return;
    const info = {
      origem: c.origem,
      revisaoAtual: c.revisaoAtual,
      documento: c.documento,
      salvoEm: c.salvoEm ?? null,
      carregarNova: () => this.carregarVersaoNova(),
      manterMinha: () => this.manterMinhaVersao(),
    };
    if (this._ouvintesConflito.size > 0) {
      for (const fn of [...this._ouvintesConflito]) {
        try {
          fn(info, this);
        } catch (e) {
          console.error(e);
        }
      }
    } else if (this._conflitoPadrao) {
      janelaConflitoPadrao(info);
    }
  }

  /** "Carregar a versão mais nova": adota o documento do servidor (desfazível) e descarta a cópia local. */
  async carregarVersaoNova() {
    const c = this._conflito;
    let documento = c?.documento ?? null;
    let revisao = c?.revisaoAtual ?? null;
    if (!documento || revisao === null) {
      const r = await this._api.get(`/sites/${encodeURIComponent(this.siteId)}`);
      documento = r.site.documento;
      revisao = r.site.revisao;
      if (r.midia && typeof r.midia === 'object') this._midia = { ...r.midia };
    }
    this._conflito = null;
    clearTimeout(this._timerSalvar);
    clearTimeout(this._timerTentativa);
    clearTimeout(this._timerCopia);
    this._timerSalvar = this._timerTentativa = this._timerCopia = null;
    this._revisao = revisao;
    if (!iguais(documento, this._doc)) {
      this._passado.push({ doc: this._doc, rotulo: 'Carregar a versão mais nova' });
      if (this._passado.length > PASSOS_HISTORICO) this._passado.shift();
      this._futuro = [];
      this._grupo = null;
      this._doc = documento;
    }
    this._pendente = false;
    this._versaoLocal += 1;
    this._ultimoErro = null;
    this._definirStatus(STATUS.SALVO);
    await this._apagarCopiaLocal();
    this._emitir({ tipo: 'doc', rotulo: 'Carregar a versão mais nova', origem: 'servidor' });
    this._emitir({ tipo: 'revisao', revisao: this._revisao });
    return true;
  }

  /** "Manter a minha": reenvia o documento atual com a revisão mais nova do servidor. */
  manterMinhaVersao() {
    const c = this._conflito;
    if (c && Number.isInteger(c.revisaoAtual)) this._revisao = c.revisaoAtual;
    this._conflito = null;
    this._pendente = true;
    this._definirStatus(STATUS.SALVANDO);
    return this.salvar();
  }

  /* ---------------------------------------------------------------- cópia local */

  _agendarCopiaLocal() {
    clearTimeout(this._timerCopia);
    this._timerCopia = setTimeout(() => {
      this._timerCopia = null;
      this._guardarCopiaLocal();
    }, ESPERA_COPIA_LOCAL_MS);
  }

  _guardarCopiaLocal() {
    if (!this._pendente) return Promise.resolve();
    const registro = { doc: this._doc, revisao: this._revisao, salvoEm: new Date().toISOString() };
    return Promise.resolve()
      .then(() => this._local.guardar(this.siteId, registro))
      .catch(() => { /* sem IndexedDB: nada a fazer */ });
  }

  _apagarCopiaLocal() {
    return Promise.resolve()
      .then(() => this._local.apagar(this.siteId))
      .catch(() => {});
  }

  /**
   * Na abertura: se houver uma cópia local diferente do servidor (alterações que não chegaram),
   * ela é reaplicada (desfazível) e enviada. Se a cópia foi feita sobre uma revisão antiga, vira
   * um conflito ("Carregar a versão mais nova" descarta a cópia; "Manter a minha" a envia).
   */
  async _recuperarCopiaLocal() {
    let copia = null;
    try {
      copia = await this._local.ler(this.siteId);
    } catch {
      return null;
    }
    if (!copia || !copia.doc || typeof copia.doc !== 'object' || this._destruido) return null;
    if (iguais(copia.doc, this._doc)) {
      await this._apagarCopiaLocal();
      return null;
    }
    if (copia.revisao === this._revisao) {
      const docServidor = this._doc;
      this._passado.push({ doc: docServidor, rotulo: 'Recuperar alterações não salvas' });
      this._futuro = [];
      this._doc = copia.doc;
      this._alterado({ tipo: 'doc', rotulo: 'Recuperar alterações não salvas', origem: 'copia-local' });
      return { recuperado: true, salvoEm: copia.salvoEm ?? null };
    }
    // Cópia feita sobre uma versão antiga: o servidor tem mudanças que a cópia não conhece.
    const docServidor = this._doc;
    this._passado.push({ doc: docServidor, rotulo: 'Recuperar alterações não salvas' });
    this._futuro = [];
    this._doc = copia.doc;
    this._pendente = true;
    this._versaoLocal += 1;
    this._conflito = { origem: 'copia-local', revisaoAtual: this._revisao, documento: docServidor, salvoEm: copia.salvoEm ?? null };
    this._definirStatus(STATUS.CONFLITO);
    this._emitir({ tipo: 'doc', rotulo: 'Recuperar alterações não salvas', origem: 'copia-local' });
    this._avisarConflito();
    return { recuperado: true, conflito: true, salvoEm: copia.salvoEm ?? null };
  }

  /* ---------------------------------------------------------------- fim */

  /**
   * Remove os ouvintes da página e para os temporizadores. Se houver alteração pendente,
   * dispara o envio (e guarda a cópia local) antes de sair.
   */
  destruir() {
    if (this._destruido) return;
    this._destruido = true;
    this._janela?.removeEventListener?.('beforeunload', this._aoSairDaPagina);
    this._janela?.removeEventListener?.('pagehide', this._aoEsconderPagina);
    this._janela?.removeEventListener?.('online', this._aoVoltarConexao);
    this._documento?.removeEventListener?.('visibilitychange', this._aoMudarVisibilidade);
    clearTimeout(this._timerTentativa);
    clearTimeout(this._timerCopia);
    this._timerTentativa = null;
    this._timerCopia = null;
    if (instanciasAtivas.get(String(this.siteId)) === this) instanciasAtivas.delete(String(this.siteId));
    this._ouvintes.clear();
    if (this._pendente) {
      this._guardarCopiaLocal();
      if (this._status !== STATUS.CONFLITO) this.salvar();
    } else {
      clearTimeout(this._timerSalvar);
      this._timerSalvar = null;
    }
  }
}

/** Janela padrão de conflito (quando a tela não assinou aoConflito). */
async function janelaConflitoPadrao(info) {
  try {
    const { modal, el, aviso } = await import('./ui.mjs');
    const copiaLocal = info.origem === 'copia-local';
    modal({
      titulo: copiaLocal ? 'Há alterações não salvas deste site' : 'Este site foi alterado em outra janela',
      descricao: copiaLocal
        ? 'Encontramos alterações feitas neste navegador que não chegaram ao servidor, e o site mudou depois disso.'
        : 'Outra janela (ou outra pessoa) salvou uma versão mais nova enquanto você editava.',
      corpo: el('p', { class: 'modal__texto' },
        '"Carregar a versão mais nova" descarta as suas alterações (você ainda pode usar Desfazer). '
        + '"Manter a minha" grava a sua versão por cima da outra.'),
      fecharAoClicarFora: false,
      acoes: [
        {
          rotulo: 'Carregar a versão mais nova',
          tipo: 'secundario',
          fn: async () => {
            await info.carregarNova();
            aviso('Versão mais nova carregada.', { tipo: 'ok' });
          },
        },
        {
          rotulo: 'Manter a minha',
          tipo: 'primario',
          foco: true,
          fn: async () => {
            await info.manterMinha();
          },
        },
      ],
      aoFechar: (resultado) => {
        // Fechar sem escolher (Esc ou ×) mantém o conflito: o aviso fica até a pessoa decidir.
        if (resultado === undefined) {
          aviso('As alterações não estão sendo salvas até você escolher uma versão.', {
            tipo: 'erro', duracao: 0, acao: { rotulo: 'Escolher', fn: () => janelaConflitoPadrao(info) },
          });
        }
      },
    });
  } catch (e) {
    console.error(e);
  }
}
