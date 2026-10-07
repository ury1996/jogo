-- Construtor Rankly · esquema inicial (MySQL 8 / MariaDB 10.6+), contrato §6.2.
-- Datas sempre em UTC, passadas pelo PHP ("Y-m-d H:i:s"). Documentos JSON em LONGTEXT
-- (o tipo JSON do MySQL 8 reordena as chaves; LONGTEXT preserva o documento como enviado).

CREATE TABLE usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  papel VARCHAR(10) NOT NULL DEFAULT 'equipe',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  totp_segredo VARCHAR(64) NULL,
  criado_em DATETIME NOT NULL,
  ultimo_login_em DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  CONSTRAINT ck_usuarios_papel CHECK (papel IN ('admin', 'equipe', 'cliente'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redefinicoes_senha (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira_em DATETIME NOT NULL,
  usado_em DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redefinicoes_token (token_hash),
  KEY ix_redefinicoes_usuario (usuario_id),
  CONSTRAINT fk_redefinicoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  dono_id INT UNSIGNED NULL,
  slug VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  nicho VARCHAR(40) NOT NULL,
  modelo VARCHAR(40) NOT NULL,
  documento LONGTEXT NOT NULL,
  revisao INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'rascunho',
  publicado_versao INT UNSIGNED NULL,
  publicado_em DATETIME NULL,
  criado_em DATETIME NOT NULL,
  atualizado_em DATETIME NOT NULL,
  atualizado_por INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_slug (slug),
  KEY ix_sites_status (status, atualizado_em),
  CONSTRAINT ck_sites_status CHECK (status IN ('rascunho', 'publicado', 'arquivado')),
  CONSTRAINT fk_sites_dono FOREIGN KEY (dono_id) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_sites_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_acessos (
  site_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  papel VARCHAR(10) NOT NULL DEFAULT 'editor',
  PRIMARY KEY (site_id, usuario_id),
  KEY ix_site_acessos_usuario (usuario_id),
  CONSTRAINT ck_site_acessos_papel CHECK (papel IN ('dono', 'editor')),
  CONSTRAINT fk_site_acessos_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
  CONSTRAINT fk_site_acessos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE versoes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  numero INT UNSIGNED NOT NULL,
  documento LONGTEXT NOT NULL,
  resolvidos LONGTEXT NULL,
  biblioteca_versao VARCHAR(64) NULL,
  `release` VARCHAR(190) NULL,
  publicado_por INT UNSIGNED NULL,
  publicado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_versoes_site_numero (site_id, numero),
  CONSTRAINT fk_versoes_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
  CONSTRAINT fk_versoes_usuario FOREIGN KEY (publicado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE midia (
  id VARCHAR(16) NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(8) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  largura INT UNSIGNED NOT NULL,
  altura INT UNSIGNED NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  variantes TEXT NOT NULL,
  hash CHAR(64) NOT NULL,
  texto_alt VARCHAR(255) NULL,
  formato VARCHAR(8) NOT NULL DEFAULT 'webp',
  criado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_midia_site_hash (site_id, hash),
  KEY ix_midia_criado (criado_em),
  CONSTRAINT ck_midia_tipo CHECK (tipo IN ('foto', 'logo')),
  CONSTRAINT ck_midia_formato CHECK (formato IN ('webp', 'svg')),
  CONSTRAINT fk_midia_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dominios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  dominio VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pendente',
  ssl_ate DATETIME NULL,
  criado_em DATETIME NOT NULL,
  verificado_em DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dominios_dominio (dominio),
  KEY ix_dominios_site (site_id),
  CONSTRAINT ck_dominios_status CHECK (status IN ('pendente', 'verificado', 'ativo')),
  CONSTRAINT fk_dominios_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  telefone VARCHAR(30) NOT NULL,
  email VARCHAR(190) NULL,
  mensagem TEXT NULL,
  origem TEXT NULL,
  ip_hash CHAR(64) NOT NULL,
  criado_em DATETIME NOT NULL,
  lido_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_leads_site (site_id, criado_em),
  KEY ix_leads_criado (criado_em),
  CONSTRAINT fk_leads_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tarefas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(40) NOT NULL,
  payload LONGTEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pendente',
  tentativas INT UNSIGNED NOT NULL DEFAULT 0,
  executar_em DATETIME NOT NULL,
  erro TEXT NULL,
  criado_em DATETIME NOT NULL,
  atualizado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tarefas_fila (status, executar_em),
  KEY ix_tarefas_tipo (tipo, status),
  CONSTRAINT ck_tarefas_status CHECK (status IN ('pendente', 'executando', 'feita', 'falhou'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  tipo VARCHAR(40) NOT NULL,
  detalhe TEXT NULL,
  criado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_eventos_site (site_id, criado_em),
  CONSTRAINT fk_eventos_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
  CONSTRAINT fk_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE limites (
  chave VARCHAR(190) NOT NULL,
  contagem INT UNSIGNED NOT NULL DEFAULT 0,
  janela_inicio DATETIME NOT NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
