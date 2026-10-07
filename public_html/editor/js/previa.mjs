// Prévia do site dentro do editor — contrato §9.
//
//   renderizarPrevia(alvo, doc, lib, { midia, modo: "editor" })
//       escreve o HTML do prepararSite dentro de `alvo`, que vira o elemento raiz do site
//       (.rk + classes do acabamento e da fonte) com um <style> da paleta só dele (várias
//       prévias com cores diferentes convivem na mesma página). Devolve o resultado do
//       prepararSite ({ html, secoes, avisos, … }). Classes que o editor puser no alvo ficam.
//   escalar(moldura, alvo, larguraSite)
//       mostra o site em largura real (1280 computador / 390 celular / 1200 miniatura)
//       reduzido com `zoom` para caber na largura da moldura; recalcula quando a moldura muda
//       de tamanho (ResizeObserver). Devolve { fator, definirLargura(l), atualizar(), desligar() }.
//   miniatura(doc, lib, { midia, largura, classe, rotulo })
//       moldura + site inerte (sem foco, sem clique) já renderizado e escalado — para cards e
//       galerias. Devolve { elemento, alvo, atualizar(doc, midia), desligar() }.

import { prepararSite } from './compartilhado/preparo.mjs';
import { injetarCssSite } from './biblioteca.mjs';

export const URL_MIDIA_EDITOR = '/api/media/{id}/{w}';
export const LARGURA_COMPUTADOR = 1280;
export const LARGURA_CELULAR = 390;
export const LARGURA_MINIATURA = 1200;

let contador = 0;

/** Opções do preparo no editor (URL das imagens da API, mídia do estado, ano corrente). */
export function opcoesPreparo({ midia = {}, modo = 'editor', ano } = {}) {
  return {
    modo: modo === 'publicar' ? 'publicar' : 'editor',
    urlMidia: URL_MIDIA_EDITOR,
    midia: midia && typeof midia === 'object' ? midia : {},
    ano: Number.isInteger(ano) ? ano : new Date().getFullYear(),
  };
}

/** Restringe o CSS da paleta (".rk{…}") a esta prévia. */
export function paletaEscopada(cssPaleta, idPrevia) {
  return String(cssPaleta).replace(/^\.rk\{/, `.rk[data-previa="${idPrevia}"]{`);
}

/** Renderiza o site em `alvo`. Ver o cabeçalho do módulo. */
export function renderizarPrevia(alvo, doc, lib, opcoes = {}) {
  injetarCssSite(lib);
  const r = prepararSite(doc, lib, opcoesPreparo(opcoes));
  if (!alvo.dataset.previa) alvo.dataset.previa = `p${++contador}`;
  for (const c of [...alvo.classList]) {
    if (c === 'rk' || /^k-[a-z]+$/.test(c) || /^f-[a-z]+$/.test(c)) alvo.classList.remove(c);
  }
  alvo.classList.add(...r.classesRaiz.split(/\s+/).filter(Boolean));
  alvo.innerHTML = `<style data-rk-paleta>${paletaEscopada(r.cssPaleta, alvo.dataset.previa)}</style>${r.html}`;
  return r;
}

function larguraUtil(moldura) {
  const estilo = getComputedStyle(moldura);
  const padding = (parseFloat(estilo.paddingLeft) || 0) + (parseFloat(estilo.paddingRight) || 0);
  return Math.max(0, moldura.clientWidth - padding);
}

/**
 * Ajusta o `zoom` do alvo para o site de `larguraSite` px caber na moldura.
 * opcoes.ampliar = true permite fator > 1 (padrão: nunca aumenta além do tamanho real).
 */
export function escalar(moldura, alvo, larguraSite = LARGURA_COMPUTADOR, { ampliar = false } = {}) {
  let largura = larguraSite;
  let fator = 1;
  let ultimaLargura = -1;
  const aplicar = (forcar = false) => {
    const disponivel = larguraUtil(moldura);
    if (disponivel <= 0) return;
    if (!forcar && disponivel === ultimaLargura) return;
    ultimaLargura = disponivel;
    fator = disponivel / largura;
    if (!ampliar) fator = Math.min(1, fator);
    fator = Math.max(0.05, Math.round(fator * 10000) / 10000);
    alvo.style.width = `${largura}px`;
    alvo.style.zoom = String(fator);
    moldura.style.setProperty('--zoom-previa', String(fator));
  };
  let observador = null;
  if (typeof ResizeObserver === 'function') {
    observador = new ResizeObserver(() => aplicar());
    observador.observe(moldura);
  } else {
    const aoRedimensionar = () => aplicar();
    window.addEventListener('resize', aoRedimensionar);
    observador = { disconnect: () => window.removeEventListener('resize', aoRedimensionar) };
  }
  aplicar(true);
  return {
    get fator() {
      return fator;
    },
    get largura() {
      return largura;
    },
    definirLargura(l) {
      largura = l;
      aplicar(true);
    },
    atualizar: () => aplicar(true),
    desligar() {
      observador?.disconnect();
      observador = null;
    },
  };
}

/**
 * Miniatura de um site (para cards e galerias): o HTML real reduzido, inerte e escondido
 * dos leitores de tela (o card que a contém leva o nome acessível).
 */
export function miniatura(doc, lib, { midia = {}, largura = LARGURA_MINIATURA, classe = '' } = {}) {
  const elemento = document.createElement('div');
  elemento.className = ['previa-mini', classe].filter(Boolean).join(' ');
  elemento.setAttribute('aria-hidden', 'true');
  const alvo = document.createElement('div');
  alvo.inert = true;
  alvo.className = 'previa-mini__site';
  elemento.append(alvo);
  renderizarPrevia(alvo, doc, lib, { midia });
  const escala = escalar(elemento, alvo, largura);
  return {
    elemento,
    alvo,
    escala,
    atualizar(novoDoc, novaMidia = midia) {
      renderizarPrevia(alvo, novoDoc, lib, { midia: novaMidia });
    },
    desligar() {
      escala.desligar();
    },
  };
}
