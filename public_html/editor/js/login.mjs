// Login, "esqueci a senha" e redefinição de senha (#/entrar, #/esqueci, #/redefinir?token=…).
//
// montar(alvo, { modo: "entrar" | "esqueci" | "redefinir", token })

import { api } from './api.mjs';
import { el, icone, campo, navegar } from './ui.mjs';
import { destinoDepoisDoLogin } from './app.mjs';

export const SENHA_MIN = 8;
const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Problema no e-mail digitado (mensagem) ou null. */
export function problemaEmail(valor) {
  const v = String(valor ?? '').trim();
  if (v === '') return 'Informe o seu e-mail.';
  if (!RE_EMAIL.test(v)) return 'Confira o e-mail: ele precisa ter o formato nome@empresa.com.br.';
  return null;
}

/** Problema na senha nova (mensagem) ou null. */
export function problemaSenhaNova(senha, confirmacao) {
  const s = String(senha ?? '');
  if ([...s].length < SENHA_MIN) return `A senha precisa ter pelo menos ${SENHA_MIN} caracteres.`;
  if ([...s].length > 200) return 'A senha pode ter no máximo 200 caracteres.';
  if (confirmacao !== undefined && s !== String(confirmacao ?? '')) return 'As duas senhas não são iguais.';
  return null;
}

function marca() {
  return el('div', { class: 'tela-login__marca app-marca', 'aria-hidden': 'true' },
    el('span', { class: 'app-marca__r' }, 'R'), el('span', null, 'Construtor Rankly'));
}

/** Campo de senha com o botão "Mostrar senha". */
function campoSenha({ rotulo, autocomplete, ajuda = '' }) {
  const entrada = el('input', { type: 'password', name: autocomplete === 'current-password' ? 'senha' : 'senha-nova', autocomplete, required: true, spellcheck: 'false' });
  const c = campo({ rotulo, entrada, ajuda });
  const mostrar = el('button', { type: 'button', class: 'icone-btn senha__mostrar', 'aria-label': 'Mostrar senha', 'aria-pressed': 'false' }, icone('olho'));
  mostrar.addEventListener('click', () => {
    const visivel = entrada.type === 'password';
    entrada.type = visivel ? 'text' : 'password';
    mostrar.setAttribute('aria-pressed', String(visivel));
    mostrar.setAttribute('aria-label', visivel ? 'Esconder senha' : 'Mostrar senha');
    mostrar.replaceChildren(icone(visivel ? 'olhoFechado' : 'olho'));
  });
  // Envolve só a entrada (o botão fica dentro dela, à direita).
  const caixa = el('div', { class: 'senha' });
  entrada.replaceWith(caixa);
  caixa.append(entrada, mostrar);
  return c;
}

function caixaErro() {
  return el('div', { class: 'aviso-inline aviso-inline--erro', role: 'alert', hidden: true });
}

function mostrarErro(caixa, mensagem) {
  caixa.replaceChildren(icone('alerta'), el('span', null, mensagem));
  caixa.hidden = false;
}

function botaoOcupado(botao, ocupado) {
  botao.disabled = ocupado;
  if (ocupado) botao.setAttribute('aria-busy', 'true');
  else botao.removeAttribute('aria-busy');
}

/* ------------------------------------------------------------------ entrar */

function telaEntrar() {
  const email = el('input', { type: 'email', name: 'email', autocomplete: 'username', inputmode: 'email', required: true, spellcheck: 'false', autocapitalize: 'none' });
  const cEmail = campo({ rotulo: 'E-mail', entrada: email });
  const cSenha = campoSenha({ rotulo: 'Senha', autocomplete: 'current-password' });
  const erro = caixaErro();
  const botao = el('button', { type: 'submit', class: 'btn btn--primario btn--grande btn--bloco' }, 'Entrar');
  const form = el('form', { class: 'formulario', novalidate: true },
    erro, cEmail.elemento, cSenha.elemento, botao);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    erro.hidden = true;
    const pe = problemaEmail(email.value);
    cEmail.definirErro(pe);
    const ps = cSenha.entrada.value === '' ? 'Informe a sua senha.' : null;
    cSenha.definirErro(ps);
    if (pe) {
      email.focus();
      return;
    }
    if (ps) {
      cSenha.entrada.focus();
      return;
    }
    botaoOcupado(botao, true);
    try {
      const r = await api.post('/auth/login', { email: email.value.trim(), senha: cSenha.entrada.value });
      window.dispatchEvent(new CustomEvent('rk:sessao', { detail: { usuario: r?.usuario ?? null } }));
      navegar(destinoDepoisDoLogin(), { substituir: true });
    } catch (e) {
      botaoOcupado(botao, false);
      const msg = e?.status === 401 || e?.status === 422 || e?.status === 400
        ? (e.mensagem || 'E-mail ou senha incorretos.')
        : (e?.mensagem ?? 'Não foi possível entrar. Tente de novo.');
      mostrarErro(erro, msg);
      cSenha.entrada.select();
      cSenha.entrada.focus();
    }
  });

  return {
    titulo: 'Entrar',
    corpo: [
      el('div', { class: 'cab-login' },
        el('h1', { class: 'tela-login__titulo' }, 'Entrar'),
        el('p', { class: 'tela-login__texto' }, 'Acesse para criar e editar os sites dos seus clientes.')),
      form,
      el('p', { class: 'tela-login__rodape' }, el('a', { class: 'tela-login__link', href: '#/esqueci' }, 'Esqueci a senha')),
    ],
    foco: email,
  };
}

/* ------------------------------------------------------------------ esqueci a senha */

function telaEsqueci() {
  const email = el('input', { type: 'email', name: 'email', autocomplete: 'username', inputmode: 'email', required: true, spellcheck: 'false', autocapitalize: 'none' });
  const cEmail = campo({ rotulo: 'E-mail da sua conta', entrada: email });
  const erro = caixaErro();
  const botao = el('button', { type: 'submit', class: 'btn btn--primario btn--grande btn--bloco' }, 'Enviar link para redefinir');
  const form = el('form', { class: 'formulario', novalidate: true }, erro, cEmail.elemento, botao);
  const card = el('div', { class: 'tela-login__conteudo' },
    el('div', { class: 'cab-login' },
      el('h1', { class: 'tela-login__titulo' }, 'Esqueci a senha'),
      el('p', { class: 'tela-login__texto' }, 'Informe o e-mail da sua conta. Enviaremos um link para você criar uma senha nova.')),
    form);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    erro.hidden = true;
    const pe = problemaEmail(email.value);
    cEmail.definirErro(pe);
    if (pe) {
      email.focus();
      return;
    }
    botaoOcupado(botao, true);
    try {
      await api.post('/auth/esqueci', { email: email.value.trim() });
      const titulo = el('h1', { class: 'tela-login__titulo', tabindex: '-1' }, 'Confira o seu e-mail');
      card.replaceChildren(
        el('div', { class: 'cab-login' },
          el('div', { class: 'continuar__ico' }, icone('envelope')),
          titulo,
          el('p', { class: 'tela-login__texto' },
            'Se existir uma conta com ', el('strong', null, email.value.trim()),
            ', enviamos um link para redefinir a senha. O link vale por 1 hora.')),
        el('p', { class: 'tela-login__texto' }, 'Não chegou? Olhe a caixa de spam ou peça de novo em alguns minutos.'),
        el('a', { class: 'btn btn--bloco', href: '#/entrar' }, icone('voltar'), 'Voltar para entrar'));
      titulo.focus();
    } catch (e) {
      botaoOcupado(botao, false);
      mostrarErro(erro, e?.mensagem ?? 'Não foi possível enviar agora. Tente de novo.');
    }
  });

  return {
    titulo: 'Esqueci a senha',
    corpo: [card, el('p', { class: 'tela-login__rodape' }, el('a', { class: 'tela-login__link', href: '#/entrar' }, 'Lembrei a senha'))],
    foco: email,
  };
}

/* ------------------------------------------------------------------ redefinir */

function telaRedefinir(token) {
  const tokenValido = /^[a-f0-9]{64}$/.test(String(token ?? ''));
  if (!tokenValido) {
    return {
      titulo: 'Link inválido',
      corpo: [
        el('div', { class: 'cab-login' },
          el('h1', { class: 'tela-login__titulo' }, 'Link inválido ou incompleto'),
          el('p', { class: 'tela-login__texto' }, 'Este link de redefinição não está completo. Abra de novo o link do e-mail ou peça um novo.')),
        el('a', { class: 'btn btn--primario btn--bloco', href: '#/esqueci' }, 'Pedir um link novo'),
        el('p', { class: 'tela-login__rodape' }, el('a', { class: 'tela-login__link', href: '#/entrar' }, 'Voltar para entrar')),
      ],
    };
  }
  const cSenha = campoSenha({ rotulo: 'Senha nova', autocomplete: 'new-password', ajuda: `Pelo menos ${SENHA_MIN} caracteres.` });
  const cConfirma = campoSenha({ rotulo: 'Repita a senha nova', autocomplete: 'new-password' });
  const erro = caixaErro();
  const botao = el('button', { type: 'submit', class: 'btn btn--primario btn--grande btn--bloco' }, 'Salvar a senha nova');
  const form = el('form', { class: 'formulario', novalidate: true }, erro, cSenha.elemento, cConfirma.elemento, botao);
  const card = el('div', { class: 'tela-login__conteudo' },
    el('div', { class: 'cab-login' },
      el('h1', { class: 'tela-login__titulo' }, 'Criar uma senha nova'),
      el('p', { class: 'tela-login__texto' }, 'Escolha uma senha que você não usa em outros sites.')),
    form);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    erro.hidden = true;
    const p1 = problemaSenhaNova(cSenha.entrada.value);
    cSenha.definirErro(p1);
    const p2 = p1 ? null : problemaSenhaNova(cSenha.entrada.value, cConfirma.entrada.value);
    cConfirma.definirErro(p2);
    if (p1 || p2) {
      (p1 ? cSenha.entrada : cConfirma.entrada).focus();
      return;
    }
    botaoOcupado(botao, true);
    try {
      await api.post('/auth/redefinir', { token, senha: cSenha.entrada.value });
      const titulo = el('h1', { class: 'tela-login__titulo', tabindex: '-1' }, 'Senha alterada');
      card.replaceChildren(
        el('div', { class: 'cab-login' },
          el('div', { class: 'continuar__ico' }, icone('ok')),
          titulo,
          el('p', { class: 'tela-login__texto' }, 'Pronto! Agora é só entrar com o seu e-mail e a senha nova.')),
        el('a', { class: 'btn btn--primario btn--bloco', href: '#/entrar' }, 'Entrar'));
      titulo.focus();
    } catch (e) {
      botaoOcupado(botao, false);
      if (e?.dados?.erro?.campo === 'senha' || e?.dados?.campo === 'senha') cSenha.definirErro(e.mensagem);
      else mostrarErro(erro, e?.mensagem ?? 'Não foi possível salvar a senha. Tente de novo.');
    }
  });

  return {
    titulo: 'Redefinir senha',
    corpo: [card, el('p', { class: 'tela-login__rodape' }, el('a', { class: 'tela-login__link', href: '#/entrar' }, 'Voltar para entrar'))],
    foco: cSenha.entrada,
  };
}

/* ------------------------------------------------------------------ montar */

export async function montar(alvo, params = {}) {
  const modo = params.modo === 'esqueci' || params.modo === 'redefinir' ? params.modo : 'entrar';
  const tela = modo === 'esqueci' ? telaEsqueci() : modo === 'redefinir' ? telaRedefinir(params.token) : telaEntrar();
  document.title = `${tela.titulo} · Construtor Rankly`;
  alvo.replaceChildren(el('div', { class: 'tela-login' },
    el('div', { class: 'tela-login__caixa' },
      marca(),
      el('section', { class: 'tela-login__card', 'aria-label': tela.titulo }, ...tela.corpo))));
  tela.foco?.focus({ preventScroll: true });
  return {};
}
