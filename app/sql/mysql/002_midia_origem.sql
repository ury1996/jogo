-- De onde veio a foto (upload | exemplo:{foto} | pexels:{id}) e crédito do autor (bancos de imagens).
ALTER TABLE midia ADD COLUMN origem VARCHAR(40) NULL;
ALTER TABLE midia ADD COLUMN credito VARCHAR(200) NULL;
