# Como testar o Construtor Rankly (com o Docker Desktop)

Este é o jeito padrão de rodar o sistema para testar: tudo dentro do **Docker Desktop**, sem
instalar PHP, banco de dados nem nada além dele. Leva uns 20 minutos na primeira vez (a maior parte
é o Docker baixando o que precisa) e 1 minuto nas próximas.

O que você vai fazer:

1. instalar o Docker Desktop (uma vez só);
2. baixar o projeto;
3. criar o arquivo `.env` com as duas chaves grátis (IA e banco de imagens);
4. ligar o sistema com `docker compose up`;
5. testar o fluxo completo: criar um site, editar, publicar e receber um contato.

Tudo fica num endereço só: o editor em <http://localhost:8080/editor/> e cada site publicado em
`http://localhost:8080/s/nome-do-site/`.

---

## 1. Instalar o Docker Desktop (uma vez só)

1. Baixe em <https://www.docker.com/products/docker-desktop/>:
   - **Windows 10 ou 11:** "Download for Windows". Na instalação, deixe marcada a opção **Use WSL 2**.
     Se o instalador pedir para reiniciar o computador, reinicie.
   - **Mac:** escolha o chip do seu Mac: **Apple Silicon** (M1, M2, M3, M4) ou **Intel**
     (menu  → Sobre este Mac mostra qual é).
2. Abra o **Docker Desktop** e aceite os termos. Não precisa criar conta (pode clicar em "Skip"/"Pular").
3. Espere aparecer **Engine running** (no canto de baixo, à esquerda, com a bolinha verde).
   **O Docker Desktop precisa estar aberto sempre que for usar o sistema.**
4. Confira no terminal:
   - **Windows:** abra o **PowerShell** (menu Iniciar → digite "PowerShell").
   - **Mac:** abra o **Terminal** (Spotlight → digite "Terminal").

   Digite e aperte Enter:

   ```
   docker --version
   ```

   Tem que aparecer algo como `Docker version 27...`. Se aparecer "comando não encontrado", feche e
   abra o terminal de novo (ou reinicie o computador).

---

## 2. Baixar o projeto

Escolha um dos jeitos:

**Sem programas extras (mais fácil):**

1. No GitHub, abra o repositório `ury1996/jogo`.
2. No botão que mostra o nome da branch (normalmente `main`), escolha `claude/jolly-hawking-m0fur7`.
3. Clique no botão verde **Code** → **Download ZIP**.
4. Descompacte o ZIP numa pasta fácil de achar, por exemplo `Documentos\construtor-rankly`.

**Com Git:**

```
git clone https://github.com/ury1996/jogo.git construtor-rankly
cd construtor-rankly
git checkout claude/jolly-hawking-m0fur7
```

Para atualizar depois: baixe o ZIP de novo (ou `git pull`) e ligue com `docker compose up --build`
(veja a parte 6).

---

## 3. Criar o arquivo `.env` com as chaves

O arquivo `.env` guarda as duas chaves grátis que ligam os recursos externos:

| Chave | O que liga | Sem ela |
|---|---|---|
| `GEMINI_API_KEY` | a **IA** que escreve os textos e o SEO do site (Google Gemini) | a IA funciona em "modo de teste": aparecem textos marcados "[IA simulada]" |
| `PIXABAY_API_KEY` | o **banco de imagens** no editor (buscar e baixar fotos do Pixabay) | a busca de fotos fica desligada |

Dá para ligar o sistema sem nenhuma das duas e preencher depois.

### 3.1 Criar o arquivo

Na pasta do projeto existe o arquivo **`.env.exemplo`**. Faça uma cópia dele com o nome **`.env`**:

- **Windows:**
  1. Abra a pasta do projeto no Explorador de Arquivos. Se não aparecer o `.env.exemplo`, ative
     **Exibir → Mostrar → Itens ocultos** e **Extensões de nomes de arquivos**.
  2. Clique com o botão direito no `.env.exemplo` → **Copiar**, e depois **Colar** na mesma pasta.
  3. Renomeie a cópia para exatamente `.env` (ponto + env). O Windows pode avisar que o arquivo vai
     ficar sem extensão: confirme com **Sim**.
  4. Abra o `.env` com o **Bloco de Notas** (botão direito → Abrir com → Bloco de Notas).

  Atenção: o nome não pode virar `.env.txt`. Se for criar pelo Bloco de Notas, em **Salvar como**
  escolha **Tipo: Todos os arquivos** e digite `.env` no nome.
- **Mac:** no Terminal, dentro da pasta do projeto, rode `cp .env.exemplo .env` e depois
  `open -e .env` (abre no TextEdit). No Finder, arquivos que começam com ponto ficam ocultos:
  aperte **Cmd + Shift + .** para vê-los.

O `.env` aberto se parece com isto (as linhas com `#` são explicações):

```
GEMINI_API_KEY=
PIXABAY_API_KEY=
```

Cada chave vai **logo depois do sinal de igual**, sem espaços e sem aspas. Exemplo de como fica
preenchido (chaves inventadas):

```
GEMINI_API_KEY=AIzaSyB1a2b3c4d5e6f7g8h9i0jKlMnOpQrStUv
PIXABAY_API_KEY=12345678-abcdef0123456789abcdef012
```

O `.env` fica só no seu computador: ele não vai para o GitHub nem para dentro do sistema publicado.
Não mande as chaves para ninguém.

### 3.2 Chave da IA (Google Gemini, grátis)

1. Entre em <https://aistudio.google.com/apikey> com uma conta Google (Gmail serve).
2. Na primeira vez, aceite os termos do Google AI Studio.
3. Clique em **Create API key** (ou **Criar chave de API**). Se ele pedir um projeto, escolha
   **Create API key in new project** (Criar chave em um novo projeto).
4. Aparece uma chave que começa com `AIza…`. Clique em **Copy** (Copiar).
5. Cole no `.env`, na linha `GEMINI_API_KEY=` (ficando `GEMINI_API_KEY=AIza…`) e salve.

Sobre o plano gratuito: tem limite de pedidos por minuto e por dia (se estourar, o sistema tenta um
modelo reserva e, se ainda assim falhar, avisa para tentar de novo em alguns minutos), e o Google
pode usar o conteúdo enviado para melhorar os produtos dele. O sistema só envia dados do negócio
(nome, cidade, ramo e a descrição), nunca dados de clientes ou de contatos recebidos.

### 3.3 Chave do banco de imagens (Pixabay, grátis)

1. Crie uma conta em <https://pixabay.com> (botão **Join** / **Entrar**). Dá para usar a conta
   Google. Confirme o e-mail se o Pixabay pedir.
2. Com a conta aberta, entre em <https://pixabay.com/api/docs/>.
3. Desça até a seção **Search Images** → **Parameters**. Na primeira linha da tabela,
   **key (required)**, aparece **"Your API key:"** seguido de um código **em verde**, parecido com
   `12345678-abcdef0123456789abcdef012`. Essa é a sua chave. (Se aparecer só um aviso pedindo para
   entrar, a conta não está aberta: faça o login e recarregue a página.)
4. Copie o código inteiro e cole no `.env`, na linha `PIXABAY_API_KEY=`, e salve.

Sobre o Pixabay: as fotos podem ser usadas de graça, inclusive em sites comerciais, sem precisar dar
crédito (o sistema guarda o nome do autor mesmo assim). O limite é de 100 buscas por minuto por
chave, mais que suficiente. As buscas ficam guardadas por 24 horas, como o Pixabay pede, e a foto
escolhida é copiada para dentro do site (o site publicado não depende do Pixabay).

### 3.4 Conferir

Salve o `.env` e siga para a parte 4. Quando o sistema ligar, o quadro do terminal mostra se cada
recurso ficou ligado:

```
│ IA:     gemini                      ← ligada (sem chave: "simulado")
│ Fotos:  banco de imagens Pixabay ligado
```

Mudou o `.env` com o sistema ligado? Desligue (Ctrl+C no terminal) e ligue de novo
(`docker compose up`). O Docker só lê o `.env` ao ligar.

---

## 4. Ligar o sistema

1. Abra o Docker Desktop e espere o **Engine running**.
2. Abra o terminal **dentro da pasta do projeto**:
   - **Windows:** abra a pasta no Explorador, clique com o botão direito num espaço vazio →
     **Abrir no Terminal**. (Ou, no PowerShell: `cd "$HOME\Documents\construtor-rankly"`.)
   - **Mac:** no Terminal, digite `cd ` (com espaço), arraste a pasta do projeto para dentro da
     janela e aperte Enter.
3. Rode:

   ```
   docker compose up
   ```

4. Na primeira vez ele monta o sistema: leva de 3 a 10 minutos e mostra muitas linhas. Nas próximas,
   segundos. Está pronto quando aparecer o quadro:

   ```
   ┌──────────────────────────────────────────────────────────────
   │ Construtor Rankly · modo demonstração
   │
   │ Abra:   http://localhost:8080/editor/
   │ E-mail: demo@rankly.app
   │ Senha:  demo12345
   ...
   ```

5. Abra <http://localhost:8080/editor/> no navegador (Chrome, Edge, Firefox ou Safari).

**Deixe o terminal aberto** enquanto usa o sistema. Para desligar: **Ctrl+C** nesse terminal.
No Docker Desktop, em **Containers**, também dá para ver o sistema rodando, os registros (Logs) e
ligar/desligar pelo botão ▶ / ■.

---

## 5. Testar o fluxo completo

### 5.1 Entrar

Na tela de entrar, use **demo@rankly.app** e a senha **demo12345**. Aparece o painel
**Meus sites**, vazio.

### 5.2 Criar um site pelo assistente

1. Clique em **Novo site**.
2. **Tipo de negócio:** escolha **Clínicas**. As miniaturas são o site de verdade, em tamanho
   reduzido, já com fotos de exemplo.

   ![Passo 1 do assistente](img/como-testar/1-nicho.jpg)

3. **Modelo:** cada tipo de negócio tem 7 modelos: 4 exclusivos dele (em Clínicas: Acolher,
   Essência, Vital e Agenda) e os 3 gerais (Clássico, Moderno e Direto). Clique em **Ver prévia**
   para ver o site inteiro (dá para alternar Computador/Celular) e depois em **Usar este modelo**
   no **Clássico**. (Todos funcionam. O Clássico tem formulário de contato no fim da página, o que
   facilita o item 5.7.)

   ![Passo 2 do assistente](img/como-testar/2-modelos.jpg)

4. **Seus dados:** o primeiro bloco é **"Conte sobre o seu negócio — a IA escreve o site"**.
   Escreva com as suas palavras (ou clique num dos exemplos e troque os trechos entre colchetes),
   por exemplo: *"Clínica odontológica focada em implantes e ortodontia. Atendemos convênios e aos
   sábados. Público: famílias da região central."* Depois preencha nome (ex.: "Clínica Teste
   Sorriso"), cidade, estado e WhatsApp. A prévia à direita muda a cada tecla. Teste também trocar
   a cor.

   ![Passo 3 do assistente](img/como-testar/3-dados.jpg)

5. Clique em **Gerar meu site com IA**. A IA leva de 10 a 40 segundos escrevendo os textos. (Sem
   descrição, o botão vira **Gerar com textos de exemplo**.)

### 5.3 Editar

![Editor](img/como-testar/4-editor.jpg)

Coisas para experimentar:

- **Texto:** clique no título grande e escreva outra coisa. Enter confirma.
- **Desfazer/refazer:** setas no alto, ou Ctrl+Z / Ctrl+Shift+Z.
- **IA:** o botão **Escrever com IA** (no alto) reescreve o site inteiro; o ícone de brilho na
  barrinha de cada seção reescreve só aquela seção. **Desfazer** volta ao que estava. A IA não
  inventa números, depoimentos nem nomes da equipe.
- **Seções** (painel da esquerda): as setas ‹ › trocam o visual da seção sem perder o texto (o
  Destaque, por exemplo, tem 8 visuais); arraste para mudar a ordem; **Adicionar seção** no fim.
- **Lista de serviços:** passe o mouse na seção de serviços e use **Adicionar** / **Remover** item.
- **Estilo:** cor, acabamento (Clássico, Moderno, Direto, Elegante, Suave, Impacto) e fontes.
- **Ícone:** clique num ícone de serviço e escolha outro.
- **Computador / Celular** no alto, e **Visualizar** para ver sem as ferramentas (Esc volta).

O status embaixo do nome do site mostra **Salvando…** e **Salvo**: o salvamento é automático.

### 5.4 Trocar uma foto (banco de imagens)

1. Clique numa foto do site (por exemplo, a do destaque). Abre a janela da foto, com as opções
   **Remover foto**, **Buscar no banco de imagens** e **Trocar foto** (enviar do seu computador).
2. Clique em **Buscar no banco de imagens**. Abre a **Biblioteca de imagens**, já pesquisando um termo
   do seu tipo de negócio (ex.: "consultório odontológico"). Os botões embaixo da busca são
   sugestões; digite o que quiser e clique em **Buscar**.
3. Clique numa foto: ela é baixada para dentro do sistema e já entra no site. **Desfazer** volta.

![Biblioteca de imagens](img/como-testar/5-banco-imagens.jpg)

Para usar uma foto sua, clique em **Trocar foto**. Sem a chave do Pixabay (parte 3.3), a
biblioteca explica como ligar.

Sobre as **fotos de exemplo:** todo site novo já nasce com fotos em todos os espaços, para o modelo
ficar completo. Elas podem ficar no site, mas o ideal é trocar pelas do negócio, principalmente as da
equipe, que devem ser dos profissionais de verdade. A publicação avisa quantas ainda são de exemplo.

### 5.5 Publicar (a lista de verificação vai barrar, de propósito)

Clique em **Publicar**. A lista de verificação aparece e **bloqueia** a publicação por dois motivos,
como deve fazer:

![Lista de verificação](img/como-testar/6-checklist.jpg)

1. **Falta o registro profissional (CRO):** clique em **Ir até lá** e preencha o número, a UF e o
   responsável técnico.
2. **Depoimentos de exemplo:** na aba **Seções**, passe o mouse em **Depoimentos** e clique na
   lixeira (ou edite os depoimentos com textos reais).

Clique em **Publicar** de novo. Agora sobram só confirmações (números de exemplo, nota do Google):
marque as caixas **Confirmo…** e clique em **Publicar agora**.

### 5.6 Ver o site publicado

Na janela **Site publicado!**, clique em **Abrir site**. O endereço é algo como
`http://localhost:8080/s/clinicatestesorriso/`.

![Site publicado](img/como-testar/7-site.jpg)

Confira também:

- o rodapé com o CRO e o link **Política de privacidade** (página gerada automaticamente);
- o site no celular: no Chrome, F12 → ícone de celular (ou abra o endereço no celular, se ele estiver
  na mesma rede, trocando `localhost` pelo IP do computador);
- um endereço que não existe (ex.: `/s/clinicatestesorriso/teste`) mostra a página 404 do próprio site.

### 5.7 Enviar um contato e ver o lead

1. No site publicado, role até o formulário de contato.
2. **Espere uns 3 segundos** depois de abrir a página (envios mais rápidos são tratados como robô e
   descartados sem aviso) e envie nome e telefone.
3. Volte ao editor → **Meus sites** → no cartão do site, clique em **Contatos**.

![Contatos recebidos](img/como-testar/8-leads.jpg)

O e-mail de aviso ao dono não sai de verdade nos testes: ele vira um arquivo `.eml`. Para ver,
no Docker Desktop: **Containers** → clique no sistema → aba **Files** → pasta `/dados/var/emails`
(ou, no terminal, `docker compose exec rankly ls /dados/var/emails`). Pode levar até 1 minuto para
aparecer.

### 5.8 Outras coisas para testar

- **Voltar à publicação anterior:** publique uma segunda vez com uma mudança e use
  **Voltar à publicação anterior** na janela de publicação.
- **Histórico de versões:** ícone de relógio no alto do editor.
- **Trocar de modelo:** **Trocar modelo** no fim do painel de seções. Textos e fotos continuam.
- **Duas abas:** abra o mesmo site em duas abas, edite nas duas e veja o aviso de conflito.
- **Tema escuro** do editor: botão ao lado do seu nome.

---

## 6. No dia a dia

Todos os comandos são rodados no terminal, dentro da pasta do projeto.

| Quero… | Como |
|---|---|
| ligar | abrir o Docker Desktop e rodar `docker compose up` |
| desligar | **Ctrl+C** no terminal (ou ■ no Docker Desktop) |
| ligar sem ocupar o terminal | `docker compose up -d` (desligar: `docker compose down`) |
| ver o que está acontecendo | `docker compose logs -f`, ou a aba **Logs** no Docker Desktop |
| atualizar para uma versão nova do código | baixar o código novo e rodar `docker compose up --build` |
| mudar uma chave | editar o `.env`, desligar e ligar de novo |
| criar outro usuário | `docker compose exec rankly php app/cli/criar-usuario.php --nome="Fulano" --email=fulano@exemplo.com --papel=admin --senha=umasenhaforte` |
| apagar tudo (sites, fotos, contatos) e começar do zero | `docker compose down -v` |

Os sites, fotos e contatos ficam guardados entre uma vez e outra (num "volume" do Docker chamado
`rankly-dados`), até você rodar o `down -v`.

Para mostrar o sistema para alguém de fora do seu computador, veja
[`DEMONSTRACAO.md`](DEMONSTRACAO.md) (túnel gratuito ou servidor de testes).

---

## Problemas comuns

| Sintoma | Solução |
|---|---|
| `docker: command not found` / "não é reconhecido como comando" | O Docker Desktop não está instalado ou o terminal foi aberto antes da instalação: feche e abra o terminal (ou reinicie o computador). |
| `Cannot connect to the Docker daemon` / `error during connect` | O Docker Desktop está fechado: abra e espere o **Engine running**. |
| `no configuration file provided: not found` | O terminal não está na pasta do projeto (a que tem o `docker-compose.yml`). Use `cd` até ela. |
| `port is already allocated` / porta 8080 ocupada | Outro programa usa a 8080. No `.env`, acrescente `RANKLY_PORTA=8090` e abra <http://localhost:8090/editor/>. |
| O quadro mostra `IA: simulado` mesmo com a chave | O arquivo não se chama exatamente `.env` (veja se não ficou `.env.txt`), a linha tem espaço ou aspas, ou o sistema não foi religado depois de salvar. |
| A IA responde "chave inválida" | Copie a chave de novo em <https://aistudio.google.com/apikey> (ela começa com `AIza`). |
| "O banco de imagens ainda não está ligado" | Falta a `PIXABAY_API_KEY` no `.env` (parte 3.3), ou o sistema não foi religado. |
| "A chave do Pixabay é inválida" | Confira se copiou o código inteiro da seção **key (required)** em <https://pixabay.com/api/docs/>, com a conta aberta. |
| O formulário "envia" mas o contato não aparece | Espere 3 s depois de abrir a página antes de enviar; e não mais de 5 envios por hora do mesmo computador. |
| Esqueci a senha do usuário de teste | Ela é `demo12345` (é a do primeiro uso). Se mudou, crie outro usuário com o comando da parte 6. |

---

## Para quem desenvolve: testes automáticos e rodar sem Docker

Os testes automáticos rodam fora do Docker e precisam de **PHP 8.2+** (com gd, mbstring, sqlite,
curl), **Composer** e **Node.js 22**:

```
composer install
npm install
npx playwright install chromium

npm test                                        # testes JS
vendor/bin/phpunit -c tests/php/phpunit.xml      # testes PHP
npm run paridade                                 # editor (JS) e servidor (PHP) geram o mesmo HTML
RANKLY_E2E_BANCO=sqlite npm run e2e              # navegador de verdade: fluxo completo e os 28 sites
```

Ou tudo de uma vez: `bin/testar.sh`. O teste ponta a ponta salva capturas dos 28 sites
(4 nichos × 7 modelos, computador e celular) em `var/e2e/capturas/`.

Com PHP instalado, dá para rodar o mesmo modo demonstração sem Docker: `composer install` e
`bin/demo.sh` (as chaves vão como variáveis de ambiente:
`GEMINI_API_KEY=… PIXABAY_API_KEY=… bin/demo.sh`). A instalação de produção (Hostinger, MySQL,
domínio dos sites) está no `README.md`.
