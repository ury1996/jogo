# Modo demonstração: abrir fácil e mandar para outras pessoas testarem

O modo demonstração sobe o sistema inteiro **num endereço só**: o editor em `/editor/` e os sites
publicados em `/s/{nome-do-site}/`. Não precisa de MySQL (usa SQLite), nem de subdomínios, nem de
configurar nada: o primeiro usuário é criado sozinho.

Há três jeitos de usar, do mais fácil para o mais duradouro:

| Jeito | Precisa instalar | Link para outras pessoas | Os dados ficam |
|---|---|---|---|
| **1. GitHub Codespaces** | nada (abre no navegador) | sim, enquanto o Codespace estiver ligado | no Codespace |
| **2. Docker no seu computador** | Docker Desktop | só com um túnel (item 2.1) | no seu computador |
| **3. Docker num servidor (VPS)** | Docker no servidor | sim, sempre no ar | no servidor |

---

## 1. GitHub Codespaces (recomendado para testar e mostrar)

O Codespaces é um computador na nuvem do próprio GitHub. Contas pessoais têm horas gratuitas por
mês (o suficiente para testes; ele desliga sozinho após 30 minutos sem uso).

1. Abra este endereço (estando logado no GitHub com acesso ao repositório):

   <https://codespaces.new/ury1996/jogo?ref=claude/jolly-hawking-m0fur7>

   *(Depois que o código for para a branch principal, use só `https://codespaces.new/ury1996/jogo`.)*
2. Clique em **Create codespace** e espere de 2 a 4 minutos na primeira vez (ele instala tudo).
3. O sistema liga sozinho. Na aba **PORTS** (embaixo), na linha **Construtor Rankly (8080)**,
   clique no ícone de globo para abrir. Abre o editor.
4. Os dados de acesso estão no arquivo `var/demo/acesso.txt` (abra pelo explorador de arquivos à
   esquerda) e também no log `var/demo.log`. O e-mail é `demo@rankly.app`; a senha é aleatória.

**Para mandar para outras pessoas:** na aba **PORTS**, clique com o botão direito na linha da
porta 8080 → **Port Visibility** → **Public**. Copie o endereço (algo como
`https://seu-codespace-8080.app.github.dev`) e envie junto com o e-mail e a senha de teste.
Os sites publicados ficam em `…/s/nome-do-site/` e também podem ser enviados.

- O link só funciona enquanto o Codespace estiver ligado. Para religar: <https://github.com/codespaces>.
- **IA de verdade (Gemini):** em <https://github.com/settings/codespaces> → **New secret**, nome
  `GEMINI_API_KEY`, valor = a sua chave (veja o `COMO-TESTAR.md` §3.1), libere para o repositório
  `ury1996/jogo` e reinicie o Codespace. Sem a chave, a IA funciona em modo simulado.
- **Mais usuários de teste:** no terminal do Codespace:
  `RANKLY_CONFIG=config/config.demo.php php app/cli/criar-usuario.php --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenha123`
- **Recomeçar do zero:** apague a pasta `var/demo` e reinicie o Codespace.

---

## 2. Docker no seu computador

Instale o [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows, Mac ou
Linux). Na pasta do projeto:

```bash
docker compose up
```

Abra <http://localhost:8080/editor/> e entre com `demo@rankly.app` / `demo12345`.
Os dados ficam guardados num volume do Docker (`rankly-dados`) entre uma vez e outra.
Ctrl+C desliga. Para a IA de verdade: `GEMINI_API_KEY=sua-chave docker compose up`.

Sem Docker, com PHP instalado (veja o `COMO-TESTAR.md` §1): `composer install` e depois
`bin/demo.sh`.

### 2.1 Mostrar para alguém de fora, a partir do seu computador

Com o [cloudflared](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/)
instalado (gratuito, sem conta):

```bash
cloudflared tunnel --url http://localhost:8080
```

Ele mostra um endereço `https://algumas-palavras.trycloudflare.com`. Desligue o sistema e ligue de
novo informando esse endereço (os links dos sites e o formulário de contato usam o endereço público):

```bash
RANKLY_URL=https://algumas-palavras.trycloudflare.com docker compose up
```

O endereço muda cada vez que o túnel é aberto. Num endereço público, o usuário de teste criado na
primeira vez recebe uma **senha aleatória**, mostrada no terminal ao ligar.

---

## 3. Docker num servidor (servidor de testes sempre no ar)

Qualquer VPS com Docker serve (1 GB de memória basta). No servidor:

```bash
git clone https://github.com/ury1996/jogo.git rankly && cd rankly
git checkout claude/jolly-hawking-m0fur7
RANKLY_URL=https://teste.seudominio.com.br docker compose up -d --build
docker compose logs rankly | grep -A3 "E-mail"     # dados de acesso
```

Coloque um proxy com HTTPS na frente (Caddy é o mais simples:
`teste.seudominio.com.br { reverse_proxy localhost:8080 }`).
Sem domínio, dá para usar o IP com o [sslip.io](https://sslip.io): `RANKLY_URL=http://203-0-113-10.sslip.io:8080`.

---

## O que muda em relação à produção

O modo demonstração é para testes. Em produção (Hostinger/VPS, ver `README.md`):

- os sites ficam num domínio separado, um subdomínio por site (`nome.sitesrankly.com.br`), e não
  em `/s/nome/` no mesmo endereço do editor ([M25] na especificação);
- o banco é MySQL/MariaDB, o servidor é Apache/LiteSpeed e os e-mails saem por SMTP de verdade
  (no modo demonstração viram arquivos em `var/demo/var/emails/`);
- o servidor embutido do PHP (`php -S`) aguenta bem alguns testadores ao mesmo tempo, não tráfego real.
