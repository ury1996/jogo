// Atualização mínima do DOM da prévia (sem trocar o que não mudou).
//
// O editor re-renderiza o site inteiro a cada alteração (prepararSite é rápido), mas trocar o
// innerHTML perderia o foco, o cursor de digitação, os <details> abertos e faria as fotos
// piscarem. `morfar(raiz, html)` compara a árvore atual com a nova e só mexe no que mudou:
// texto, atributos e nós que entraram ou saíram. Filhos com `id` (as seções, que têm âncora
// única) são casados pelo id, então reordenar seções move os nós em vez de recriá-los.
//
// Estado do editor que o HTML novo não tem e precisa ficar:
//   - atributos de edição em [data-k] / [data-img] / [data-ic] (contenteditable, tabindex, role…)
//   - classes "ed-…" (realces do editor)
//   - o atributo `open` de <details> (o usuário abriu uma pergunta para editar a resposta)
//   - o conteúdo do campo que está sendo digitado (elemento com foco)

const ATRIBUTOS_EDICAO = new Set([
  'contenteditable', 'spellcheck', 'tabindex', 'role', 'aria-label', 'aria-describedby', 'aria-multiline',
  'aria-pressed', 'aria-haspopup', 'translate', 'autocorrect', 'autocapitalize', 'enterkeyhint',
]);

function ehEditavel(e) {
  return e.hasAttribute('data-k') || e.hasAttribute('data-img') || e.hasAttribute('data-ic');
}

function sincronizarClasses(atual, nova) {
  const manter = [...atual.classList].filter((c) => c.startsWith('ed-'));
  const alvo = [nova ?? '', ...manter].join(' ').trim().split(/\s+/).filter(Boolean);
  const desejada = [...new Set(alvo)].join(' ');
  if ((atual.getAttribute('class') ?? '') !== desejada) {
    if (desejada === '') atual.removeAttribute('class');
    else atual.setAttribute('class', desejada);
  }
}

function sincronizarAtributos(atual, novo) {
  const editavel = ehEditavel(atual);
  const detalhes = atual.tagName === 'DETAILS';
  for (const { name } of [...atual.attributes]) {
    if (name === 'class') continue;
    if (novo.hasAttribute(name)) continue;
    if (editavel && ATRIBUTOS_EDICAO.has(name)) continue;
    if (detalhes && name === 'open') continue;
    if (name.startsWith('data-ed')) continue;
    atual.removeAttribute(name);
  }
  for (const { name, value } of [...novo.attributes]) {
    if (name === 'class') continue;
    if (detalhes && name === 'open') continue;
    if (editavel && ATRIBUTOS_EDICAO.has(name) && atual.hasAttribute(name)) continue;
    if (atual.getAttribute(name) !== value) atual.setAttribute(name, value);
  }
  sincronizarClasses(atual, novo.getAttribute('class'));
}

function mesmoTipo(a, b) {
  if (a.nodeType !== b.nodeType) return false;
  if (a.nodeType !== 1) return true;
  if (a.tagName !== b.tagName) return false;
  const ia = a.getAttribute('id');
  const ib = b.getAttribute('id');
  return !(ia && ib && ia !== ib);
}

function morfarNo(atual, novo, foco) {
  if (atual.nodeType !== 1) {
    if (atual.nodeValue !== novo.nodeValue) atual.nodeValue = novo.nodeValue;
    return;
  }
  sincronizarAtributos(atual, novo);
  // O campo em edição guarda o que o usuário está digitando.
  if (atual === foco && atual.hasAttribute('data-k')) return;
  if (atual.tagName === 'STYLE' || atual.tagName === 'SCRIPT') {
    if (atual.textContent !== novo.textContent) atual.textContent = novo.textContent;
    return;
  }
  morfarFilhos(atual, novo, foco);
}

/** Compara e atualiza os filhos de `atual` para ficarem iguais aos de `novo`. */
export function morfarFilhos(atual, novo, foco = null) {
  const novos = [...novo.childNodes];
  const idsNovos = new Set();
  for (const n of novos) if (n.nodeType === 1 && n.id) idsNovos.add(n.id);
  const porId = new Map();
  for (const c of atual.childNodes) if (c.nodeType === 1 && c.id) porId.set(c.id, c);

  let ref = atual.firstChild;
  for (const n of novos) {
    let candidato = ref;
    if (n.nodeType === 1 && n.id && porId.has(n.id)) {
      candidato = porId.get(n.id);
      porId.delete(n.id);
      if (candidato !== ref) atual.insertBefore(candidato, ref);
    } else if (candidato && candidato.nodeType === 1 && candidato.id && idsNovos.has(candidato.id)) {
      // o nó atual pertence a um id que ainda vai aparecer: não reaproveitar aqui
      candidato = null;
    }
    if (candidato && mesmoTipo(candidato, n)) {
      morfarNo(candidato, n, foco);
      ref = candidato.nextSibling;
    } else {
      atual.insertBefore(n, candidato ?? ref);
      ref = n.nextSibling;
    }
  }
  while (ref) {
    const prox = ref.nextSibling;
    ref.remove();
    ref = prox;
  }
}

/** Atualiza o conteúdo de `raiz` para `html` mexendo só no que mudou. */
export function morfar(raiz, html, foco = (typeof document !== 'undefined' ? document.activeElement : null)) {
  const modelo = raiz.ownerDocument.createElement('template');
  modelo.innerHTML = html;
  morfarFilhos(raiz, modelo.content, foco);
}
