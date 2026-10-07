-- Construtor Rankly · esquema inicial (SQLite, usado nos testes), equivalente a mysql/001_inicial.sql.

CREATE TABLE usuarios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  senha_hash TEXT NOT NULL,
  papel TEXT NOT NULL DEFAULT 'equipe' CHECK (papel IN ('admin', 'equipe', 'cliente')),
  ativo INTEGER NOT NULL DEFAULT 1,
  totp_segredo TEXT NULL,
  criado_em TEXT NOT NULL,
  ultimo_login_em TEXT NULL
);

CREATE TABLE redefinicoes_senha (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  expira_em TEXT NOT NULL,
  usado_em TEXT NULL
);
CREATE INDEX ix_redefinicoes_usuario ON redefinicoes_senha (usuario_id);

CREATE TABLE sites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  dono_id INTEGER NULL REFERENCES usuarios (id) ON DELETE SET NULL,
  slug TEXT NOT NULL UNIQUE,
  nome TEXT NOT NULL,
  nicho TEXT NOT NULL,
  modelo TEXT NOT NULL,
  documento TEXT NOT NULL,
  revisao INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho', 'publicado', 'arquivado')),
  publicado_versao INTEGER NULL,
  publicado_em TEXT NULL,
  criado_em TEXT NOT NULL,
  atualizado_em TEXT NOT NULL,
  atualizado_por INTEGER NULL REFERENCES usuarios (id) ON DELETE SET NULL
);
CREATE INDEX ix_sites_status ON sites (status, atualizado_em);

CREATE TABLE site_acessos (
  site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
  papel TEXT NOT NULL DEFAULT 'editor' CHECK (papel IN ('dono', 'editor')),
  PRIMARY KEY (site_id, usuario_id)
);
CREATE INDEX ix_site_acessos_usuario ON site_acessos (usuario_id);

CREATE TABLE versoes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
  numero INTEGER NOT NULL,
  documento TEXT NOT NULL,
  resolvidos TEXT NULL,
  biblioteca_versao TEXT NULL,
  `release` TEXT NULL,
  publicado_por INTEGER NULL REFERENCES usuarios (id) ON DELETE SET NULL,
  publicado_em TEXT NOT NULL,
  UNIQUE (site_id, numero)
);

CREATE TABLE midia (
  id TEXT PRIMARY KEY,
  site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
  tipo TEXT NOT NULL CHECK (tipo IN ('foto', 'logo')),
  mime TEXT NOT NULL,
  largura INTEGER NOT NULL,
  altura INTEGER NOT NULL,
  bytes INTEGER NOT NULL,
  variantes TEXT NOT NULL,
  hash TEXT NOT NULL,
  texto_alt TEXT NULL,
  formato TEXT NOT NULL DEFAULT 'webp' CHECK (formato IN ('webp', 'svg')),
  criado_em TEXT NOT NULL
);
CREATE INDEX ix_midia_site_hash ON midia (site_id, hash);
CREATE INDEX ix_midia_criado ON midia (criado_em);

CREATE TABLE dominios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
  dominio TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'verificado', 'ativo')),
  ssl_ate TEXT NULL,
  criado_em TEXT NOT NULL,
  verificado_em TEXT NULL
);
CREATE INDEX ix_dominios_site ON dominios (site_id);

CREATE TABLE leads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
  nome TEXT NOT NULL,
  telefone TEXT NOT NULL,
  email TEXT NULL,
  mensagem TEXT NULL,
  origem TEXT NULL,
  ip_hash TEXT NOT NULL,
  criado_em TEXT NOT NULL,
  lido_em TEXT NULL
);
CREATE INDEX ix_leads_site ON leads (site_id, criado_em);
CREATE INDEX ix_leads_criado ON leads (criado_em);

CREATE TABLE tarefas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tipo TEXT NOT NULL,
  payload TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'executando', 'feita', 'falhou')),
  tentativas INTEGER NOT NULL DEFAULT 0,
  executar_em TEXT NOT NULL,
  erro TEXT NULL,
  criado_em TEXT NOT NULL,
  atualizado_em TEXT NOT NULL
);
CREATE INDEX ix_tarefas_fila ON tarefas (status, executar_em);
CREATE INDEX ix_tarefas_tipo ON tarefas (tipo, status);

CREATE TABLE eventos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NULL REFERENCES sites (id) ON DELETE CASCADE,
  usuario_id INTEGER NULL REFERENCES usuarios (id) ON DELETE SET NULL,
  tipo TEXT NOT NULL,
  detalhe TEXT NULL,
  criado_em TEXT NOT NULL
);
CREATE INDEX ix_eventos_site ON eventos (site_id, criado_em);

CREATE TABLE limites (
  chave TEXT PRIMARY KEY,
  contagem INTEGER NOT NULL DEFAULT 0,
  janela_inicio TEXT NOT NULL
);
