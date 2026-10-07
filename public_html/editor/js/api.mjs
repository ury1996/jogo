// Cliente da API (/api/*) — contrato §6 e §9.
//
//   api.get(p)  api.post(p, corpo)  api.put(p, corpo)  api.patch(p, corpo)  api.del(p)
//   api.enviarArquivo(p, formData, aoProgresso)   (XHR, com progresso do envio 0–1)
//
// `p` pode vir com ou sem o prefixo: "/sites" e "/api/sites" são o mesmo endereço.
// Erros sempre chegam como ErroApi { status, codigo, mensagem, dados } — `mensagem` já
// está pronta para mostrar ao usuário; `dados` é o corpo inteiro da resposta (ex.: o 409
// do salvamento traz `revisaoAtual` e `documento`). Sem conexão: status 0, codigo
// "sem_conexao". O token CSRF recebido em /auth/eu e /auth/login é guardado e enviado
// em toda requisição que altera dados (cabeçalho X-CSRF-Token).

const PREFIXO = '/api';

// Rotas de autenticação: um 401 nelas é resposta normal (não dispara "sessão expirou").
const ROTAS_AUTH = /^\/api\/auth\/(login|eu|esqueci|redefinir)(\?|$)/;

const MENSAGENS_PADRAO = {
  0: 'Sem conexão com o servidor. Verifique a internet e tente de novo.',
  400: 'Não foi possível concluir o pedido.',
  401: 'Sua sessão expirou. Entre de novo para continuar.',
  403: 'Você não tem permissão para fazer isso.',
  404: 'Não encontramos o que você procurava.',
  409: 'Este conteúdo foi alterado em outra janela.',
  413: 'O arquivo é grande demais.',
  422: 'Confira os dados informados.',
  429: 'Muitas tentativas seguidas. Aguarde um pouco e tente de novo.',
  500: 'O servidor teve um problema. Tente de novo em instantes.',
};

export class ErroApi extends Error {
  constructor({ status = 0, codigo = 'erro', mensagem = '', dados = null } = {}) {
    const texto = mensagem || mensagemPadrao(status);
    super(texto);
    this.name = 'ErroApi';
    this.status = status;
    this.codigo = codigo;
    this.mensagem = texto;
    this.dados = dados;
  }

  /** Falha de rede (o pedido nem chegou ao servidor). */
  get semConexao() {
    return this.status === 0;
  }
}

function mensagemPadrao(status) {
  if (MENSAGENS_PADRAO[status]) return MENSAGENS_PADRAO[status];
  if (status >= 500) return MENSAGENS_PADRAO[500];
  return MENSAGENS_PADRAO[400];
}

let csrf = null;
const ouvintes401 = new Set();

/** Caminho completo: "/sites" → "/api/sites"; "/api/sites" e URLs absolutas ficam como estão. */
export function caminho(p) {
  const s = String(p ?? '');
  if (/^https?:\/\//i.test(s)) return s;
  const comBarra = s.startsWith('/') ? s : `/${s}`;
  if (comBarra === PREFIXO || comBarra.startsWith(`${PREFIXO}/`) || comBarra.startsWith(`${PREFIXO}?`)) return comBarra;
  return PREFIXO + comBarra;
}

function guardarCsrf(dados) {
  if (dados && typeof dados === 'object' && typeof dados.csrf === 'string' && dados.csrf !== '') csrf = dados.csrf;
}

function erroDe(status, dados) {
  const e = dados && typeof dados === 'object' && dados.erro && typeof dados.erro === 'object' ? dados.erro : {};
  return new ErroApi({
    status,
    codigo: typeof e.codigo === 'string' ? e.codigo : `http_${status}`,
    mensagem: typeof e.mensagem === 'string' ? e.mensagem : '',
    dados: dados ?? null,
  });
}

function avisar401(caminhoCompleto, erro) {
  if (ROTAS_AUTH.test(caminhoCompleto)) return;
  for (const fn of ouvintes401) {
    try {
      fn(erro);
    } catch (e) {
      console.error(e);
    }
  }
}

async function lerCorpo(resp) {
  if (resp.status === 204 || resp.status === 304) return null;
  const tipo = resp.headers?.get?.('content-type') ?? '';
  if (tipo.includes('json')) {
    try {
      return await resp.json();
    } catch {
      return null;
    }
  }
  try {
    return await resp.text();
  } catch {
    return null;
  }
}

function semConexao() {
  return new ErroApi({ status: 0, codigo: 'sem_conexao' });
}

async function requisitar(metodo, p, corpo, opcoes = {}) {
  const url = caminho(p);
  const cabecalhos = { Accept: 'application/json' };
  let body;
  if (corpo !== undefined) {
    cabecalhos['Content-Type'] = 'application/json';
    body = JSON.stringify(corpo);
  }
  const altera = metodo !== 'GET' && metodo !== 'HEAD';
  if (altera && csrf) cabecalhos['X-CSRF-Token'] = csrf;

  let resp;
  try {
    resp = await fetch(url, {
      method: metodo,
      headers: cabecalhos,
      body,
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: opcoes.keepalive === true,
      signal: opcoes.sinal,
    });
  } catch (e) {
    if (e?.name === 'AbortError') throw e;
    throw semConexao();
  }
  const dados = await lerCorpo(resp);
  guardarCsrf(dados);
  if (resp.ok || resp.status === 304) return dados;

  const erro = erroDe(resp.status, dados);
  // CSRF desatualizado (ex.: outra aba entrou de novo): renova uma vez e repete.
  if (resp.status === 403 && erro.codigo === 'csrf' && altera && opcoes.repetiu !== true) {
    try {
      await requisitar('GET', '/auth/eu', undefined, { repetiu: true });
    } catch {
      /* a repetição abaixo mostra o erro real */
    }
    return requisitar(metodo, p, corpo, { ...opcoes, repetiu: true });
  }
  if (resp.status === 401) avisar401(url, erro);
  throw erro;
}

/**
 * Envia um FormData por XHR (para ter progresso do envio).
 * aoProgresso(fracao 0–1, evento) é chamado durante o envio.
 */
function enviarArquivo(p, formData, aoProgresso, opcoes = {}) {
  const url = caminho(p);
  return new Promise((resolver, rejeitar) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('Accept', 'application/json');
    if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
    if (typeof aoProgresso === 'function') {
      xhr.upload.addEventListener('progress', (ev) => {
        if (ev.lengthComputable && ev.total > 0) aoProgresso(Math.min(1, ev.loaded / ev.total), ev);
      });
    }
    xhr.addEventListener('load', () => {
      let dados = null;
      const tipo = xhr.getResponseHeader('content-type') ?? '';
      if (tipo.includes('json')) {
        try {
          dados = JSON.parse(xhr.responseText);
        } catch {
          dados = null;
        }
      } else {
        dados = xhr.responseText;
      }
      guardarCsrf(dados);
      if (xhr.status >= 200 && xhr.status < 300) {
        if (typeof aoProgresso === 'function') aoProgresso(1, null);
        resolver(dados);
        return;
      }
      const erro = erroDe(xhr.status, dados);
      if (xhr.status === 403 && erro.codigo === 'csrf' && opcoes.repetiu !== true) {
        requisitar('GET', '/auth/eu', undefined, { repetiu: true })
          .catch(() => null)
          .then(() => enviarArquivo(p, formData, aoProgresso, { ...opcoes, repetiu: true }))
          .then(resolver, rejeitar);
        return;
      }
      if (xhr.status === 401) avisar401(url, erro);
      rejeitar(erro);
    });
    xhr.addEventListener('error', () => rejeitar(semConexao()));
    xhr.addEventListener('timeout', () => rejeitar(semConexao()));
    xhr.addEventListener('abort', () => rejeitar(new DOMException('Envio cancelado.', 'AbortError')));
    if (opcoes.sinal) {
      if (opcoes.sinal.aborted) {
        xhr.abort();
        return;
      }
      opcoes.sinal.addEventListener('abort', () => xhr.abort(), { once: true });
    }
    xhr.send(formData);
  });
}

export const api = {
  get: (p, opcoes) => requisitar('GET', p, undefined, opcoes),
  post: (p, corpo = {}, opcoes) => requisitar('POST', p, corpo, opcoes),
  put: (p, corpo = {}, opcoes) => requisitar('PUT', p, corpo, opcoes),
  patch: (p, corpo = {}, opcoes) => requisitar('PATCH', p, corpo, opcoes),
  del: (p, opcoes) => requisitar('DELETE', p, undefined, opcoes),
  enviarArquivo,
  /** Endereço completo de uma rota (para links: CSV, imagens). */
  url: caminho,
  /** Token CSRF atual (ou null). */
  obterCsrf: () => csrf,
  definirCsrf(token) {
    csrf = typeof token === 'string' && token !== '' ? token : null;
  },
  /** Chamado quando qualquer rota (fora as de autenticação) responde 401. Devolve o cancelamento. */
  aoNaoAutenticado(fn) {
    ouvintes401.add(fn);
    return () => ouvintes401.delete(fn);
  },
};
api.delete = api.del;

export default api;
