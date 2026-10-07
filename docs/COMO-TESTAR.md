# Como testar o Construtor Rankly no seu computador

Roteiro para ver o sistema funcionando de ponta a ponta: criar um site pelo assistente, editar,
publicar, abrir o site e receber um contato. Leva uns 15 minutos na primeira vez.

Este roteiro usa **SQLite** (um arquivo no lugar do banco), então não precisa instalar MySQL.
Em produção, use MySQL/MariaDB (veja o README).

---

## 1. Instalar o que precisa (uma vez só)

Você precisa de **PHP 8.2+** (com as extensões gd, mbstring e sqlite), **Composer** e **Git**.
Node.js só é necessário para os testes automáticos (parte 5).

**macOS** (com [Homebrew](https://brew.sh)):

```bash
brew install php composer git node
```

**Ubuntu / Debian**:

```bash
sudo apt update
sudo apt install -y git unzip php-cli php-gd php-mbstring php-sqlite3 php-xml php-curl php-zip composer
```

**Windows**: use o **WSL** (Ubuntu dentro do Windows). No PowerShell como administrador rode
`wsl --install`, reinicie, abra o "Ubuntu" e siga os comandos de Ubuntu acima. O sistema usa links
simbólicos e scripts bash, que não funcionam bem no Windows puro.

Confira se o PHP gera WebP (deve aparecer `bool(true)`):

```bash
php -r 'var_dump(function_exists("imagewebp"));'
```

---

## 2. Baixar o projeto e configurar

```bash
git clone https://github.com/ury1996/jogo.git construtor-rankly
cd construtor-rankly
git checkout claude/jolly-hawking-m0fur7
composer install
cp config/config.exemplo.php config/config.php
mkdir -p var
```

Abra `config/config.php` num editor de texto e troque as duas linhas do banco:

```php
'driver' => 'mysql',
'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=rankly;charset=utf8mb4',
```

por:

```php
'driver' => 'sqlite',
'dsn' => 'sqlite:' . __DIR__ . '/../var/rankly.sqlite',
```

Crie as tabelas e o seu usuário (a senha precisa ter 8 caracteres ou mais):

```bash
php app/cli/migrar.php
php app/cli/criar-usuario.php --nome="Marcos" --email=marcos@teste.com --papel=admin --senha=senha12345
```

---

## 3. Ligar o sistema

```bash
bin/dev.sh
```

Deixe esse terminal aberto (Ctrl+C desliga). Ele sobe duas coisas:

- o **editor** em <http://localhost:8080/editor/>
- os **sites publicados** em `http://{nome-do-site}.localhost:8081/`

Use **Chrome ou Firefox** (o Safari não abre endereços `*.localhost`).

---

## 4. Testar o fluxo completo

### 4.1 Entrar

Abra <http://localhost:8080/editor/> e entre com o e-mail e a senha que você criou.
Aparece o painel **Meus sites**, vazio.

### 4.2 Criar um site pelo assistente

1. Clique em **Novo site**.
2. **Tipo de negócio**: escolha **Clínicas**. As miniaturas são o site de verdade, em tamanho
   reduzido.

   ![Passo 1 do assistente](img/como-testar/1-nicho.jpg)

3. **Modelo**: clique em **Ver prévia** em qualquer um para ver o site inteiro (dá para alternar
   Computador/Celular). Depois clique em **Usar este modelo** no **Clássico**.
   *(O Moderno e o Direto também funcionam; o Clássico tem formulário de contato no fim da página,
   o que facilita o teste do item 4.6.)*
4. **Dados**: preencha nome (ex.: "Clínica Teste Sorriso"), cidade, estado e WhatsApp.
   Repare que a prévia à direita muda a cada tecla. Teste também trocar a cor.

   ![Passo 3 do assistente](img/como-testar/2-dados.jpg)

5. Clique em **Gerar meu site**.

### 4.3 Editar

![Editor](img/como-testar/3-editor.jpg)

Coisas para experimentar:

- **Texto**: clique no título grande e escreva outra coisa. Enter confirma. Os textos em caixa alta
  (botões, rótulos) continuam com a caixa original guardada.
- **Desfazer/refazer**: setas no alto, ou Ctrl+Z / Ctrl+Shift+Z.
- **Foto**: clique num espaço de foto e envie uma imagem do seu computador.
- **Ícone**: clique num ícone de serviço e escolha outro. Ou edite o título do serviço (ex.:
  "Implantes") e veja o ícone mudar sozinho.
- **Seções** (painel da esquerda): as setas ‹ › trocam o visual da seção sem perder o texto; arraste
  para mudar a ordem; **Adicionar seção** no fim da lista.
- **Lista de serviços**: passe o mouse na seção de serviços e use **Adicionar** / **Remover** item.
- **Estilo**: troque cor, acabamento (Clássico, Moderno, Direto) e fontes.
- **Computador / Celular** no alto, e **Visualizar** para ver sem as ferramentas (Esc volta).

O status embaixo do nome do site mostra **Salvando…** e **Salvo**: o salvamento é automático.

### 4.4 Publicar (a lista de verificação vai barrar, de propósito)

Clique em **Publicar**. A lista de verificação aparece e **bloqueia** a publicação por dois motivos,
como deve fazer:

![Lista de verificação](img/como-testar/4-checklist.jpg)

1. **Falta o registro profissional (CRO)**: clique em **Ir até lá**, preencha o número, a UF e o
   responsável técnico.
2. **Depoimentos de exemplo**: na aba **Seções**, passe o mouse em **Depoimentos** e clique na
   lixeira (ou edite os depoimentos com textos reais).

Clique em **Publicar** de novo. Agora sobram só confirmações (números de exemplo, nota do Google):
marque as caixas **Confirmo…** e clique em **Publicar agora**.

### 4.5 Ver o site publicado

Na janela **Site publicado!**, clique em **Abrir site**. O endereço é algo como
`http://clinicatestesorriso.localhost:8081`.

![Site publicado](img/como-testar/5-site.jpg)

Confira também:

- o rodapé com o CRO e o link **Política de privacidade** (página gerada automaticamente);
- o site no celular: no Chrome, F12 → ícone de celular;
- um endereço que não existe (ex.: `/teste`) mostra a página 404 do próprio site.

### 4.6 Enviar um contato e ver o lead

1. No site publicado, role até o formulário de contato.
2. **Espere uns 3 segundos** depois de abrir a página (envios mais rápidos que isso são tratados como
   robô e descartados sem aviso) e envie nome e telefone.
3. Volte ao editor → **Meus sites** → no cartão do site, clique em **Contatos**.

![Contatos recebidos](img/como-testar/6-leads.jpg)

O e-mail de aviso ao dono não é enviado de verdade no computador: ele vira um arquivo `.eml` em
`var/emails/` (abre no Outlook, Thunderbird ou num editor de texto). Pode levar até 1 minuto,
porque é o cron do `bin/dev.sh` que envia.

### 4.7 Outras coisas para testar

- **Voltar à publicação anterior**: publique uma segunda vez com uma mudança e use
  **Voltar à publicação anterior** na janela de publicação.
- **Histórico de versões**: ícone de relógio no alto do editor.
- **Duas abas**: abra o mesmo site em duas abas, edite nas duas e veja o aviso de conflito.
- **Sem internet**: desligue a rede, edite, religue; a alteração é enviada sozinha.
- **Esqueci a senha**: na tela de entrar; o link chega como arquivo em `var/emails/`.
- **Tema escuro** do editor: botão ao lado do seu nome.

---

## 5. Testes automáticos (opcional)

Precisa de Node.js 22.

```bash
composer install            # inclui o PHPUnit
npm install
npx playwright install chromium

npm test                                        # testes JS (~190)
vendor/bin/phpunit -c tests/php/phpunit.xml      # testes PHP (~270)
npm run paridade                                 # editor (JS) e servidor (PHP) geram o mesmo HTML
RANKLY_E2E_BANCO=sqlite npm run e2e              # navegador de verdade: fluxo completo e os 12 sites
```

Ou tudo de uma vez: `bin/testar.sh`. O teste ponta a ponta salva capturas dos 12 sites
(4 nichos × 3 modelos, computador e celular) em `var/e2e/capturas/`.

---

## Problemas comuns

| Sintoma | Solução |
|---|---|
| `Dependências ausentes: rode "composer install"` | Rode `composer install` na pasta do projeto. |
| `Não foi possível migrar o banco` | Confira as duas linhas do banco em `config/config.php` e se a pasta `var/` existe. |
| Porta 8080 ou 8081 ocupada | `PORTA_EDITOR=8090 bin/dev.sh` (para os sites, mude também `dominio_sites` no `config.php`). |
| O site publicado não abre | Use Chrome ou Firefox; confira se o `bin/dev.sh` continua rodando. |
| O formulário "envia" mas o contato não aparece | Espere 3 s depois de abrir a página antes de enviar; e não mais de 5 envios por hora do mesmo computador. |
| Fotos não geram | `php -r 'var_dump(function_exists("imagewebp"));'` precisa dar `true` (instale `php-gd`). |
