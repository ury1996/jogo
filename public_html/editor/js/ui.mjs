// Utilitários de interface do editor — contrato §9.
//
//   el(tag, attrs, ...filhos)                         cria elementos (texto sempre como texto, nunca HTML)
//   aviso(msg, { acao: { rotulo, fn }, duracao, tipo }) toast com role="status" (tipo "erro" → role="alert")
//   modal({ titulo, descricao, corpo, acoes, aoFechar, tamanho, classe })  prende o foco; Esc fecha e devolve o foco
//   confirmar(msg, { titulo, confirmar, cancelar, perigo }) → Promise<boolean>
//
// Auxiliares compartilhados com as telas do editor (EDITOR-TELA usa os mesmos):
//   icone(nome), anexar(pai, ...filhos), navegar(hash), campo({...}), carregando(), estadoVazio({...})
//   tempoRelativo(data), formatarDataHora(data), formatarTamanho(bytes)
//   mascaraTelefone(valor), problemaWhatsapp(valor), UFS, CORES_SUGERIDAS
//   corDominante(pixels), corDominanteDaImagem(arquivo), problemaArquivoLogo(arquivo)
//
// As funções puras (sem DOM) podem ser importadas no Node para teste: nada aqui toca o
// `document` no carregamento do módulo.

import { soDigitos, validarWhatsapp } from './compartilhado/dados.mjs';
import { gerarPaleta, normalizarCor, rgbParaHex } from './compartilhado/paleta.mjs';

// Ícones da interface (Phosphor Icons "regular", licença MIT; viewBox 0 0 256 256).
const ICONES = {
  voltar: '<path d="M224,128a8,8,0,0,1-8,8H59.31l58.35,58.34a8,8,0,0,1-11.32,11.32l-72-72a8,8,0,0,1,0-11.32l72-72a8,8,0,0,1,11.32,11.32L59.31,120H216A8,8,0,0,1,224,128Z"/>',
  avancar: '<path d="M221.66,133.66l-72,72a8,8,0,0,1-11.32-11.32L196.69,136H40a8,8,0,0,1,0-16H196.69L138.34,61.66a8,8,0,0,1,11.32-11.32l72,72A8,8,0,0,1,221.66,133.66Z"/>',
  mais: '<path d="M224,128a8,8,0,0,1-8,8H136v80a8,8,0,0,1-16,0V136H40a8,8,0,0,1,0-16h80V40a8,8,0,0,1,16,0v80h80A8,8,0,0,1,224,128Z"/>',
  editar: '<path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"/>',
  lixeira: '<path d="M216,48H176V40a24,24,0,0,0-24-24H104A24,24,0,0,0,80,40v8H40a8,8,0,0,0,0,16h8V208a16,16,0,0,0,16,16H192a16,16,0,0,0,16-16V64h8a8,8,0,0,0,0-16ZM96,40a8,8,0,0,1,8-8h48a8,8,0,0,1,8,8v8H96Zm96,168H64V64H192ZM112,104v64a8,8,0,0,1-16,0V104a8,8,0,0,1,16,0Zm48,0v64a8,8,0,0,1-16,0V104a8,8,0,0,1,16,0Z"/>',
  duplicar: '<path d="M216,32H88a8,8,0,0,0-8,8V80H40a8,8,0,0,0-8,8V216a8,8,0,0,0,8,8H168a8,8,0,0,0,8-8V176h40a8,8,0,0,0,8-8V40A8,8,0,0,0,216,32ZM160,208H48V96H160Zm48-48H176V88a8,8,0,0,0-8-8H96V48H208Z"/>',
  arquivar: '<path d="M224,48H32A16,16,0,0,0,16,64V88a16,16,0,0,0,16,16v88a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V104a16,16,0,0,0,16-16V64A16,16,0,0,0,224,48ZM208,192H48V104H208ZM224,88H32V64H224V88ZM96,136a8,8,0,0,1,8-8h48a8,8,0,0,1,0,16H104A8,8,0,0,1,96,136Z"/>',
  olho: '<path d="M247.31,124.76c-.35-.79-8.82-19.58-27.65-38.41C194.57,61.26,162.88,48,128,48S61.43,61.26,36.34,86.35C17.51,105.18,9,124,8.69,124.76a8,8,0,0,0,0,6.5c.35.79,8.82,19.57,27.65,38.4C61.43,194.74,93.12,208,128,208s66.57-13.26,91.66-38.34c18.83-18.83,27.3-37.61,27.65-38.4A8,8,0,0,0,247.31,124.76ZM128,192c-30.78,0-57.67-11.19-79.93-33.25A133.47,133.47,0,0,1,25,128,133.33,133.33,0,0,1,48.07,97.25C70.33,75.19,97.22,64,128,64s57.67,11.19,79.93,33.25A133.46,133.46,0,0,1,231.05,128C223.84,141.46,192.43,192,128,192Zm0-112a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160Z"/>',
  olhoFechado: '<path d="M53.92,34.62A8,8,0,1,0,42.08,45.38L61.32,66.55C25,88.84,9.38,123.2,8.69,124.76a8,8,0,0,0,0,6.5c.35.79,8.82,19.57,27.65,38.4C61.43,194.74,93.12,208,128,208a127.11,127.11,0,0,0,52.07-10.83l22,24.21a8,8,0,1,0,11.84-10.76Zm47.33,75.84,41.67,45.85a32,32,0,0,1-41.67-45.85ZM128,192c-30.78,0-57.67-11.19-79.93-33.25A133.16,133.16,0,0,1,25,128c4.69-8.79,19.66-33.39,47.35-49.38l18,19.75a48,48,0,0,0,63.66,70l14.73,16.2A112,112,0,0,1,128,192Zm6-95.43a8,8,0,0,1,3-15.72,48.16,48.16,0,0,1,38.77,42.64,8,8,0,0,1-7.22,8.71,6.39,6.39,0,0,1-.75,0,8,8,0,0,1-8-7.26A32.09,32.09,0,0,0,134,96.57Zm113.28,34.69c-.42.94-10.55,23.37-33.36,43.8a8,8,0,1,1-10.67-11.92A132.77,132.77,0,0,0,231.05,128a133.15,133.15,0,0,0-23.12-30.77C185.67,75.19,158.78,64,128,64a118.37,118.37,0,0,0-19.36,1.57A8,8,0,1,1,106,49.79,134,134,0,0,1,128,48c34.88,0,66.57,13.26,91.66,38.35,18.83,18.83,27.3,37.62,27.65,38.41A8,8,0,0,1,247.31,131.26Z"/>',
  abrir: '<path d="M224,104a8,8,0,0,1-16,0V59.32l-66.33,66.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z"/>',
  envelope: '<path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48ZM203.43,64,128,133.15,52.57,64ZM216,192H40V74.19l82.59,75.71a8,8,0,0,0,10.82,0L216,74.19V192Z"/>',
  whatsapp: '<path d="M187.58,144.84l-32-16a8,8,0,0,0-8,.5l-14.69,9.8a40.55,40.55,0,0,1-16-16l9.8-14.69a8,8,0,0,0,.5-8l-16-32A8,8,0,0,0,104,64a40,40,0,0,0-40,40,88.1,88.1,0,0,0,88,88,40,40,0,0,0,40-40A8,8,0,0,0,187.58,144.84ZM152,176a72.08,72.08,0,0,1-72-72A24,24,0,0,1,99.29,80.46l11.48,23L101,118a8,8,0,0,0-.73,7.51,56.47,56.47,0,0,0,30.15,30.15A8,8,0,0,0,138,155l14.61-9.74,23,11.48A24,24,0,0,1,152,176ZM128,24A104,104,0,0,0,36.18,176.88L24.83,210.93a16,16,0,0,0,20.24,20.24l34.05-11.35A104,104,0,1,0,128,24Zm0,192a87.87,87.87,0,0,1-44.06-11.81,8,8,0,0,0-6.54-.67L40,216,52.47,178.6a8,8,0,0,0-.66-6.54A88,88,0,1,1,128,216Z"/>',
  check: '<path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/>',
  fechar: '<path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31,61.66,205.66a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/>',
  esquerda: '<path d="M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z"/>',
  direita: '<path d="M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z"/>',
  cima: '<path d="M213.66,165.66a8,8,0,0,1-11.32,0L128,91.31,53.66,165.66a8,8,0,0,1-11.32-11.32l80-80a8,8,0,0,1,11.32,0l80,80A8,8,0,0,1,213.66,165.66Z"/>',
  baixo: '<path d="M213.66,101.66l-80,80a8,8,0,0,1-11.32,0l-80-80A8,8,0,0,1,53.66,90.34L128,164.69l74.34-74.35a8,8,0,0,1,11.32,11.32Z"/>',
  desfazer: '<path d="M224,128a96,96,0,0,1-94.71,96H128A95.38,95.38,0,0,1,62.1,197.8a8,8,0,0,1,11-11.63A80,80,0,1,0,71.43,71.39a3.07,3.07,0,0,1-.26.25L44.59,96H72a8,8,0,0,1,0,16H24a8,8,0,0,1-8-8V56a8,8,0,0,1,16,0V85.8L60.25,60A96,96,0,0,1,224,128Z"/>',
  refazer: '<path d="M240,56v48a8,8,0,0,1-8,8H184a8,8,0,0,1,0-16H211.4L184.81,71.64l-.25-.24a80,80,0,1,0-1.67,114.78,8,8,0,0,1,11,11.63A95.44,95.44,0,0,1,128,224h-1.32A96,96,0,1,1,195.75,60L224,85.8V56a8,8,0,1,1,16,0Z"/>',
  computador: '<path d="M208,40H48A24,24,0,0,0,24,64V176a24,24,0,0,0,24,24h72v16H96a8,8,0,0,0,0,16h64a8,8,0,0,0,0-16H136V200h72a24,24,0,0,0,24-24V64A24,24,0,0,0,208,40ZM48,56H208a8,8,0,0,1,8,8v80H40V64A8,8,0,0,1,48,56ZM208,184H48a8,8,0,0,1-8-8V160H216v16A8,8,0,0,1,208,184Z"/>',
  celular: '<path d="M176,16H80A24,24,0,0,0,56,40V216a24,24,0,0,0,24,24h96a24,24,0,0,0,24-24V40A24,24,0,0,0,176,16ZM72,64H184V192H72Zm8-32h96a8,8,0,0,1,8,8v8H72V40A8,8,0,0,1,80,32Zm96,192H80a8,8,0,0,1-8-8v-8H184v8A8,8,0,0,1,176,224Z"/>',
  sair: '<path d="M120,216a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V40a8,8,0,0,1,8-8h64a8,8,0,0,1,0,16H56V208h56A8,8,0,0,1,120,216Zm109.66-93.66-40-40a8,8,0,0,0-11.32,11.32L204.69,120H112a8,8,0,0,0,0,16h92.69l-26.35,26.34a8,8,0,0,0,11.32,11.32l40-40A8,8,0,0,0,229.66,122.34Z"/>',
  usuario: '<path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24ZM74.08,197.5a64,64,0,0,1,107.84,0,87.83,87.83,0,0,1-107.84,0ZM96,120a32,32,0,1,1,32,32A32,32,0,0,1,96,120Zm97.76,66.41a79.66,79.66,0,0,0-36.06-28.75,48,48,0,1,0-59.4,0,79.66,79.66,0,0,0-36.06,28.75,88,88,0,1,1,131.52,0Z"/>',
  baixar: '<path d="M224,144v64a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V144a8,8,0,0,1,16,0v56H208V144a8,8,0,0,1,16,0Zm-101.66,5.66a8,8,0,0,0,11.32,0l40-40a8,8,0,0,0-11.32-11.32L136,124.69V32a8,8,0,0,0-16,0v92.69L93.66,98.34a8,8,0,0,0-11.32,11.32Z"/>',
  enviar: '<path d="M224,144v64a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V144a8,8,0,0,1,16,0v56H208V144a8,8,0,0,1,16,0ZM93.66,77.66,120,51.31V144a8,8,0,0,0,16,0V51.31l26.34,26.35a8,8,0,0,0,11.32-11.32l-40-40a8,8,0,0,0-11.32,0l-40,40A8,8,0,0,0,93.66,77.66Z"/>',
  imagem: '<path d="M216,40H40A16,16,0,0,0,24,56V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A16,16,0,0,0,216,40Zm0,16V158.75l-26.07-26.06a16,16,0,0,0-22.63,0l-20,20-44-44a16,16,0,0,0-22.62,0L40,149.37V56ZM40,172l52-52,80,80H40Zm176,28H194.63l-36-36,20-20L216,181.38V200ZM144,100a12,12,0,1,1,12,12A12,12,0,0,1,144,100Z"/>',
  paleta: '<path d="M200.77,53.89A103.27,103.27,0,0,0,128,24h-1.07A104,104,0,0,0,24,128c0,43,26.58,79.06,69.36,94.17A32,32,0,0,0,136,192a16,16,0,0,1,16-16h46.21a31.81,31.81,0,0,0,31.2-24.88,104.43,104.43,0,0,0,2.59-24A103.28,103.28,0,0,0,200.77,53.89Zm13,93.71A15.89,15.89,0,0,1,198.21,160H152a32,32,0,0,0-32,32,16,16,0,0,1-21.31,15.07C62.49,194.3,40,164,40,128a88,88,0,0,1,87.09-88h.9a88.35,88.35,0,0,1,88,87.25A88.86,88.86,0,0,1,213.81,147.6ZM140,76a12,12,0,1,1-12-12A12,12,0,0,1,140,76ZM96,100A12,12,0,1,1,84,88,12,12,0,0,1,96,100Zm0,56a12,12,0,1,1-12-12A12,12,0,0,1,96,156Zm88-56a12,12,0,1,1-12-12A12,12,0,0,1,184,100Z"/>',
  alerta: '<path d="M236.8,188.09,149.35,36.22h0a24.76,24.76,0,0,0-42.7,0L19.2,188.09a23.51,23.51,0,0,0,0,23.72A24.35,24.35,0,0,0,40.55,224h174.9a24.35,24.35,0,0,0,21.33-12.19A23.51,23.51,0,0,0,236.8,188.09ZM222.93,203.8a8.5,8.5,0,0,1-7.48,4.2H40.55a8.5,8.5,0,0,1-7.48-4.2,7.59,7.59,0,0,1,0-7.72L120.52,44.21a8.75,8.75,0,0,1,15,0l87.45,151.87A7.59,7.59,0,0,1,222.93,203.8ZM120,144V104a8,8,0,0,1,16,0v40a8,8,0,0,1-16,0Zm20,36a12,12,0,1,1-12-12A12,12,0,0,1,140,180Z"/>',
  info: '<path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm16-40a8,8,0,0,1-8,8,16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40A8,8,0,0,1,144,176ZM112,84a12,12,0,1,1,12,12A12,12,0,0,1,112,84Z"/>',
  ok: '<path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/>',
  alca: '<path d="M104,60A12,12,0,1,1,92,48,12,12,0,0,1,104,60Zm60,12a12,12,0,1,0-12-12A12,12,0,0,0,164,72ZM92,116a12,12,0,1,0,12,12A12,12,0,0,0,92,116Zm72,0a12,12,0,1,0,12,12A12,12,0,0,0,164,116ZM92,184a12,12,0,1,0,12,12A12,12,0,0,0,92,184Zm72,0a12,12,0,1,0,12,12A12,12,0,0,0,164,184Z"/>',
  lupa: '<path d="M229.66,218.34l-50.07-50.06a88.11,88.11,0,1,0-11.31,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Z"/>',
  grade: '<path d="M104,40H56A16,16,0,0,0,40,56v48a16,16,0,0,0,16,16h48a16,16,0,0,0,16-16V56A16,16,0,0,0,104,40Zm0,64H56V56h48v48Zm96-64H152a16,16,0,0,0-16,16v48a16,16,0,0,0,16,16h48a16,16,0,0,0,16-16V56A16,16,0,0,0,200,40Zm0,64H152V56h48v48Zm-96,32H56a16,16,0,0,0-16,16v48a16,16,0,0,0,16,16h48a16,16,0,0,0,16-16V152A16,16,0,0,0,104,136Zm0,64H56V152h48v48Zm96-64H152a16,16,0,0,0-16,16v48a16,16,0,0,0,16,16h48a16,16,0,0,0,16-16V152A16,16,0,0,0,200,136Zm0,64H152V152h48v48Z"/>',
  publicar: '<path d="M227.32,28.68a16,16,0,0,0-15.66-4.08l-.15,0L19.57,82.84a16,16,0,0,0-2.49,29.8L102,154l41.3,84.87A15.86,15.86,0,0,0,157.74,248q.69,0,1.38-.06a15.88,15.88,0,0,0,14-11.51l58.2-191.94c0-.05,0-.1,0-.15A16,16,0,0,0,227.32,28.68ZM157.83,231.85l-.05.14,0-.07-40.06-82.3,48-48a8,8,0,0,0-11.31-11.31l-48,48L24.08,98.25l-.07,0,.14,0L216,40Z"/>',
  config: '<path d="M128,80a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160Zm109.94-52.79a8,8,0,0,0-3.89-5.4l-29.83-17-.12-33.62a8,8,0,0,0-2.83-6.08,111.91,111.91,0,0,0-36.72-20.67,8,8,0,0,0-6.46.59L128,41.85,97.88,25a8,8,0,0,0-6.47-.6A112.1,112.1,0,0,0,54.73,45.15a8,8,0,0,0-2.83,6.07l-.15,33.65-29.83,17a8,8,0,0,0-3.89,5.4,106.47,106.47,0,0,0,0,41.56,8,8,0,0,0,3.89,5.4l29.83,17,.12,33.62a8,8,0,0,0,2.83,6.08,111.91,111.91,0,0,0,36.72,20.67,8,8,0,0,0,6.46-.59L128,214.15,158.12,231a7.91,7.91,0,0,0,3.9,1,8.09,8.09,0,0,0,2.57-.42,112.1,112.1,0,0,0,36.68-20.73,8,8,0,0,0,2.83-6.07l.15-33.65,29.83-17a8,8,0,0,0,3.89-5.4A106.47,106.47,0,0,0,237.94,107.21Zm-15,34.91-28.57,16.25a8,8,0,0,0-3,3c-.58,1-1.19,2.06-1.81,3.06a7.94,7.94,0,0,0-1.22,4.21l-.15,32.25a95.89,95.89,0,0,1-25.37,14.3L134,199.13a8,8,0,0,0-3.91-1h-.19c-1.21,0-2.43,0-3.64,0a8.08,8.08,0,0,0-4.1,1l-28.84,16.1A96,96,0,0,1,67.88,201l-.11-32.2a8,8,0,0,0-1.22-4.22c-.62-1-1.23-2-1.8-3.06a8.09,8.09,0,0,0-3-3.06l-28.6-16.29a90.49,90.49,0,0,1,0-28.26L61.67,97.63a8,8,0,0,0,3-3c.58-1,1.19-2.06,1.81-3.06a7.94,7.94,0,0,0,1.22-4.21l.15-32.25a95.89,95.89,0,0,1,25.37-14.3L122,56.87a8,8,0,0,0,4.1,1c1.21,0,2.43,0,3.64,0a8.08,8.08,0,0,0,4.1-1l28.84-16.1A96,96,0,0,1,188.12,55l.11,32.2a8,8,0,0,0,1.22,4.22c.62,1,1.23,2,1.8,3.06a8.09,8.09,0,0,0,3,3.06l28.6,16.29A90.49,90.49,0,0,1,222.9,142.12Z"/>',
  conversa: '<path d="M140,128a12,12,0,1,1-12-12A12,12,0,0,1,140,128ZM84,116a12,12,0,1,0,12,12A12,12,0,0,0,84,116Zm88,0a12,12,0,1,0,12,12A12,12,0,0,0,172,116Zm60,12A104,104,0,0,1,79.12,219.82L45.07,231.17a16,16,0,0,1-20.24-20.24l11.35-34.05A104,104,0,1,1,232,128Zm-16,0A88,88,0,1,0,51.81,172.06a8,8,0,0,1,.66,6.54L40,216,77.4,203.53a7.85,7.85,0,0,1,2.53-.42,8,8,0,0,1,4,1.08A88,88,0,0,0,216,128Z"/>',
  filtro: '<path d="M230.6,49.53A15.81,15.81,0,0,0,216,40H40A16,16,0,0,0,28.19,66.76l.08.09L96,139.17V216a16,16,0,0,0,24.87,13.32l32-21.34A16,16,0,0,0,160,194.66V139.17l67.74-72.32.08-.09A15.8,15.8,0,0,0,230.6,49.53ZM40,56h0Zm106.18,74.58A8,8,0,0,0,144,136v58.66L112,216V136a8,8,0,0,0-2.16-5.47L40,56H216Z"/>',
  historico: '<path d="M136,80v43.47l36.12,21.67a8,8,0,0,1-8.24,13.72l-40-24A8,8,0,0,1,120,128V80a8,8,0,0,1,16,0Zm-8-48A95.44,95.44,0,0,0,60.08,60.15C52.81,67.51,46.35,74.59,40,82V64a8,8,0,0,0-16,0v40a8,8,0,0,0,8,8H72a8,8,0,0,0,0-16H49c7.15-8.42,14.27-16.35,22.39-24.57a80,80,0,1,1,1.66,114.75,8,8,0,1,0-11,11.64A96,96,0,1,0,128,32Z"/>',
  brilho: '<path d="M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z"/>',
  globo: '<path d="M128,24h0A104,104,0,1,0,232,128,104.12,104.12,0,0,0,128,24Zm87.62,96H175.79C174,83.49,159.94,57.67,148.41,42.4A88.19,88.19,0,0,1,215.63,120ZM96.23,136h63.54c-2.31,41.61-22.23,67.11-31.77,77C118.45,203.1,98.54,177.6,96.23,136Zm0-16C98.54,78.39,118.46,52.89,128,43c9.55,9.93,29.46,35.43,31.77,77Zm11.36-77.6C96.06,57.67,82,83.49,80.21,120H40.37A88.19,88.19,0,0,1,107.59,42.4ZM40.37,136H80.21c1.82,36.51,15.85,62.33,27.38,77.6A88.19,88.19,0,0,1,40.37,136Zm108,77.6c11.53-15.27,25.56-41.09,27.38-77.6h39.84A88.19,88.19,0,0,1,148.41,213.6Z"/>',
  setaCima: '<path d="M205.66,117.66a8,8,0,0,1-11.32,0L136,59.31V216a8,8,0,0,1-16,0V59.31L61.66,117.66a8,8,0,0,1-11.32-11.32l72-72a8,8,0,0,1,11.32,0l72,72A8,8,0,0,1,205.66,117.66Z"/>',
  setaBaixo: '<path d="M205.66,149.66l-72,72a8,8,0,0,1-11.32,0l-72-72a8,8,0,0,1,11.32-11.32L120,196.69V40a8,8,0,0,1,16,0V196.69l58.34-58.35a8,8,0,0,1,11.32,11.32Z"/>',
  cadeado: '<path d="M208,80H176V56a48,48,0,0,0-96,0V80H48A16,16,0,0,0,32,96V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V96A16,16,0,0,0,208,80ZM96,56a32,32,0,0,1,64,0V80H96ZM208,208H48V96H208V208Z"/>',
  telefone: '<path d="M222.37,158.46l-47.11-21.11-.13-.06a16,16,0,0,0-15.17,1.4,8.12,8.12,0,0,0-.75.56L134.87,160c-15.42-7.49-31.34-23.29-38.83-38.51l20.78-24.71c.2-.25.39-.5.57-.77a16,16,0,0,0,1.32-15.06l0-.12L97.54,33.64a16,16,0,0,0-16.62-9.52A56.26,56.26,0,0,0,32,80c0,79.4,64.6,144,144,144a56.26,56.26,0,0,0,55.88-48.92A16,16,0,0,0,222.37,158.46ZM176,208A128.14,128.14,0,0,1,48,80,40.2,40.2,0,0,1,82.87,40a.61.61,0,0,0,0,.12l21,47L83.2,111.86a6.13,6.13,0,0,0-.57.77,16,16,0,0,0-1,15.7c9.06,18.53,27.73,37.06,46.46,46.11a16,16,0,0,0,15.75-1.14,8.44,8.44,0,0,0,.74-.56L168.89,152l47,21.05h0s.08,0,.11,0A40.21,40.21,0,0,1,176,208Z"/>',
  texto: '<path d="M87.24,52.59a8,8,0,0,0-14.48,0l-64,136a8,8,0,1,0,14.48,6.81L39.9,160h80.2l16.66,35.4a8,8,0,1,0,14.48-6.81ZM47.43,144,80,74.79,112.57,144ZM200,96c-12.76,0-22.73,3.47-29.63,10.32a8,8,0,0,0,11.26,11.36c3.8-3.77,10-5.68,18.37-5.68,13.23,0,24,9,24,20v3.22A42.76,42.76,0,0,0,200,128c-22.06,0-40,16.15-40,36s17.94,36,40,36a42.73,42.73,0,0,0,24-7.25,8,8,0,0,0,16-.75V132C240,112.15,222.06,96,200,96Zm0,88c-13.23,0-24-9-24-20s10.77-20,24-20,24,9,24,20S213.23,184,200,184Z"/>',
  secoes: '<path d="M230.91,172A8,8,0,0,1,228,182.91l-96,56a8,8,0,0,1-8.06,0l-96-56A8,8,0,0,1,36,169.09l92,53.65,92-53.65A8,8,0,0,1,230.91,172ZM220,121.09l-92,53.65L36,121.09A8,8,0,0,0,28,134.91l96,56a8,8,0,0,0,8.06,0l96-56A8,8,0,1,0,220,121.09ZM24,80a8,8,0,0,1,4-6.91l96-56a8,8,0,0,1,8.06,0l96,56a8,8,0,0,1,0,13.82l-96,56a8,8,0,0,1-8.06,0l-96-56A8,8,0,0,1,24,80Zm23.88,0L128,126.74,208.12,80,128,33.26Z"/>',
  dados: '<path d="M200,112a8,8,0,0,1-8,8H152a8,8,0,0,1,0-16h40A8,8,0,0,1,200,112Zm-8,24H152a8,8,0,0,0,0,16h40a8,8,0,0,0,0-16Zm40-80V200a16,16,0,0,1-16,16H40a16,16,0,0,1-16-16V56A16,16,0,0,1,40,40H216A16,16,0,0,1,232,56ZM216,200V56H40V200H216Zm-80.26-34a8,8,0,1,1-15.5,4c-2.63-10.26-13.06-18-24.25-18s-21.61,7.74-24.25,18a8,8,0,1,1-15.5-4,39.84,39.84,0,0,1,17.19-23.34,32,32,0,1,1,45.12,0A39.76,39.76,0,0,1,135.75,166ZM96,136a16,16,0,1,0-16-16A16,16,0,0,0,96,136Z"/>',
  pincel: '<path d="M232,32a8,8,0,0,0-8-8c-44.08,0-89.31,49.71-114.43,82.63A60,60,0,0,0,32,164c0,30.88-19.54,44.73-20.47,45.37A8,8,0,0,0,16,224H92a60,60,0,0,0,57.37-77.57C182.3,121.31,232,76.08,232,32ZM92,208H34.63C41.38,198.41,48,183.92,48,164a44,44,0,1,1,44,44Zm32.42-94.45q5.14-6.66,10.09-12.55A76.23,76.23,0,0,1,155,121.49q-5.9,4.94-12.55,10.09A60.54,60.54,0,0,0,124.42,113.55Zm42.7-2.68a92.57,92.57,0,0,0-22-22c31.78-34.53,55.75-45,69.9-47.91C212.17,55.12,201.65,79.09,167.12,110.87Z"/>',
  link: '<path d="M165.66,90.34a8,8,0,0,1,0,11.32l-64,64a8,8,0,0,1-11.32-11.32l64-64A8,8,0,0,1,165.66,90.34ZM215.6,40.4a56,56,0,0,0-79.2,0L106.34,70.45a8,8,0,0,0,11.32,11.32l30.06-30a40,40,0,0,1,56.57,56.56l-30.07,30.06a8,8,0,0,0,11.31,11.32L215.6,119.6a56,56,0,0,0,0-79.2ZM138.34,174.22l-30.06,30.06a40,40,0,1,1-56.56-56.57l30.05-30.05a8,8,0,0,0-11.32-11.32L40.4,136.4a56,56,0,0,0,79.2,79.2l30.06-30.07a8,8,0,0,0-11.32-11.31Z"/>',
  lido: '<path d="M228.44,89.34l-96-64a8,8,0,0,0-8.88,0l-96,64A8,8,0,0,0,24,96V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V96A8,8,0,0,0,228.44,89.34ZM96.72,152,40,192V111.53Zm16.37,8h29.82l56.63,40H56.46Zm46.19-8L216,111.53V192ZM128,41.61l81.91,54.61-67,47.78H113.11l-67-47.78Z"/>',
  naoLido: '<path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48ZM203.43,64,128,133.15,52.57,64ZM216,192H40V74.19l82.59,75.71a8,8,0,0,0,10.82,0L216,74.19V192Z"/>',
  pessoas: '<path d="M117.25,157.92a60,60,0,1,0-66.5,0A95.83,95.83,0,0,0,3.53,195.63a8,8,0,1,0,13.4,8.74,80,80,0,0,1,134.14,0,8,8,0,0,0,13.4-8.74A95.83,95.83,0,0,0,117.25,157.92ZM40,108a44,44,0,1,1,44,44A44.05,44.05,0,0,1,40,108Zm210.14,98.7a8,8,0,0,1-11.07-2.33A79.83,79.83,0,0,0,172,168a8,8,0,0,1,0-16,44,44,0,1,0-16.34-84.87,8,8,0,1,1-5.94-14.85,60,60,0,0,1,55.53,105.64,95.83,95.83,0,0,1,47.22,37.71A8,8,0,0,1,250.14,206.7Z"/>',
};

const NS_SVG = 'http://www.w3.org/2000/svg';
const PROPRIEDADES = new Set(['value', 'checked', 'selected', 'indeterminate', 'muted', 'defaultValue']);

/* ------------------------------------------------------------------ elementos */

/** Anexa filhos: strings/números viram texto; arrays são achatados; null/false/true são ignorados. */
export function anexar(pai, ...filhos) {
  for (const f of filhos) {
    if (f === null || f === undefined || f === false || f === true) continue;
    if (Array.isArray(f)) anexar(pai, ...f);
    else if (typeof f === 'string' || typeof f === 'number') pai.append(document.createTextNode(String(f)));
    else pai.append(f);
  }
  return pai;
}

function definirAtributos(e, attrs) {
  if (!attrs) return;
  for (const [k, v] of Object.entries(attrs)) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class' || k === 'className') {
      const c = Array.isArray(v) ? v.filter(Boolean).join(' ') : String(v);
      if (c !== '') e.setAttribute('class', c);
    } else if (k === 'style') {
      if (typeof v === 'string') {
        e.setAttribute('style', v);
      } else {
        for (const [p, val] of Object.entries(v)) {
          if (val === null || val === undefined || val === false) continue;
          if (p.includes('-')) e.style.setProperty(p, String(val));
          else e.style[p] = val;
        }
      }
    } else if (k === 'dataset') {
      for (const [p, val] of Object.entries(v)) if (val !== null && val !== undefined) e.dataset[p] = String(val);
    } else if (k === 'html') {
      e.innerHTML = String(v); // só para conteúdo confiável (SVG gerado pelo preparo ou constante)
    } else if (k === 'texto') {
      e.textContent = String(v);
    } else if (k.startsWith('on') && typeof v === 'function') {
      e.addEventListener(k.slice(2).toLowerCase(), v);
    } else if (PROPRIEDADES.has(k)) {
      e[k] = v;
    } else {
      e.setAttribute(k, v === true ? '' : String(v));
    }
  }
}

/**
 * Cria um elemento. attrs: class (string ou lista), style (string ou objeto), dataset,
 * on{evento}: função, texto, html (só conteúdo confiável), value/checked (propriedades),
 * demais viram atributos (true → atributo vazio; false/null → ausente).
 */
export function el(tag, attrs = null, ...filhos) {
  const e = document.createElement(tag);
  definirAtributos(e, attrs);
  return anexar(e, ...filhos);
}

/** Ícone SVG da interface. Sem `rotulo`, é decorativo (aria-hidden). */
export function icone(nome, { tamanho = null, classe = '', rotulo = '' } = {}) {
  const s = document.createElementNS(NS_SVG, 'svg');
  s.setAttribute('viewBox', '0 0 256 256');
  s.setAttribute('fill', 'currentColor');
  s.setAttribute('class', ['ico', classe].filter(Boolean).join(' '));
  s.setAttribute('focusable', 'false');
  if (tamanho) {
    s.setAttribute('width', String(tamanho));
    s.setAttribute('height', String(tamanho));
  }
  if (rotulo) {
    s.setAttribute('role', 'img');
    s.setAttribute('aria-label', rotulo);
  } else {
    s.setAttribute('aria-hidden', 'true');
  }
  s.innerHTML = ICONES[nome] ?? '';
  return s;
}

/** Vai para uma rota do editor (hash). `substituir` não cria entrada no histórico do navegador. */
export function navegar(hash, { substituir = false } = {}) {
  const alvo = hash.startsWith('#') ? hash : `#${hash}`;
  if (location.hash === alvo) {
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  } else if (substituir) {
    location.replace(alvo);
  } else {
    location.hash = alvo;
  }
}

/* ------------------------------------------------------------------ avisos (toasts) */

const MAX_AVISOS = 3;

function recipienteAvisos() {
  let c = document.getElementById('avisos');
  if (!c) {
    c = el('div', { id: 'avisos', class: 'avisos', 'aria-live': 'polite', 'aria-relevant': 'additions' });
    document.body.append(c);
  }
  return c;
}

/**
 * Mostra um aviso passageiro. tipo: "info" | "ok" | "erro".
 * duracao em ms (padrão 4,5 s; com ação 6 s; erro 7 s; 0 = fica até fechar).
 * Devolve { elemento, fechar }.
 */
export function aviso(mensagem, opcoes = {}) {
  const { acao = null, tipo = 'info' } = opcoes;
  const duracao = opcoes.duracao ?? (acao ? 6000 : tipo === 'erro' ? 7000 : 4500);
  const caixa = recipienteAvisos();
  let timer = null;
  let restante = duracao;
  let inicio = 0;
  let fechado = false;

  const item = el('div', { class: ['aviso', `aviso--${tipo}`], role: tipo === 'erro' ? 'alert' : 'status' },
    icone(tipo === 'erro' ? 'alerta' : tipo === 'ok' ? 'ok' : 'info', { classe: 'aviso__ico' }),
    el('span', { class: 'aviso__texto' }, mensagem));

  function fechar() {
    if (fechado) return;
    fechado = true;
    clearTimeout(timer);
    item.classList.add('aviso--saindo');
    const remover = () => item.remove();
    if (matchMedia?.('(prefers-reduced-motion: reduce)').matches) remover();
    else setTimeout(remover, 180);
  }
  function contar() {
    if (duracao > 0 && Number.isFinite(duracao)) {
      inicio = Date.now();
      timer = setTimeout(fechar, restante);
    }
  }
  function pausar() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
      restante = Math.max(1500, restante - (Date.now() - inicio));
    }
  }

  if (acao && acao.rotulo) {
    item.append(el('button', {
      type: 'button',
      class: 'aviso__acao',
      onclick: () => {
        fechar();
        acao.fn?.();
      },
    }, acao.rotulo));
  }
  item.append(el('button', { type: 'button', class: 'aviso__fechar', 'aria-label': 'Fechar aviso', onclick: fechar }, icone('fechar')));
  item.addEventListener('mouseenter', pausar);
  item.addEventListener('mouseleave', () => !fechado && !timer && contar());
  item.addEventListener('focusin', pausar);
  item.addEventListener('focusout', () => !fechado && !timer && contar());

  caixa.append(item);
  while (caixa.children.length > MAX_AVISOS) caixa.firstElementChild.remove();
  contar();
  return { elemento: item, fechar };
}

/* ------------------------------------------------------------------ janelas (modais) */

const pilhaModais = [];
let contadorModais = 0;
const FOCAVEIS = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), '
  + 'textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"], [contenteditable="plaintext-only"], summary';

function focaveis(raiz) {
  return [...raiz.querySelectorAll(FOCAVEIS)].filter((e) => !e.closest('[inert]') && e.getClientRects().length > 0);
}

/**
 * Abre uma janela modal.
 *   titulo, descricao       texto do cabeçalho
 *   corpo                   elemento(s) ou texto
 *   acoes                   [{ rotulo, tipo: "primario"|"secundario"|"fantasma"|"perigo", fn, fechar = true, foco, icone, valor }]
 *                           fn pode ser assíncrona (o botão fica ocupado); devolver false mantém a janela aberta.
 *   aoFechar(resultado)     chamado ao fechar (Esc, ×, clique fora ou ação)
 *   tamanho                 "pequeno" | "normal" | "largo" | "tela-cheia"
 *   cabecalho               elemento que substitui o cabeçalho padrão (ex.: barra da prévia em tela cheia)
 * Devolve { elemento, corpo, fechar(resultado), promessa }.
 */
export function modal(opcoes = {}) {
  const {
    titulo = '', descricao = '', corpo = null, acoes = [], aoFechar = null, tamanho = 'normal', classe = '',
    fecharAoClicarFora = true, rotulo = '', cabecalho = null,
  } = opcoes;
  const id = `modal-${++contadorModais}`;
  const anterior = document.activeElement;
  const inertes = [];
  let fechado = false;
  let resolver;
  const promessa = new Promise((r) => {
    resolver = r;
  });

  const caixaCorpo = anexar(el('div', { class: 'modal__corpo' }), corpo);
  const janela = el('div', {
    class: ['modal', `modal--${tamanho}`, classe],
    role: 'dialog',
    'aria-modal': 'true',
    'aria-labelledby': titulo ? `${id}-t` : null,
    'aria-label': !titulo ? (rotulo || null) : null,
    'aria-describedby': descricao ? `${id}-d` : null,
    tabindex: '-1',
  });
  const botaoFechar = el('button', { type: 'button', class: 'icone-btn modal__fechar', 'aria-label': 'Fechar', onclick: () => api.fechar() }, icone('fechar'));
  if (cabecalho) {
    janela.append(cabecalho);
  } else {
    janela.append(el('div', { class: 'modal__cab' },
      el('div', { class: 'modal__titulos' },
        titulo ? el('h2', { class: 'modal__titulo', id: `${id}-t` }, titulo) : null,
        descricao ? el('p', { class: 'modal__desc', id: `${id}-d` }, descricao) : null),
      botaoFechar));
  }
  janela.append(caixaCorpo);
  let focoAcao = null;
  if (acoes.length > 0) {
    const barra = el('div', { class: 'modal__acoes' });
    for (const acao of acoes) {
      const b = botaoAcao(acao);
      if (acao.foco) focoAcao = b;
      barra.append(b);
    }
    janela.append(barra);
  }
  const fundo = el('div', { class: 'modal-fundo' }, janela);

  function botaoAcao(acao) {
    const tipo = acao.tipo ?? 'secundario';
    const b = el('button', { type: 'button', class: ['btn', tipo !== 'secundario' && `btn--${tipo}`] },
      acao.icone ? icone(acao.icone) : null, acao.rotulo);
    b.addEventListener('click', async () => {
      if (b.disabled) return;
      let r;
      if (typeof acao.fn === 'function') {
        b.disabled = true;
        b.setAttribute('aria-busy', 'true');
        try {
          r = await acao.fn(api);
        } catch (e) {
          aviso(e?.mensagem ?? e?.message ?? 'Algo deu errado. Tente de novo.', { tipo: 'erro' });
          r = false;
        } finally {
          b.disabled = false;
          b.removeAttribute('aria-busy');
        }
      }
      if (r === false || acao.fechar === false) return;
      api.fechar(acao.valor ?? acao.rotulo);
    });
    return b;
  }

  function aoTecla(ev) {
    if (pilhaModais[pilhaModais.length - 1] !== api) return;
    if (ev.key === 'Escape') {
      ev.preventDefault();
      ev.stopPropagation();
      api.fechar();
    } else if (ev.key === 'Tab') {
      const lista = focaveis(janela);
      if (lista.length === 0) {
        ev.preventDefault();
        janela.focus();
        return;
      }
      const primeiro = lista[0];
      const ultimo = lista[lista.length - 1];
      if (ev.shiftKey && (document.activeElement === primeiro || document.activeElement === janela)) {
        ev.preventDefault();
        ultimo.focus();
      } else if (!ev.shiftKey && document.activeElement === ultimo) {
        ev.preventDefault();
        primeiro.focus();
      }
    }
  }

  // Clique fora fecha — mas não quando o arrasto começou dentro da janela (seleção de texto).
  let comecouFora = false;
  fundo.addEventListener('mousedown', (ev) => {
    comecouFora = ev.target === fundo;
  });
  fundo.addEventListener('click', (ev) => {
    if (fecharAoClicarFora && comecouFora && ev.target === fundo) api.fechar();
  });

  const api = {
    elemento: janela,
    corpo: caixaCorpo,
    promessa,
    fechar(resultado) {
      if (fechado) return;
      fechado = true;
      document.removeEventListener('keydown', aoTecla, true);
      for (const e of inertes) e.inert = false;
      fundo.remove();
      const i = pilhaModais.indexOf(api);
      if (i >= 0) pilhaModais.splice(i, 1);
      if (anterior && anterior.isConnected && typeof anterior.focus === 'function') anterior.focus({ preventScroll: true });
      try {
        aoFechar?.(resultado);
      } finally {
        resolver(resultado);
      }
    },
  };

  // O resto da página fica inerte (nem foco nem clique) enquanto a janela está aberta.
  for (const filho of document.body.children) {
    if (filho.id === 'avisos' || filho.inert || filho.tagName === 'SCRIPT') continue;
    filho.inert = true;
    inertes.push(filho);
  }
  document.body.append(fundo);
  pilhaModais.push(api);
  document.addEventListener('keydown', aoTecla, true);

  const inicial = caixaCorpo.querySelector('[data-foco-inicial], [autofocus]') ?? focoAcao ?? focaveis(caixaCorpo)[0] ?? janela;
  inicial.focus({ preventScroll: true });
  return api;
}

/** Pergunta de sim/não. `perigo` pinta o botão de confirmar de vermelho e começa com o foco em "Cancelar". */
export function confirmar(mensagem, opcoes = {}) {
  const { titulo = 'Confirmar', confirmar: rotuloOk = 'Confirmar', cancelar = 'Cancelar', perigo = false } = opcoes;
  return new Promise((resolver) => {
    let ok = false;
    modal({
      titulo,
      tamanho: 'pequeno',
      corpo: typeof mensagem === 'string' ? el('p', { class: 'modal__texto' }, mensagem) : mensagem,
      acoes: [
        { rotulo: cancelar, tipo: 'fantasma', foco: perigo },
        { rotulo: rotuloOk, tipo: perigo ? 'perigo' : 'primario', foco: !perigo, fn: () => { ok = true; } },
      ],
      aoFechar: () => resolver(ok),
    });
  });
}

/* ------------------------------------------------------------------ peças de formulário e estados */

let contadorCampos = 0;

/**
 * Campo de formulário (.campo): rótulo + entrada + ajuda + erro (+ contador a partir de 80% do `max`).
 * entrada: elemento <input>/<select>/<textarea> já criado. Devolve { elemento, entrada, definirErro(msg), atualizarContador() }.
 */
export function campo({ rotulo, entrada, ajuda = '', opcional = false, max = 0, classe = '' }) {
  const id = entrada.id || `campo-${++contadorCampos}`;
  entrada.id = id;
  entrada.classList.add('campo__entrada');
  const idAjuda = `${id}-ajuda`;
  const idErro = `${id}-erro`;
  const textoAjuda = el('p', { class: 'campo__ajuda', id: idAjuda, hidden: !ajuda }, ajuda);
  const textoErro = el('p', { class: 'campo__erro', id: idErro, hidden: true });
  const contador = max > 0 ? el('span', { class: 'campo__contador', 'aria-hidden': 'true', hidden: true }) : null;
  const descritores = [ajuda ? idAjuda : null].filter(Boolean);
  if (descritores.length) entrada.setAttribute('aria-describedby', descritores.join(' '));
  if (max > 0) entrada.setAttribute('maxlength', String(max));
  const elemento = el('div', { class: ['campo', classe] },
    el('label', { class: 'campo__rotulo', for: id }, rotulo, opcional ? el('span', { class: 'campo__opcional' }, ' (opcional)') : null),
    entrada, contador, textoAjuda, textoErro);

  function atualizarContador() {
    if (!contador) return;
    const n = [...String(entrada.value ?? '')].length;
    const mostrar = n >= Math.ceil(max * 0.8);
    contador.hidden = !mostrar;
    contador.textContent = `${n}/${max}`;
    contador.classList.toggle('campo__contador--limite', n >= max);
  }
  if (contador) entrada.addEventListener('input', atualizarContador);

  function definirErro(mensagem) {
    const tem = typeof mensagem === 'string' && mensagem !== '';
    textoErro.textContent = tem ? mensagem : '';
    textoErro.hidden = !tem;
    elemento.classList.toggle('campo--erro', tem);
    entrada.setAttribute('aria-invalid', tem ? 'true' : 'false');
    const ids = [ajuda ? idAjuda : null, tem ? idErro : null].filter(Boolean);
    if (ids.length) entrada.setAttribute('aria-describedby', ids.join(' '));
    else entrada.removeAttribute('aria-describedby');
  }
  atualizarContador();
  return { elemento, entrada, definirErro, atualizarContador, definirAjuda(t) { textoAjuda.textContent = t; textoAjuda.hidden = !t; } };
}

/** Indicador de carregamento (anunciado aos leitores de tela). */
export function carregando(rotulo = 'Carregando…') {
  return el('div', { class: 'carregando', role: 'status' }, el('span', { class: 'giro', 'aria-hidden': 'true' }), el('span', null, rotulo));
}

/** Estado vazio amigável: { icone, titulo, texto, acao: elemento } */
export function estadoVazio({ icone: nomeIcone = 'brilho', titulo, texto = '', acao = null }) {
  return el('div', { class: 'vazio' },
    el('div', { class: 'vazio__ico' }, icone(nomeIcone)),
    el('h2', { class: 'vazio__titulo' }, titulo),
    texto ? el('p', { class: 'vazio__texto' }, texto) : null,
    acao);
}

/** Tela de erro com "Tentar de novo". */
export function telaErro(mensagem, tentarDeNovo) {
  return el('div', { class: 'vazio vazio--erro', role: 'alert' },
    el('div', { class: 'vazio__ico' }, icone('alerta')),
    el('h2', { class: 'vazio__titulo' }, 'Não foi possível carregar'),
    el('p', { class: 'vazio__texto' }, mensagem),
    tentarDeNovo ? el('button', { type: 'button', class: 'btn btn--primario', onclick: tentarDeNovo }, 'Tentar de novo') : null);
}

/* ------------------------------------------------------------------ datas e tamanhos (puras) */

/** "agora há pouco", "há 5 minutos", "há 2 horas", "ontem", "há 3 dias", "há 2 meses", "há 1 ano". */
export function tempoRelativo(data, agora = Date.now()) {
  const t = data instanceof Date ? data.getTime() : (typeof data === 'number' ? data : Date.parse(String(data ?? '')));
  if (!Number.isFinite(t)) return '';
  const s = Math.max(0, Math.floor((agora - t) / 1000));
  if (s < 60) return 'agora há pouco';
  const min = Math.floor(s / 60);
  if (min < 60) return min === 1 ? 'há 1 minuto' : `há ${min} minutos`;
  const h = Math.floor(min / 60);
  if (h < 24) return h === 1 ? 'há 1 hora' : `há ${h} horas`;
  const d = Math.floor(h / 24);
  if (d === 1) return 'ontem';
  if (d < 30) return `há ${d} dias`;
  if (d < 365) {
    const m = Math.max(1, Math.floor(d / 30));
    return m === 1 ? 'há 1 mês' : `há ${Math.min(m, 11)} meses`;
  }
  const a = Math.floor(d / 365);
  return a === 1 ? 'há 1 ano' : `há ${a} anos`;
}

/** "06/10/2026 às 14:32" no fuso do navegador. */
export function formatarDataHora(data) {
  const t = data instanceof Date ? data : new Date(String(data ?? ''));
  if (Number.isNaN(t.getTime())) return '';
  const dia = t.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const hora = t.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  return `${dia} às ${hora}`;
}

/** 1536 → "1,5 KB"; 5242880 → "5 MB". */
export function formatarTamanho(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} bytes`;
  const kb = n / 1024;
  if (kb < 1024) return `${kb.toLocaleString('pt-BR', { maximumFractionDigits: kb < 10 ? 1 : 0 })} KB`;
  const mb = kb / 1024;
  return `${mb.toLocaleString('pt-BR', { maximumFractionDigits: mb < 10 ? 1 : 0 })} MB`;
}

/* ------------------------------------------------------------------ telefone / WhatsApp (puras) */

/** Dígitos para a máscara: aceita colar "+55 11 98765-4321" ou "011 98765-4321"; no máximo 11. */
function digitosParaMascara(valor) {
  let d = soDigitos(valor);
  if (d.length > 11 && d.startsWith('55')) d = d.slice(2);
  else if (d.length > 10 && d.startsWith('0')) d = d.slice(1);
  return d.slice(0, 11);
}

/**
 * Máscara progressiva enquanto digita: "(11) 98765-4321" (celular, 3º dígito 9) ou
 * "(11) 3456-7890" (fixo). Não reorganiza a cada tecla: o formato segue o 3º dígito.
 */
export function mascaraTelefone(valor) {
  const d = digitosParaMascara(valor);
  if (d.length === 0) return '';
  if (d.length <= 2) return `(${d}`;
  const ddd = d.slice(0, 2);
  const resto = d.slice(2);
  const prefixo = resto[0] === '9' || d.length === 11 ? 5 : 4;
  if (resto.length <= prefixo) return `(${ddd}) ${resto}`;
  return `(${ddd}) ${resto.slice(0, prefixo)}-${resto.slice(prefixo)}`;
}

/**
 * Problema no WhatsApp digitado (mensagem pronta) ou null se está tudo certo.
 * Vazio é permitido (o site usa o número de exemplo até ser preenchido).
 * A regra final é a mesma do servidor: validarWhatsapp (10/11 dígitos, DDD válido).
 */
export function problemaWhatsapp(valor) {
  const d = digitosParaMascara(valor);
  if (d.length === 0) return null;
  if (d.length < 10) return 'Digite o número completo com DDD, como (11) 98765-4321.';
  const ddd = d.slice(0, 2);
  if (!validarWhatsapp(`${ddd}987654321`)) return `O DDD ${ddd} não existe. Confira o código da cidade.`;
  if (d.length === 11 && d[2] !== '9') return 'Celular com 11 dígitos começa com 9 depois do DDD.';
  if (d.length === 10 && !(d[2] >= '2' && d[2] <= '5')) return 'Número fixo começa com 2, 3, 4 ou 5 depois do DDD. Celular tem 11 dígitos.';
  return validarWhatsapp(d) ? null : 'Número de WhatsApp inválido.';
}

export const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB',
  'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

/* ------------------------------------------------------------------ cores */

/** As 9 cores sugeridas (assistente e aba Estilo). */
export const CORES_SUGERIDAS = Object.freeze([
  { cor: '#2a7f86', nome: 'Verde-azulado' },
  { cor: '#2e6fd1', nome: 'Azul' },
  { cor: '#1b2a4a', nome: 'Azul-marinho' },
  { cor: '#1f7a4d', nome: 'Verde' },
  { cor: '#6b4fa0', nome: 'Roxo' },
  { cor: '#c2531b', nome: 'Laranja queimado' },
  { cor: '#b08a2e', nome: 'Dourado' },
  { cor: '#c23b6e', nome: 'Magenta' },
  { cor: '#2d3340', nome: 'Grafite' },
]);

/** A cor é clara demais (L > 75%)? Mesma regra da paleta (§4.1). */
export function corClaraDemais(hex) {
  const cor = normalizarCor(hex);
  return cor !== null && gerarPaleta(cor).claraDemais;
}

/**
 * Cor dominante de um logo a partir dos pixels RGBA (ImageData.data ou array comum).
 * Ignora pixels transparentes e dá preferência às cores "de marca": tons com saturação
 * visível, nem quase brancos nem quase pretos. Agrupa em caixas de 4 bits por canal e
 * devolve a média da caixa mais pesada (peso = quantidade, com bônus de até 2× para a
 * saturação — a cor mais presente ganha, e cores vivas desempatam). Logo só em
 * preto/cinza → o tom escuro mais comum; nada aproveitável → null.
 */
export function corDominante(pixels, { passo = 1 } = {}) {
  const n = Math.floor((pixels?.length ?? 0) / 4);
  if (n === 0) return null;
  const caixas = new Map();
  const escuros = new Map();
  const avancar = Math.max(1, Math.floor(passo));
  for (let i = 0; i < n; i += avancar) {
    const o = i * 4;
    const a = pixels[o + 3];
    if (a < 128) continue;
    const r = pixels[o];
    const g = pixels[o + 1];
    const b = pixels[o + 2];
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const croma = max - min;
    const luz = (max + min) / 510;
    if (croma >= 40 && luz > 0.08 && luz < 0.93) {
      const chave = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
      const c = caixas.get(chave) ?? { peso: 0, r: 0, g: 0, b: 0, n: 0 };
      c.peso += 0.5 + croma / 510;
      c.r += r;
      c.g += g;
      c.b += b;
      c.n += 1;
      caixas.set(chave, c);
    } else if (luz <= 0.45) {
      const chave = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
      const c = escuros.get(chave) ?? { peso: 0, r: 0, g: 0, b: 0, n: 0 };
      c.peso += 1;
      c.r += r;
      c.g += g;
      c.b += b;
      c.n += 1;
      escuros.set(chave, c);
    }
  }
  const melhor = (mapa) => {
    let m = null;
    for (const c of mapa.values()) if (m === null || c.peso > m.peso) m = c;
    return m;
  };
  const escolhida = melhor(caixas) ?? melhor(escuros);
  if (!escolhida) return null;
  return rgbParaHex([escolhida.r / escolhida.n, escolhida.g / escolhida.n, escolhida.b / escolhida.n].map((v) => Math.round(v)));
}

/** Lê uma imagem (File/Blob ou URL) num canvas pequeno e devolve a cor dominante (ou null). */
export async function corDominanteDaImagem(fonte, { lado = 96 } = {}) {
  const ehUrl = typeof fonte === 'string';
  const url = ehUrl ? fonte : URL.createObjectURL(fonte);
  try {
    const img = new Image();
    img.decoding = 'async';
    img.src = url;
    await img.decode();
    let w = img.naturalWidth || lado;
    let h = img.naturalHeight || lado;
    const escala = Math.min(1, lado / Math.max(w, h));
    w = Math.max(1, Math.round(w * escala));
    h = Math.max(1, Math.round(h * escala));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(img, 0, 0, w, h);
    return corDominante(ctx.getImageData(0, 0, w, h).data);
  } catch {
    return null;
  } finally {
    if (!ehUrl) URL.revokeObjectURL(url);
  }
}

/* ------------------------------------------------------------------ arquivos */

const TIPOS_LOGO = { 'image/png': 'PNG', 'image/jpeg': 'JPG', 'image/svg+xml': 'SVG', 'image/webp': 'WebP' };
const EXTENSOES_LOGO = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', svg: 'image/svg+xml', webp: 'image/webp' };
export const LIMITE_LOGO_BYTES = 5 * 1024 * 1024;
export const ACEITA_LOGO = '.png,.jpg,.jpeg,.svg,.webp,image/png,image/jpeg,image/svg+xml,image/webp';

/** Logo: PNG, JPG, SVG ou WebP até 5 MB. Devolve a mensagem do problema ou null. */
export function problemaArquivoLogo(arquivo) {
  if (!arquivo) return 'Escolha um arquivo de imagem.';
  const ext = String(arquivo.name ?? '').toLowerCase().split('.').pop();
  const tipo = arquivo.type || EXTENSOES_LOGO[ext] || '';
  if (!TIPOS_LOGO[tipo]) return 'Use um arquivo PNG, JPG, SVG ou WebP.';
  if ((arquivo.size ?? 0) > LIMITE_LOGO_BYTES) return `O logo pode ter no máximo 5 MB (este tem ${formatarTamanho(arquivo.size)}).`;
  if ((arquivo.size ?? 0) === 0) return 'O arquivo está vazio.';
  return null;
}
