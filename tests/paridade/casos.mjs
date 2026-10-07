// Gera automaticamente, a partir da biblioteca, os documentos renderizados nos dois lados.

import { criarDocumento, registroCampos, registroListas } from '../../public_html/editor/js/compartilhado/documento.mjs';
import { itensLista } from '../../public_html/editor/js/compartilhado/textos.mjs';
import { documentoV1, midiaDeTeste } from './funcoes.mjs';

export const ACABAMENTOS = ['classico', 'moderno', 'direto'];
export const CORES = ['#c23b6e', '#f5d90a', '#1b2a4a', '#777777'];

const MIDIA = midiaDeTeste();
const FOTOS = ['m_00000001', 'm_00000002', 'm_00000003', 'm_00000004'];
export const OPCOES_PUBLICAR = { modo: 'publicar', urlMidia: 'img/{id}-{w}.{ext}', ano: 2026, midia: MIDIA };
export const OPCOES_EDITOR = { modo: 'editor', urlMidia: '/api/media/{id}/{w}', ano: 2026, midia: MIDIA };

const DADOS_BASICOS = { nome: 'Clínica Sorriso Vivo', cidade: 'Jundiaí', uf: 'SP', whatsapp: '(11) 98765-4321' };

export const DADOS_COMPLETOS = {
  nome: 'Clínica & Cia <Teste> "Aspas" \'Simples\'',
  cidade: 'São José dos Campos',
  uf: 'sp',
  whatsapp: '+55 (12) 99876-5432',
  telefone: '(12) 3456-7890',
  email: 'contato@clinica.com.br',
  logo: 'm_0000000a',
  endereco: { cep: '12245000', logradouro: 'Av. São João', numero: '1.234', complemento: 'Sala 5 & 6', bairro: 'Jardim Esplanada' },
  horarios: {
    seg: ['08:00', '18:00'], ter: ['08:00', '18:00'], qua: ['08:00', '18:00'], qui: ['08:00', '18:00'], sex: ['08:00', '17:30'],
    sab: ['08:30', '12:00'], dom: null,
  },
  registro: { numero: '12345', uf: 'SP', responsavel: 'Dra. Ana Lima' },
  redes: { instagram: '@clinica.teste', facebook: 'facebook.com/clinica', linkedin: '', youtube: 'https://youtube.com/@x', google: 'https://g.page/clinica?a=1&b=2' },
};

const copia = (x) => JSON.parse(JSON.stringify(x));

/** Textos com & < > " ' / ` = e acentos em TODAS as chaves de texto (escalares e itens). */
function textosEspeciais(doc, lib) {
  const textos = {};
  const registro = registroCampos(lib);
  for (const [chave, def] of Object.entries(registro)) {
    if (chave.includes('*') || def.tipo === 'imagem' || def.tipo === 'icone') continue;
    textos[chave] = `${chave}: & < > " ' / \` = ção {nome} em {cidade} · {segmento}`;
  }
  for (const [lista, ldef] of Object.entries(registroListas(lib))) {
    for (const id of itensLista(doc, lib, lista)) {
      for (const [campo, def] of Object.entries(ldef.campos ?? {})) {
        if (def?.tipo === 'imagem' || def?.tipo === 'icone') continue;
        textos[`${lista}.${id}.${campo}`] = `<${lista}.${id}.${campo}> "item" & 'aspas' =\`/`;
      }
    }
  }
  return textos;
}

/** Atribui fotos fictícias a todas as chaves de imagem (escalares e itens), pulando algumas. */
function imagensParaTudo(doc, lib, pular = 4) {
  const imagens = {};
  let n = 0;
  const registro = registroCampos(lib);
  for (const [chave, def] of Object.entries(registro)) {
    if (chave.includes('*') || def.tipo !== 'imagem') continue;
    n += 1;
    if (n % pular !== 0) imagens[chave] = FOTOS[n % FOTOS.length];
  }
  for (const [lista, ldef] of Object.entries(registroListas(lib))) {
    for (const id of itensLista(doc, lib, lista)) {
      for (const [campo, def] of Object.entries(ldef.campos ?? {})) {
        if (def?.tipo !== 'imagem') continue;
        n += 1;
        if (n % pular !== 0) imagens[`${lista}.${id}.${campo}`] = FOTOS[n % FOTOS.length];
      }
    }
  }
  return imagens;
}

function primeiraOpcao(lib, tipo) {
  const opcoes = lib.secoes?.[tipo]?.manifest?.opcoes;
  return Array.isArray(opcoes) && opcoes.length > 0 ? opcoes[0].id : null;
}

/** Casos: [{ nome, doc, opcoes }]. */
export function gerarCasos(lib) {
  const casos = [];
  const add = (nome, doc, opcoes = OPCOES_PUBLICAR) => casos.push({ nome, doc, opcoes });
  const nichos = Object.keys(lib.nichos ?? {});
  const modelos = Object.keys(lib.modelos ?? {});
  if (nichos.length === 0 || modelos.length === 0) return casos;

  // 1. todo nicho × todo modelo × toda especialidade
  for (const nicho of nichos) {
    const especialidades = (lib.nichos[nicho].especialidades ?? []).map((e) => e.id);
    for (const modelo of modelos) {
      for (const especialidade of especialidades.length > 0 ? especialidades : [undefined]) {
        add(`nicho/${nicho}/${modelo}/${especialidade ?? '-'}`, criarDocumento({ nicho, modelo, especialidade, dados: DADOS_BASICOS }, lib));
      }
    }
  }

  const nichoBase = nichos.includes('clinicas') ? 'clinicas' : nichos[0];
  const modeloBase = modelos.includes('moderno') ? 'moderno' : modelos[0];
  const base = criarDocumento({ nicho: nichoBase, modelo: modeloBase, dados: DADOS_BASICOS }, lib);
  const fontes = Object.keys(lib.fontes?.pares ?? {});

  // 2. acabamentos × fontes × cores
  for (const acabamento of ACABAMENTOS) {
    for (const fonte of fontes.length > 0 ? fontes : ['moderna']) {
      for (const cor of CORES) {
        const doc = copia(base);
        doc.estilo = { ...doc.estilo, acabamento, fonte, cor };
        add(`estilo/${acabamento}/${fonte}/${cor}`, doc);
      }
    }
  }

  // 3. listas editadas
  for (const lista of Object.keys(registroListas(lib))) {
    const ids = itensLista(base, lib, lista);
    if (ids.length === 0) continue;
    const novo = copia(base);
    novo.listas = { [lista]: [...ids, 'nk3f'] };
    novo.textos = { [`${lista}.nk3f.t`]: 'Item novo editado' };
    add(`lista/${lista}/item-novo`, novo);
    const removido = copia(base);
    removido.listas = { [lista]: ids.filter((_, i) => i !== 1) };
    add(`lista/${lista}/item-removido`, removido);
    const reordenado = copia(base);
    reordenado.listas = { [lista]: [...ids].reverse() };
    add(`lista/${lista}/reordenado`, reordenado);
    const demais = copia(base);
    demais.listas = { [lista]: Array.from({ length: 12 }, (_, i) => `n${i}`) };
    add(`lista/${lista}/acima-do-maximo`, demais);
    const vazia = copia(base);
    vazia.listas = { [lista]: [] };
    add(`lista/${lista}/vazia`, vazia);
  }
  const icones = copia(base);
  icones.icones = { 'serv.1': 'dente', 'serv.2': 'nao-existe', 'dif.1': 'relogio', 'hero.ic': 'x' };
  add('icones/manuais', icones);

  // 4. textos com caracteres especiais
  const especiais = copia(base);
  especiais.textos = textosEspeciais(especiais, lib);
  add('textos/especiais', especiais);
  for (const acabamento of ACABAMENTOS) {
    const doc = copia(especiais);
    doc.estilo.acabamento = acabamento;
    add(`textos/especiais/${acabamento}`, doc);
  }

  // 5. dados completos e 6. mídia
  const completos = criarDocumento({ nicho: nichoBase, modelo: modeloBase, dados: DADOS_COMPLETOS }, lib);
  completos.imagens = imagensParaTudo(completos, lib);
  add('dados/completos', completos);
  const semDados = criarDocumento({ nicho: nichoBase, modelo: modeloBase, dados: {} }, lib);
  add('dados/vazios', semDados);
  for (const logo of ['m_0000000b', 'm_0000000c', 'm_ffffffff']) {
    const doc = copia(completos);
    doc.dados.logo = logo;
    add(`midia/logo/${logo}`, doc);
  }
  for (const modelo of modelos) {
    const doc = criarDocumento({ nicho: nichoBase, modelo, dados: DADOS_COMPLETOS }, lib);
    doc.imagens = imagensParaTudo(doc, lib, 7);
    doc.textos = textosEspeciais(doc, lib);
    add(`completo/${modelo}`, doc);
    add(`completo/${modelo}/editor`, doc, OPCOES_EDITOR);
  }

  // 7. modo editor (com prévia local de upload)
  const editor = copia(completos);
  editor.imagens['hero.img'] = 'm_0000000d';
  add('modo/editor', editor, OPCOES_EDITOR);
  add('modo/sem-opcoes', base, {});

  // 8. cobertura total: cada seção × opção, cheia e vazia
  const header = lib.secoes?.header ? primeiraOpcao(lib, 'header') : null;
  const rodape = lib.secoes?.rodape ? primeiraOpcao(lib, 'rodape') : null;
  for (const [tipo, sec] of Object.entries(lib.secoes ?? {})) {
    for (const opcao of sec?.manifest?.opcoes ?? []) {
      const secoes = [];
      if (header && tipo !== 'header') secoes.push({ tipo: 'header', opcao: header });
      secoes.push({ tipo, opcao: opcao.id });
      if (rodape && tipo !== 'rodape') secoes.push({ tipo: 'rodape', opcao: rodape });
      const cheio = criarDocumento({ nicho: nichoBase, modelo: modeloBase, dados: DADOS_COMPLETOS }, lib);
      cheio.secoes = secoes;
      cheio.imagens = imagensParaTudo(cheio, lib, 1000);
      add(`secao/${tipo}/${opcao.id}/cheia`, cheio);
      const vazio = criarDocumento({ nicho: nichoBase, modelo: modeloBase, dados: {} }, lib);
      vazio.secoes = secoes;
      vazio.estilo.acabamento = 'classico';
      add(`secao/${tipo}/${opcao.id}/vazia`, vazio, OPCOES_EDITOR);
    }
  }

  // 9. robustez: nada disso pode lançar
  const robusto = copia(base);
  robusto.secoes = [{ tipo: 'nao-existe', opcao: 'x' }, ...base.secoes, { tipo: base.secoes[0]?.tipo ?? 'x', opcao: 'nao-existe' }, base.secoes[1] ?? { tipo: 'y', opcao: 'y' }];
  robusto.estilo.cor = '#zzz';
  robusto.especialidade = 'nao-existe';
  add('robustez/secoes-invalidas', robusto);
  add('robustez/v1', documentoV1());
  add('robustez/nicho-desconhecido', { ...copia(base), nicho: 'nao-existe' });
  add('robustez/doc-vazio', {});
  add('robustez/tipos-errados', { versaoEsquema: 2, nicho: nichoBase, modelo: modeloBase, secoes: base.secoes, textos: ['x'], listas: 'x', imagens: 5, icones: null, dados: { horarios: 'x', redes: [] }, estilo: { whatsappFlutuante: 'sim' } });
  return casos;
}
