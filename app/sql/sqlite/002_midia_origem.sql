-- De onde veio a foto (upload | exemplo:{foto} | pixabay:{id}) e crédito do autor (bancos de imagens).
ALTER TABLE midia ADD COLUMN origem TEXT NULL;
ALTER TABLE midia ADD COLUMN credito TEXT NULL;
