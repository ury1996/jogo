// Fotos (PDF §7.3 + [M15]): escolher → reduzir no navegador → prévia local → enviar com
// progresso → ligar ao documento. Também: remover foto e texto alternativo (PATCH).
//
// Caminho de uma foto:
//   arquivo → createImageBitmap(imageOrientation "from-image") → canvas (máx. 2400 px no lado
//   maior) → JPEG 85% → prévia local (midia[temporário].local = blob:) → POST /api/media
//   (multipart: arquivo, site_id, tipo) com barra de progresso → doc.imagens[chave] = id.

import { api } from '../api.mjs';
import { el, aviso, modal, campo } from '../ui.mjs';
import * as op from './operacoes.mjs';

const LADO_MAXIMO = 2400;
const QUALIDADE_JPEG = 0.85;
let contadorTemporario = 0;

/** Lê e reduz a foto. → { blob, largura, altura } (lança Error com mensagem pronta). */
export async function prepararFoto(arquivo) {
  let bitmap;
  try {
    bitmap = await createImageBitmap(arquivo, { imageOrientation: 'from-image' });
  } catch {
    const nome = String(arquivo?.name ?? '').toLowerCase();
    if (/\.(heic|heif)$/.test(nome)) {
      throw new Error(op.problemaArquivoFoto({ name: nome, type: 'image/heic', size: 1 }));
    }
    throw new Error('Não foi possível abrir esta imagem. Tente outra foto em JPG ou PNG.');
  }
  try {
    const { largura, altura } = op.dimensoesReduzidas(bitmap.width, bitmap.height, LADO_MAXIMO);
    let canvas;
    if (typeof OffscreenCanvas === 'function') {
      canvas = new OffscreenCanvas(largura, altura);
    } else {
      canvas = document.createElement('canvas');
      canvas.width = largura;
      canvas.height = altura;
    }
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    // JPEG não tem transparência: fundo branco para PNGs com áreas vazias.
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, largura, altura);
    ctx.drawImage(bitmap, 0, 0, largura, altura);
    let blob;
    if (typeof canvas.convertToBlob === 'function') {
      blob = await canvas.convertToBlob({ type: 'image/jpeg', quality: QUALIDADE_JPEG });
    } else {
      blob = await new Promise((resolver, rejeitar) => canvas.toBlob(
        (b) => (b ? resolver(b) : rejeitar(new Error('Não foi possível preparar a foto.'))), 'image/jpeg', QUALIDADE_JPEG));
    }
    return { blob, largura, altura };
  } finally {
    bitmap.close?.();
  }
}

/** Abre o seletor de arquivos do sistema. → Promise<File|null> */
export function escolherArquivo(aceita = 'image/*') {
  return new Promise((resolver) => {
    const entrada = el('input', { type: 'file', accept: aceita, class: 'sr-only', tabindex: '-1', 'aria-hidden': 'true' });
    let resolvido = false;
    const terminar = (arquivo) => {
      if (resolvido) return;
      resolvido = true;
      entrada.remove();
      resolver(arquivo);
    };
    entrada.addEventListener('change', () => terminar(entrada.files?.[0] ?? null));
    entrada.addEventListener('cancel', () => terminar(null));
    document.body.append(entrada);
    entrada.click();
  });
}

export function ligarFotos(ed) {
  const { lib } = ed;

  function rotuloDe(chave) {
    return op.definicaoDaChave(lib, chave)?.rotulo ?? 'Foto';
  }

  /** Envia uma foto para a chave (com prévia local e progresso). */
  async function enviarFoto(chave, arquivo) {
    const problema = op.problemaArquivoFoto(arquivo, Number(ed.limiteUploadMb) || 15);
    if (problema) {
      aviso(problema, { tipo: 'erro', duracao: 9000 });
      return false;
    }
    if (ed.envios.has(chave)) {
      aviso('Espere a foto anterior terminar de enviar.');
      return false;
    }
    ed.envios.set(chave, { progresso: 0, etapa: 'preparando' });
    ed.tela.posicionar();
    let preparada;
    try {
      preparada = await prepararFoto(arquivo);
    } catch (e) {
      ed.envios.delete(chave);
      ed.tela.posicionar();
      aviso(e.message, { tipo: 'erro', duracao: 9000 });
      return false;
    }
    const local = URL.createObjectURL(preparada.blob);
    const temporario = `m_local${(++contadorTemporario).toString(16)}`;
    ed.estado.definirMidia(temporario, {
      largura: preparada.largura, altura: preparada.altura, variantes: [], alt: '', tipo: 'foto', formato: 'webp', local,
    });
    ed.imagensTemporarias[chave] = temporario;
    ed.envios.set(chave, { progresso: 0, etapa: 'enviando' });
    ed.renderizar();

    const form = new FormData();
    const nome = String(arquivo.name ?? 'foto').replace(/\.[^.]+$/, '') || 'foto';
    form.append('arquivo', preparada.blob, `${nome}.jpg`);
    form.append('site_id', String(ed.siteId));
    form.append('tipo', 'foto');
    try {
      const r = await api.enviarArquivo('/media', form, (fracao) => {
        const envio = ed.envios.get(chave);
        if (envio) {
          envio.progresso = fracao;
          ed.tela.posicionar();
        }
      });
      const midia = r?.midia;
      if (!midia?.id) throw new Error('Resposta inesperada do servidor.');
      const { id, ...dados } = midia;
      ed.estado.definirMidia(id, { ...dados, local });
      delete ed.imagensTemporarias[chave];
      ed.estado.definirMidia(temporario, null);
      ed.envios.delete(chave);
      ed.aplicar((d) => op.definirImagem(d, chave, id), { rotulo: 'Trocar foto' });
      ed.renderizar();
      ed.anunciar('Foto enviada.');
      return true;
    } catch (e) {
      delete ed.imagensTemporarias[chave];
      ed.estado.definirMidia(temporario, null);
      ed.envios.delete(chave);
      URL.revokeObjectURL(local);
      ed.renderizar();
      aviso(e?.mensagem ?? e?.message ?? 'Não foi possível enviar a foto.', { tipo: 'erro', duracao: 9000 });
      return false;
    }
  }

  async function escolherEEnviar(chave) {
    const arquivo = await escolherArquivo('image/*,.heic,.heif');
    if (arquivo) await enviarFoto(chave, arquivo);
  }

  /** Clique numa foto: sem foto → escolhe o arquivo; com foto → janela (trocar, remover, texto alternativo). */
  function abrirFoto(chave) {
    if (ed.envios.has(chave)) {
      aviso('A foto ainda está sendo enviada.');
      return;
    }
    const midiaId = ed.estado.doc.imagens?.[chave];
    if (!midiaId) {
      escolherEEnviar(chave);
      return;
    }
    const midia = ed.estado.midia[midiaId] ?? {};
    const src = midia.local ?? api.url(`/media/${midiaId}/${(midia.variantes ?? []).includes(960) ? 960 : (midia.variantes?.[0] ?? 'orig')}`);
    const entradaAlt = el('input', { type: 'text', value: midia.alt ?? '', maxlength: '160', autocomplete: 'off' });
    const campoAlt = campo({
      rotulo: 'Texto alternativo',
      entrada: entradaAlt,
      opcional: true,
      max: 160,
      ajuda: 'Descreva a foto para quem usa leitor de tela e para o Google (ex.: "Recepção da clínica com sofá azul"). Vazio usa o nome do campo.',
    });
    const corpo = el('div', { class: 'ed-foto' },
      el('div', { class: 'ed-foto__previa' }, el('img', { src, alt: '', class: 'ed-foto__img' })),
      el('p', { class: 'ed-foto__meta' }, midia.largura ? `${midia.largura} × ${midia.altura} px` : ''),
      campoAlt.elemento);
    let altInicial = midia.alt ?? '';
    const salvarAlt = async () => {
      const alt = entradaAlt.value.trim();
      if (alt === altInicial) return true;
      const r = await api.patch(`/media/${encodeURIComponent(midiaId)}`, { alt });
      const dados = r?.midia ?? { alt };
      ed.estado.definirMidia(midiaId, { alt: dados.alt ?? alt });
      altInicial = alt;
      ed.renderizar();
      return true;
    };
    modal({
      titulo: rotuloDe(chave),
      descricao: 'Troque a foto, remova-a ou descreva o que ela mostra.',
      corpo,
      acoes: [
        {
          rotulo: 'Remover foto',
          tipo: 'fantasma',
          icone: 'lixeira',
          fn: () => {
            ed.aplicar((d) => op.definirImagem(d, chave, null), { rotulo: 'Remover foto' });
            aviso('Foto removida.', { acao: { rotulo: 'Desfazer', fn: () => ed.desfazer() } });
          },
        },
        {
          rotulo: 'Trocar foto',
          icone: 'enviar',
          fn: async () => {
            await salvarAlt().catch(() => {});
            setTimeout(() => escolherEEnviar(chave), 0);
          },
        },
        { rotulo: 'Salvar', tipo: 'primario', foco: true, fn: salvarAlt },
      ],
    });
  }

  // Soltar um arquivo de imagem sobre uma foto da tela também envia.
  const alvo = ed.tela.alvo;
  function aoArrastarSobre(ev) {
    if (ed.visualizando || !ev.dataTransfer?.types?.includes('Files')) return;
    const img = ev.target instanceof Element ? ev.target.closest('[data-img]') : null;
    ev.preventDefault();
    ev.dataTransfer.dropEffect = img ? 'copy' : 'none';
  }
  function aoSoltar(ev) {
    if (ed.visualizando || !ev.dataTransfer?.types?.includes('Files')) return;
    ev.preventDefault();
    const img = ev.target instanceof Element ? ev.target.closest('[data-img]') : null;
    const arquivo = ev.dataTransfer.files?.[0];
    if (img && arquivo) enviarFoto(img.dataset.img, arquivo);
  }
  alvo.addEventListener('dragover', aoArrastarSobre);
  alvo.addEventListener('drop', aoSoltar);

  return {
    abrirFoto,
    enviarFoto,
    desligar() {
      alvo.removeEventListener('dragover', aoArrastarSobre);
      alvo.removeEventListener('drop', aoSoltar);
    },
  };
}

/** Envio do logo (aba Dados): sem redução (SVG fica vetorial). → midia ou null. */
export async function enviarLogo(ed, arquivo, aoProgresso) {
  const form = new FormData();
  form.append('arquivo', arquivo, arquivo.name || 'logo');
  form.append('site_id', String(ed.siteId));
  form.append('tipo', 'logo');
  const r = await api.enviarArquivo('/media', form, aoProgresso);
  const midia = r?.midia;
  if (!midia?.id) throw new Error('Resposta inesperada do servidor.');
  const { id, ...dados } = midia;
  ed.estado.definirMidia(id, dados);
  return midia;
}

