# Colocar o sistema na sua VPS da Hostinger

No fim deste guia o sistema fica num endereço fixo, com HTTPS, ligado 24 horas:

- editor: `https://teste.seudominio.com.br/editor/`
- sites publicados: `https://teste.seudominio.com.br/s/nome-do-site/`

Você manda esse endereço para quem quiser testar. Leva uns 20 minutos, quase tudo esperando.

> **O que é este modo:** é o mesmo "modo demonstração" do Docker Desktop, agora num servidor de verdade
> (banco SQLite, sites em `/s/nome/`, contatos recebidos aparecem no painel e os e-mails de aviso ficam
> guardados em arquivo em vez de sair). Serve muito bem para testes, demonstrações e pilotos. Para
> atender clientes de verdade (cada site no seu subdomínio ou domínio próprio, e-mail saindo de
> verdade), o próximo passo é a instalação de produção, que também dá para fazer nesta mesma VPS.

---

## 0. Antes de começar: a VPS está vazia ou já tem algo?

- **VPS nova ou sem nada importante:** no hPanel, entre em **VPS → Gerenciar** e, em
  **Sistema operacional** (OS & Panel → Operating System), escolha na aba **Aplicativos** o modelo
  **Docker** (Ubuntu 24.04 com Docker já instalado) e clique em **Trocar sistema operacional**.
  Espere uns 10 minutos.
  **Atenção: trocar o sistema operacional apaga tudo o que está na VPS.**
- **VPS que já tem sites ou um painel (CloudPanel, aaPanel, Coolify…):** não troque o sistema. O
  instalador funciona em qualquer Ubuntu/Debian e instala o Docker se faltar, mas ele precisa das
  portas 80 e 443 livres. Se outro programa já usa essas portas, o instalador avisa e para, sem
  mexer em nada. Nesse caso me chame que eu adapto para ficar atrás do que já existe.

O plano mais simples da Hostinger (KVM 1) já basta para testes.

## 1. O endereço (DNS)

Escolha um subdomínio de um domínio seu, por exemplo `teste.seudominio.com.br`, e aponte para a VPS:

1. Pegue o **IP da VPS** na página da VPS no hPanel (algo como `203.0.113.10`).
2. Se o domínio está na Hostinger: hPanel → **Domínios** → o domínio → **DNS / Nameservers** →
   adicione um registro:
   - **Tipo:** A
   - **Nome:** `teste` (a parte antes do domínio)
   - **Aponta para:** o IP da VPS
   - **TTL:** deixe o padrão
3. Se o domínio está em outro lugar (Registro.br, Cloudflare, GoDaddy…), crie o mesmo registro A lá.
   Na Cloudflare, deixe a nuvem **cinza** (só DNS), pelo menos na primeira instalação.

Pode levar de alguns minutos a algumas horas para valer.

**Sem domínio?** Pule esta parte: o instalador usa um endereço provisório que funciona na hora,
feito com o IP (ex.: `https://203-0-113-10.sslip.io/editor/`). Dá para trocar por um domínio depois.

## 2. Entrar na VPS

Dois jeitos:

- **Pelo navegador:** na página da VPS no hPanel há o botão de **terminal no navegador** (Browser
  terminal). Abre uma tela preta já conectada.
- **Pelo seu computador:** abra o Terminal (Mac) ou o PowerShell (Windows) e rode
  `ssh root@IP-DA-VPS`. A senha é a de root da VPS (se não lembra, troque no hPanel, na página da VPS).

Os comandos abaixo são para colar nessa tela, um bloco de cada vez.

## 3. Um "token" do GitHub (o repositório é privado)

A VPS precisa de permissão para baixar o código. Crie uma senha só de leitura (token):

1. Entre em <https://github.com/settings/personal-access-tokens/new>.
2. **Token name:** `VPS Rankly` · **Expiration:** o prazo que preferir.
3. **Repository access:** *Only select repositories* → `ury1996/jogo`.
4. **Permissions** → **Repository permissions** → **Contents:** *Read-only*.
5. **Generate token** e copie o código (começa com `github_pat_…`). Ele só aparece uma vez.

## 4. Baixar o sistema

```bash
apt-get update && apt-get install -y git
git config --global credential.helper store
git clone -b claude/jolly-hawking-m0fur7 https://github.com/ury1996/jogo.git /opt/rankly
```

Quando pedir:

- **Username:** o seu usuário do GitHub.
- **Password:** cole o token. Nada aparece enquanto você cola; é normal.

O `credential.helper store` guarda o token na VPS (só o root lê), para as atualizações não pedirem
de novo.

## 5. Instalar

```bash
cd /opt/rankly
bash bin/instalar-vps.sh
```

O instalador:

1. instala o Docker, se faltar;
2. pergunta o **endereço** (o subdomínio da parte 1, ou Enter para o provisório);
3. pergunta a **chave da IA (Gemini)** e a **chave do Pixabay**, as mesmas do seu `.env` do
   computador (como pegar: `COMO-TESTAR.md`, parte 3). Enter deixa em branco;
4. liga o sistema e o HTTPS (certificado grátis, renovado sozinho);
5. no fim mostra o endereço, o **e-mail e a senha de acesso** (a senha é aleatória, porque o endereço
   é público).

Na primeira vez leva de 3 a 8 minutos (ele monta o sistema).

## 6. Usar e mandar para os outros

Abra o endereço que apareceu + `/editor/`, entre com o e-mail e a senha, e mande o mesmo para quem
for testar. Para cada pessoa ter o próprio acesso:

```bash
cd /opt/rankly
docker compose exec rankly php app/cli/criar-usuario.php --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenhaforte
```

---

## No dia a dia

Sempre dentro da pasta: `cd /opt/rankly`

| Quero… | Comando |
|---|---|
| **atualizar para a versão nova** (ou trocar endereço/chaves) | `bash bin/instalar-vps.sh` |
| ver a senha de acesso de novo | `docker compose exec rankly cat /dados/acesso.txt` |
| ver o que está acontecendo | `docker compose logs -f` (Ctrl+C sai) |
| reiniciar | `docker compose restart` |
| desligar / ligar | `docker compose down` / `docker compose up -d` |
| cópia de segurança dos dados | `docker run --rm -v rankly_rankly-dados:/d -v /root:/b alpine tar czf /b/rankly-backup.tgz -C /d .` |
| apagar tudo e começar do zero | `docker compose down -v` (apaga sites, fotos e contatos) |

O sistema volta sozinho se a VPS reiniciar.

## Problemas comuns

| Sintoma | Solução |
|---|---|
| "Outro programa já usa a porta 80 ou 443" | A VPS já tem um painel ou servidor web. Veja a parte 0. |
| O fim do instalador diz que o HTTPS ainda não respondeu | Quase sempre o DNS ainda não está valendo: espere e rode `bash bin/instalar-vps.sh` de novo. Se você ativou um firewall no hPanel para a VPS, libere as portas **80** e **443**. Detalhes: `docker compose logs --tail=40 caddy`. |
| O `git clone` diz "Authentication failed" | O token foi colado errado, expirou ou não tem acesso ao repositório `ury1996/jogo` com *Contents: Read-only*. Crie outro (parte 3). Se o token errado ficou guardado: `rm /root/.git-credentials` e tente de novo. |
| "Rode como root" | Rode `sudo -i` antes, ou entre como `root`. |
| O quadro mostra "IA: simulada" | Rode o instalador de novo e cole a chave do Gemini quando ele pedir. |
| A página abre sem cadeado ou com aviso de segurança | Use sempre `https://` e o mesmo endereço do instalador. Se trocou de domínio, rode o instalador de novo com o endereço novo. |

## Como funciona (para quem for mexer)

- `docker-compose.yml`: o sistema (PHP + SQLite), com o código da própria pasta.
- `docker-compose.vps.yml`: acrescenta o **Caddy** (`vps/Caddyfile`), que atende nas portas 80/443,
  faz o HTTPS e repassa para o sistema, que fica fechado para fora (`127.0.0.1:8080`). O IP real do
  visitante vai no cabeçalho `X-Real-IP`, aceito só quando vem da rede interna
  (`confiar_proxy_local`), para os limites anti-spam do formulário e do login valerem por visitante.
- O instalador grava no `.env`: `COMPOSE_FILE` (por isso `docker compose …` já usa os dois arquivos),
  `RANKLY_DOMINIO`, `RANKLY_URL`, `RANKLY_PORTA` e as chaves.
