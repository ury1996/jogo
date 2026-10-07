// Monta o bundle da biblioteca (§6.1) em Node, com a MESMA estrutura e a MESMA versão
// de Rankly\Preparo\Biblioteca::carregar() — usado pelos testes de paridade e de unidade.

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

function compararBytes(a, b) {
  return Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
}

/** Arquivos da biblioteca (caminhos relativos com "/"), sem ocultos, em ordem de bytes. */
export function listarArquivos(dir) {
  const arquivos = [];
  const visitar = (relativo) => {
    const absoluto = relativo === '' ? dir : path.join(dir, relativo);
    for (const nome of fs.readdirSync(absoluto)) {
      if (nome.startsWith('.')) continue;
      const rel = relativo === '' ? nome : `${relativo}/${nome}`;
      const info = fs.statSync(path.join(dir, rel));
      if (info.isDirectory()) visitar(rel);
      else if (info.isFile()) arquivos.push(rel);
    }
  };
  visitar('');
  return arquivos.sort(compararBytes);
}

/** sha1 de "caminho\0conteudo\0" de todos os arquivos, em ordem de caminho. */
export function versaoBiblioteca(dir) {
  const hash = crypto.createHash('sha1');
  for (const rel of listarArquivos(dir)) {
    hash.update(Buffer.from(rel, 'utf8'));
    hash.update(Buffer.from([0]));
    hash.update(fs.readFileSync(path.join(dir, rel)));
    hash.update(Buffer.from([0]));
  }
  return hash.digest('hex');
}

function lerJson(arquivo) {
  try {
    return JSON.parse(fs.readFileSync(arquivo, 'utf8'));
  } catch (erro) {
    throw new Error(`JSON inválido em ${arquivo}: ${erro.message}`);
  }
}

function lerTexto(arquivo) {
  return fs.existsSync(arquivo) && fs.statSync(arquivo).isFile() ? fs.readFileSync(arquivo, 'utf8') : '';
}

function lerJsonOpcional(arquivo) {
  return fs.existsSync(arquivo) ? lerJson(arquivo) : {};
}

function entradas(dir, filtro) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter((n) => !n.startsWith('.') && filtro(n)).sort(compararBytes);
}

function idDoJson(json, nomeArquivo) {
  return json && typeof json === 'object' && !Array.isArray(json) && typeof json.id === 'string' && json.id !== ''
    ? json.id
    : nomeArquivo.replace(/\.json$/, '');
}

/** Bundle da biblioteca: { versao, secoes, parciais, baseCss, modelos, nichos, comum, icones, fontes }. */
export function carregarBiblioteca(dir) {
  if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) throw new Error(`Biblioteca não encontrada: ${dir}`);
  const secoes = {};
  const dirSecoes = path.join(dir, 'secoes');
  for (const tipo of entradas(dirSecoes, (n) => fs.statSync(path.join(dirSecoes, n)).isDirectory())) {
    const base = path.join(dirSecoes, tipo);
    const arqManifest = path.join(base, 'manifest.json');
    if (!fs.existsSync(arqManifest)) continue;
    const manifest = lerJson(arqManifest);
    const templates = {};
    const opcoes = Array.isArray(manifest?.opcoes) ? manifest.opcoes : [];
    for (const opcao of opcoes) {
      const id = opcao && typeof opcao === 'object' && typeof opcao.id === 'string' ? opcao.id : '';
      if (!/^[a-z0-9-]+$/.test(id)) continue;
      const arquivo = path.join(base, `${id}.mustache`);
      if (fs.existsSync(arquivo)) templates[id] = fs.readFileSync(arquivo, 'utf8');
    }
    secoes[tipo] = { manifest, templates, css: lerTexto(path.join(base, 'estilo.css')) };
  }
  const parciais = {};
  const dirParciais = path.join(dir, 'parciais');
  for (const nome of entradas(dirParciais, (n) => n.endsWith('.mustache'))) {
    parciais[nome.replace(/\.mustache$/, '')] = fs.readFileSync(path.join(dirParciais, nome), 'utf8');
  }
  const modelos = {};
  const dirModelos = path.join(dir, 'modelos');
  for (const nome of entradas(dirModelos, (n) => n.endsWith('.json'))) {
    const json = lerJson(path.join(dirModelos, nome));
    modelos[idDoJson(json, nome)] = json;
  }
  const nichos = {};
  const dirNichos = path.join(dir, 'nichos');
  for (const nome of entradas(dirNichos, (n) => n.endsWith('.json') && n !== 'comum.json')) {
    const json = lerJson(path.join(dirNichos, nome));
    nichos[idDoJson(json, nome)] = json;
  }
  return {
    versao: versaoBiblioteca(dir),
    secoes,
    parciais,
    baseCss: lerTexto(path.join(dir, 'base.css')),
    modelos,
    nichos,
    comum: lerJsonOpcional(path.join(dirNichos, 'comum.json')),
    icones: lerJsonOpcional(path.join(dir, 'icones', 'icones.json')),
    fontes: lerJsonOpcional(path.join(dir, 'fontes', 'fontes.json')),
  };
}
