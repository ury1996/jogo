// Renderiza casos em JS (lado do editor). Também roda como CLI, simétrico a renderizar.php:
//   node tests/paridade/renderizar.mjs entrada.json saida.json
// entrada = { biblioteca: dir, casos: [{nome, doc, opcoes}], funcoes: [{nome, fn, args}] }

import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { prepararSite } from '../../public_html/editor/js/compartilhado/preparo.mjs';
import { carregarBiblioteca } from './carregar-biblioteca.mjs';
import { executarFuncoes } from './funcoes.mjs';

/** Resultado comparável de cada caso (ou { nome, erro }). */
export function renderizarCasos(casos, lib) {
  return casos.map(({ nome, doc, opcoes }) => {
    try {
      const r = prepararSite(doc, lib, opcoes);
      return JSON.parse(JSON.stringify({ nome, ...r }));
    } catch (erro) {
      return { nome, erro: String(erro?.stack ?? erro) };
    }
  });
}

if (process.argv[1] && fileURLToPath(import.meta.url) === fs.realpathSync(process.argv[1])) {
  const [entrada, saida] = process.argv.slice(2);
  const pedido = JSON.parse(fs.readFileSync(entrada, 'utf8'));
  const lib = carregarBiblioteca(pedido.biblioteca);
  const resposta = {
    versao: lib.versao,
    resultados: renderizarCasos(pedido.casos ?? [], lib),
    funcoes: executarFuncoes(pedido.funcoes ?? [], lib),
  };
  const json = JSON.stringify(resposta);
  if (saida) fs.writeFileSync(saida, json);
  else process.stdout.write(json);
}
