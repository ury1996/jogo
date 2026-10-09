# Modo demonstração: abrir fácil e mandar para outras pessoas testarem

O modo demonstração sobe o sistema inteiro **num endereço só**: o editor em `/editor/` e os sites
publicados em `/s/{nome-do-site}/`. Não precisa de MySQL (usa SQLite), nem de subdomínios, nem de
configurar nada: o primeiro usuário é criado sozinho.

Há três jeitos de usar, do mais fácil para o mais duradouro:

| Jeito | Precisa instalar | Link para outras pessoas | Os dados ficam |
|---|---|---|---|
| **1. GitHub Codespaces** | nada (abre no navegador) | sim, enquanto o Codespace estiver ligado | no Codespace |
| **2. Docker no seu computador** | Docker Desktop | só com um túnel (item 2.6) | no seu computador |
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
- **Banco de imagens (Pexels):** do mesmo jeito, crie o secret `PEXELS_API_KEY` com a chave grátis
  de <https://www.pexels.com/api/>.
- **Mais usuários de teste:** no terminal do Codespace:
  `RANKLY_CONFIG=config/config.demo.php php app/cli/criar-usuario.php --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenha123`
- **Recomeçar do zero:** apague a pasta `var/demo` e reinicie o Codespace.

---

## 2. Docker no seu computador

O Docker é um programa que roda o sistema dentro de uma "caixa" pronta, com PHP e tudo o que ele
precisa. Você não instala PHP, banco de dados nem nada além do próprio Docker.

### 2.1 Instalar o Docker (uma vez só)

- **Windows 10/11 ou Mac:** baixe e instale o [Docker Desktop](https://www.docker.com/products/docker-desktop/).
  No Mac, escolha a versão do seu processador (Apple/M1-M4 ou Intel). No Windows, aceite quando
  ele pedir para ativar o WSL 2 e reinicie o computador se pedir.
  Depois de instalar, **abra o Docker Desktop** e espere aparecer "Engine running" (a baleia fica
  parada na barra de tarefas). Ele precisa estar aberto sempre que for usar o sistema.
- **Linux (Ubuntu):** `curl -fsSL https://get.docker.com | sudo sh`

Para conferir, abra um terminal (Windows: **PowerShell**; Mac: **Terminal**) e rode
`docker --version`. Tem que aparecer um número de versão.

### 2.2 Baixar o projeto

Escolha um dos dois:

- **Sem git:** no GitHub, abra o repositório `ury1996/jogo`, troque a branch (botão com o nome
  `main`) para `claude/jolly-hawking-m0fur7`, clique em **Code** → **Download ZIP** e descompacte.
- **Com git:** `git clone https://github.com/ury1996/jogo.git rankly` e depois
  `cd rankly` e `git checkout claude/jolly-hawking-m0fur7`.

### 2.3 Ligar

Abra o terminal **dentro da pasta do projeto** (no Windows: abra a pasta no Explorador, clique com
o botão direito num espaço vazio → **Abrir no Terminal**) e rode:

```bash
docker compose up
```

- Na primeira vez ele monta a "caixa": leva de 3 a 10 minutos (baixa uns 500 MB). Nas próximas, segundos.
- Está pronto quando aparecer o quadro **"Construtor Rankly · modo demonstração"** com o endereço,
  o e-mail e a senha.
- Abra <http://localhost:8080/editor/> no navegador e entre com **demo@rankly.app** / **demo12345**.
- Os sites que você publicar abrem em `http://localhost:8080/s/nome-do-site/`.

### 2.4 No dia a dia

| Quero… | Comando (na pasta do projeto) |
|---|---|
| desligar | **Ctrl+C** no terminal onde ele está rodando |
| ligar de novo (os sites e contatos continuam lá) | `docker compose up` |
| deixar ligado sem ocupar o terminal | `docker compose up -d` (e `docker compose down` para desligar) |
| ver o que está acontecendo | `docker compose logs -f` |
| atualizar para uma versão nova do código | baixe o código novo e rode `docker compose up --build` |
| apagar tudo e começar do zero | `docker compose down -v` |

### 2.5 Ajustes opcionais (arquivo `.env`)

Crie um arquivo chamado `.env` (só isso, com o ponto na frente) na pasta do projeto, ao lado do
`docker-compose.yml`, com as linhas que quiser:

```
GEMINI_API_KEY=cole-aqui-a-sua-chave
PEXELS_API_KEY=cole-aqui-a-chave-do-pexels
RANKLY_PORTA=8090
```

- `GEMINI_API_KEY` liga a IA de verdade (como criar a chave: `COMO-TESTAR.md` §3.1).
- `PEXELS_API_KEY` liga o banco de imagens no editor (chave grátis: `COMO-TESTAR.md` §3.2).
- `RANKLY_PORTA` muda a porta se a 8080 já estiver em uso no seu computador; aí o endereço vira
  `http://localhost:8090/editor/`.

Depois de mudar o `.env`, desligue e ligue de novo (`docker compose up`).

### 2.6 Mostrar para alguém de fora, a partir do seu computador

O `localhost` só abre no seu próprio computador. Para alguém de fora acessar enquanto o seu
computador estiver ligado, use um túnel gratuito, o
[cloudflared](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/)
(não precisa de conta):

1. Com o sistema ligado, abra **outro** terminal e rode `cloudflared tunnel --url http://localhost:8080`.
2. Ele mostra um endereço como `https://algumas-palavras.trycloudflare.com`. Copie.
3. No `.env`, coloque `RANKLY_URL=https://algumas-palavras.trycloudflare.com` e reinicie o sistema
   (Ctrl+C e `docker compose up`). Isso é necessário porque os links dos sites e o formulário de
   contato usam o endereço público.
4. Mande esse endereço + `/editor/` para a pessoa, com o e-mail e a senha.

O endereço muda cada vez que o túnel é aberto (aí repita os passos 2 e 3). Os sites e contatos
criados antes continuam, mas os sites precisam ser publicados de novo para os links internos
usarem o endereço novo.

---

## 3. Servidor de testes sempre no ar

Um servidor (VPS) é um computador alugado que fica ligado 24 horas. Com ele, o sistema fica num
endereço fixo (ex.: `https://teste.sitesrankly.com.br`) que você manda para quem quiser, sem
depender do seu computador.

> A hospedagem compartilhada da Hostinger (onde o sistema vai rodar em produção) **não** roda
> Docker. Para o servidor de testes, use um VPS. A instalação de produção é outra (veja o `README.md`).

### 3.1 O que contratar

- Um **VPS com Ubuntu 24.04**, 1 a 2 GB de memória, 1 processador, 20 GB de disco. Exemplos:
  Hostinger VPS (KVM 1), Hetzner, DigitalOcean, Contabo, Magalu Cloud. Custa em torno de
  R$ 25 a R$ 50 por mês. Na hora de criar, escolha "Ubuntu 24.04" e anote o **IP** e a **senha de root**.
- Opcional, mas recomendado: um **subdomínio** para o teste, ex.: `teste.sitesrankly.com.br`.
  No painel de DNS do domínio (na Hostinger: **Domínios → DNS / Nameservers**), crie um registro
  **A** com nome `teste` apontando para o IP do servidor. Leva de alguns minutos a algumas horas para valer.

### 3.2 Instalar (uma vez só)

No seu computador, abra o terminal e entre no servidor (troque pelo IP dele; ele pede a senha de root):

```bash
ssh root@203.0.113.10
```

Dentro do servidor, rode um bloco de cada vez:

```bash
# 1. Docker
curl -fsSL https://get.docker.com | sh

# 2. Código (o repositório é privado: o GitHub pede usuário e um token no lugar da senha —
#    crie em github.com → Settings → Developer settings → Personal access tokens → Fine-grained,
#    só com leitura do repositório ury1996/jogo)
git clone https://github.com/ury1996/jogo.git /opt/rankly
cd /opt/rankly
git checkout claude/jolly-hawking-m0fur7

# 3. Configuração: troque pelo seu endereço (e a chave da IA, se tiver)
cat > .env <<'FIM'
RANKLY_URL=https://teste.sitesrankly.com.br
RANKLY_PORTA=127.0.0.1:8080
GEMINI_API_KEY=
FIM

# 4. Ligar (fica ligado sozinho, inclusive depois de reiniciar o servidor)
docker compose up -d --build
```

`RANKLY_PORTA=127.0.0.1:8080` deixa o sistema acessível só de dentro do servidor: quem fala com a
internet é o Caddy (próximo passo), que cuida do HTTPS.

### 3.3 HTTPS com o Caddy

O Caddy recebe as visitas em `https://` e entrega para o sistema. Ele tira e renova o certificado
sozinho.

```bash
apt install -y caddy
cat > /etc/caddy/Caddyfile <<'FIM'
teste.sitesrankly.com.br {
    reverse_proxy 127.0.0.1:8080
}
FIM
systemctl reload caddy
```

Se o provedor tiver firewall no painel, libere as portas **80** e **443**.
Abra `https://teste.sitesrankly.com.br/editor/` no navegador.

**Sem domínio?** Dá para usar o [sslip.io](https://sslip.io), que transforma o IP em endereço: no
`.env` use `RANKLY_URL=https://203-0-113-10.sslip.io` (o IP com traços) e no Caddyfile
`203-0-113-10.sslip.io { reverse_proxy 127.0.0.1:8080 }`.

### 3.4 Pegar o e-mail e a senha de acesso

Num endereço público a senha é criada aleatória (uma senha fixa conhecida deixaria qualquer um entrar):

```bash
cd /opt/rankly
docker compose exec rankly cat /dados/acesso.txt
```

Para criar mais usuários (um para cada pessoa que vai testar):

```bash
docker compose exec rankly php app/cli/criar-usuario.php --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenhaforte
```

### 3.5 No dia a dia

| Quero… | Comando (no servidor, dentro de `/opt/rankly`) |
|---|---|
| atualizar para a versão nova do código | `git pull && docker compose up -d --build` |
| ver o que está acontecendo | `docker compose logs -f` |
| reiniciar | `docker compose restart` |
| desligar | `docker compose down` |
| apagar tudo e começar do zero | `docker compose down -v` (apaga sites, fotos e contatos) |
| cópia de segurança dos dados | `docker run --rm -v rankly_rankly-dados:/d -v $PWD:/b alpine tar czf /b/backup.tgz -C /d .` |

Os e-mails (aviso de contato, redefinir senha) não saem de verdade no modo demonstração: ficam em
arquivos, que dá para ver com `docker compose exec rankly ls /dados/var/emails`.

---

## O que muda em relação à produção

O modo demonstração é para testes. Em produção (Hostinger/VPS, ver `README.md`):

- os sites ficam num domínio separado, um subdomínio por site (`nome.sitesrankly.com.br`), e não
  em `/s/nome/` no mesmo endereço do editor ([M25] na especificação);
- o banco é MySQL/MariaDB, o servidor é Apache/LiteSpeed e os e-mails saem por SMTP de verdade
  (no modo demonstração viram arquivos em `var/demo/var/emails/`);
- o servidor embutido do PHP (`php -S`) aguenta bem alguns testadores ao mesmo tempo, não tráfego real.
