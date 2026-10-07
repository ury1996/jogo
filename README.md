# Construtor Rankly

Construtor de sites por nicho da Sites Rankly. A pessoa escolhe o nicho (advocacia, finanças,
empresas, clínicas), um modelo (Clássico, Moderno ou Direto) e preenche os dados do negócio. O
sistema monta um site de uma página com textos do nicho já escritos, paleta calculada a partir de
uma cor, fontes e ícones escolhidos automaticamente. Depois disso, a pessoa edita os textos e as fotos
direto na página e publica.

O site publicado é uma pasta de HTML estático (com CSS, fontes e fotos WebP) servida em
`https://{slug}.{seu-domínio}`. Ele não depende do banco nem da API para abrir. O formulário de
contato grava os leads no banco e avisa o dono por e-mail.

Três partes:

- **Editor** (`public_html/editor/`): aplicação em JavaScript puro (módulos ES, sem etapa de build).
  Mostra a prévia com os mesmos templates Mustache da publicação.
- **API** (`public_html/api/` → `app/`): PHP 8.2+, sem framework. Contas, sites, mídia, leads,
  publicação.
- **Gerador** (`app/Gerador/`): transforma o documento do site em HTML estático e grava a pasta com
  troca atômica (link simbólico).

A lógica que monta o HTML existe em JS (`public_html/editor/js/compartilhado/`) e em PHP
(`app/Preparo/`). Um teste de paridade confere que as duas saídas são iguais byte a byte.

Documentos:

- [`docs/ARQUITETURA.md`](docs/ARQUITETURA.md): contrato técnico (formatos, regras, rotas, tabelas).
  Em caso de dúvida, vale ele.
- [`docs/especificacao-v0.2.md`](docs/especificacao-v0.2.md): especificação atualizada, escrita para
  quem não vai ler o código. Diz o que mudou em relação à v0.1 (PDF) e o que ficou para depois.

---

## Requisitos

**Servidor (produção e desenvolvimento)**

- PHP **8.2 ou mais novo**, com as extensões:
  - `gd` **com suporte a WebP** (gera as variantes das fotos; confira com
    `php -r 'var_dump(function_exists("imagewebp"));'`);
  - `exif` (corrige a orientação de fotos de celular; sem ela as fotos ainda funcionam, mas podem
    ficar deitadas);
  - `mbstring`, `fileinfo`, `json`, `pdo` e `pdo_mysql`;
  - `openssl` (envio de e-mail por SMTP com SSL/TLS).
- MySQL 8 ou MariaDB 10.6+ (`utf8mb4`).
- Apache 2.4 ou LiteSpeed com `mod_rewrite` e `.htaccess` liberado (é o caso da Hostinger).
  Também usa `mod_headers`, `mod_deflate` e `mod_mime` quando existem.
- Cron (para a fila de tarefas: e-mails, republicação, limpeza diária).

**Só para desenvolvimento e testes**

- Composer 2.
- Node.js 22 (testes JS, teste de paridade, Playwright e os scripts de `ferramentas/`).
  O site publicado e o editor **não** precisam de Node em produção.
- `pdo_sqlite` (o PHPUnit roda em SQLite por padrão).
- Chromium do Playwright para os testes ponta a ponta (`npx playwright install chromium`).

---

## Instalação local, passo a passo

1. **Dependências**

   ```bash
   composer install
   npm install
   ```

2. **Banco** (MariaDB/MySQL rodando na máquina):

   ```sql
   CREATE DATABASE rankly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'rankly'@'localhost' IDENTIFIED BY 'rankly';
   GRANT ALL PRIVILEGES ON rankly.* TO 'rankly'@'localhost';
   -- opcional, para os testes ponta a ponta e para `bin/testar.sh --mysql`:
   CREATE DATABASE rankly_teste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON rankly_teste.* TO 'rankly'@'localhost';
   ```

   Se o MariaDB local tiver caído: `(mysqld_safe --user=mysql >/dev/null 2>&1 &)`.

3. **Configuração**

   ```bash
   cp config/config.exemplo.php config/config.php
   ```

   O exemplo já vem pronto para desenvolvimento: banco `rankly`/`rankly`, editor em
   `http://localhost:8080`, sites em `localhost:8081`, e-mails gravados em arquivo
   (`var/emails/*.eml`) em vez de enviados. `config/config.php` fica fora do git. Para usar outro
   arquivo, aponte a variável `RANKLY_CONFIG`.

4. **Tabelas**

   ```bash
   php app/cli/migrar.php            # aplica as migrações pendentes
   php app/cli/migrar.php --listar   # só mostra o que falta
   ```

5. **Primeiro usuário**

   ```bash
   php app/cli/criar-usuario.php --nome="Seu Nome" --email=voce@exemplo.com --papel=admin
   ```

   A senha é pedida no terminal (mínimo de 8 caracteres). Papéis: `admin`, `equipe`, `cliente`.
   `--atualizar` troca senha, nome ou papel de um usuário que já existe.

6. **Subir o ambiente**

   ```bash
   bin/dev.sh              # ou bin/dev.sh --sem-cron
   ```

   O script copia o `config.php` se ele ainda não existir, roda as migrações, sobe dois servidores
   embutidos do PHP e roda o cron a cada 60 segundos (log em `var/logs/cron-dev.log`).
   Portas e host mudam com `PORTA_EDITOR`, `PORTA_SITES` e `HOST_DEV`.

7. **Abrir**

   - Editor: <http://localhost:8080/editor/>
   - Sites publicados: `http://{slug}.localhost:8081/` (Chrome e Firefox resolvem `*.localhost`
     sozinhos; no curl use `-H "Host: {slug}.localhost:8081"`).
   - E-mails de desenvolvimento (lead, redefinição de senha): arquivos `.eml` em `var/emails/`.

---

## Testes

Tudo de uma vez:

```bash
bin/testar.sh                # JS + PHPUnit (SQLite) + paridade + ponta a ponta
bin/testar.sh --sem-e2e      # sem o Playwright
bin/testar.sh --mysql        # PHPUnit também no MariaDB (banco rankly_teste)
bin/testar.sh --apache       # testa o sites/.htaccess num Apache de verdade (apache2 + mod_php)
```

Cada suíte separada:

| comando | o que cobre |
|---|---|
| `node --test 'tests/js/**/*.test.mjs'` | Lógica compartilhada (paleta, tons, ícones, textos, listas, documento, preparo), estado do editor (desfazer, salvamento, conflito), operações da tela, lint dos templates e do CSS da biblioteca, conteúdo dos nichos. As aspas importam: o Node 22 expande o glob sozinho. |
| `vendor/bin/phpunit -c tests/php/phpunit.xml` | PHP: Preparo, bibliotecas (banco, imagens, SVG, e-mail, tarefas, leads), API com SQLite e servidor embutido, gerador e publicador em pasta temporária. Suítes separadas com `--testsuite Preparo`, `Lib`, `Api` ou `Gerador`. |
| `node tests/paridade/comparar.mjs` (ou `npm run paridade`) | Renderiza todos os nichos × modelos × especialidades, com variações de acabamento, fonte, cor, listas, dados e mídia, em JS e em PHP, e compara o HTML byte a byte. |
| `npx playwright test --config tests/e2e/playwright.config.mjs` (ou `npm run e2e`) | Navegador de verdade: entrar → assistente → editar → publicar → enviar o formulário → lead no painel; site sem erros nem violações de CSP; formulário sem JavaScript; páginas extras; consentimento com GTM; voltar à publicação anterior; os 12 modelos em 1280 e 390 px (peso, rolagem horizontal); casos difíceis do editor (conflito 409, foto enviada ao sair, CEP atrasado, teclado). |

O ponta a ponta sobe um ambiente isolado: banco `rankly_teste` no MariaDB local (ou SQLite com
`RANKLY_E2E_BANCO=sqlite`, ou automaticamente se o MariaDB não responder), pasta temporária e dois
`php -S` em portas livres. `RANKLY_E2E_MANTER=1` guarda a pasta no fim para inspeção.

Observação: `npm test` usa `node --test tests/js/`, que o Node 22 não aceita (pasta em vez de
arquivo). Use o comando com glob acima ou `bin/testar.sh`.

---

## Estrutura de pastas (resumo)

```
app/                 PHP (namespace Rankly\), fora da web
  Api/               controladores da API (Auth, Sites, Midia, Biblioteca, Leads, Publicacao, Versoes)
  Http/              roteador, requisição, resposta
  Lib/               banco, sessão, CSRF, limites, imagens, SVG, e-mail, tarefas, leads, usuários
  Preparo/           porte PHP da lógica compartilhada (paridade com o JS)
  Gerador/           validação, CSS, SEO, páginas extras, script do site, publicação atômica
  cli/               migrar.php, criar-usuario.php, cron.php, republicar-todos.php
  sql/mysql, sql/sqlite   migrações
biblioteca/          seções (manifesto + templates + CSS), modelos, nichos, ícones, fontes, base.css
config/              config.exemplo.php (copiar para config.php)
public_html/         raiz web do editor e da API (.htaccess, api/index.php, editor/)
sites/               raiz web dos sites publicados (.htaccess, _lead.php, _roteador.php)
  .releases/{slug}/  publicações guardadas (geradas)
  {slug} →           link simbólico para a publicação no ar (gerado)
media/               fotos enviadas (fora da web, "Require all denied")
var/                 logs, sessões, cache, travas, e-mails de dev (gerado, gravável)
ferramentas/         scripts Node que geram icones.json e copiam as fontes (só desenvolvimento)
tests/               js/, php/, paridade/, e2e/, fixtures/
bin/                 dev.sh, testar.sh
docs/                ARQUITETURA.md, especificacao-v0.2.md
```

---

## Implantação na Hostinger (Cloud Professional)

O plano tem PHP, MySQL, cron, SSH e servidor LiteSpeed (lê os `.htaccess`). Os passos abaixo
seguem o desenho do sistema; os itens marcados com **(confirmar)** dependem do painel da Hostinger e
ainda não foram testados lá.

### 1. Domínios

Exemplo com um domínio neutro `meusites.com.br` (troque pelo escolhido):

- `editor.meusites.com.br` → editor e API (raiz: `public_html/` do projeto);
- `*.meusites.com.br` → sites publicados (raiz: `sites/` do projeto).

Um subdomínio explícito (`editor`) tem prioridade sobre o curinga, então os dois convivem.

### 2. Pastas: só o necessário dentro da web

Envie o projeto (via git ou SFTP) de forma que **apenas** `public_html/` e `sites/` sejam raízes
web. Um jeito simples no hPanel é usar a pasta do domínio do editor como raiz do projeto:

```
/home/uXXXX/domains/editor.meusites.com.br/
  app/  biblioteca/  config/  media/  var/  vendor/  sites/   ← fora da web
  public_html/                                                ← raiz web do editor
```

- Rode `composer install --no-dev --optimize-autoloader` no servidor (ou envie `vendor/` pronto).
  `node_modules/` não vai para produção.
- `media/`, `sites/` e `var/` precisam ser graváveis pelo PHP. Na Hostinger o PHP roda com o usuário
  da conta, então pastas `755` e arquivos `644` bastam. Deixe `config/config.php` com `600`.

### 3. Subdomínio curinga apontando para `sites/`

- No hPanel, crie o subdomínio `*` e aponte a pasta dele para `sites/` do projeto **(confirmar** se
  o painel aceita uma pasta fora de `public_html`).
- Se o painel só aceitar a pasta que ele mesmo cria: use essa pasta como `dir_sites` (caminho
  absoluto no `config.php`), copie para ela `sites/.htaccess`, `sites/_lead.php` e
  `sites/_roteador.php`, e informe a raiz do projeto na variável `RANKLY_RAIZ` (por exemplo
  `SetEnv RANKLY_RAIZ /home/uXXXX/domains/editor.meusites.com.br` no `.htaccess` dessa pasta).
- Edite `sites/.htaccess`: troque `SEU-DOMINIO\.com\.br` pelo domínio dos sites com os pontos
  escapados (ex.: `meusites\.com\.br`). Aparece em duas regras.
- Os sites usam links simbólicos (`Options +SymLinksIfOwnerMatch`). Depois da primeira publicação,
  abra o site e confira que carrega **(confirmar** no LiteSpeed da Hostinger).
- DNS: registro `A` (ou `CNAME`) de `*` apontando para o servidor.

### 4. SSL curinga

Duas saídas (a escolha está em aberto, ver a especificação):

- **SSL da Hostinger** para `*.meusites.com.br`, se o plano emitir certificado curinga
  **(confirmar)**. Um curinga do Let's Encrypt exige validação por DNS.
- **Cloudflare (plano gratuito) na frente do domínio**: DNS curinga com proxy e certificado da
  Cloudflare, modo SSL "Full (strict)" com um certificado de origem instalado na Hostinger. Nesse
  caso, ponha `'confiar_cloudflare' => true` no `config.php` para o anti-spam usar o IP real do
  visitante (`CF-Connecting-IP`). Não ligue essa opção sem a Cloudflare na frente: qualquer um
  poderia forjar o cabeçalho.

### 5. Banco e configuração

1. Crie o banco e o usuário MySQL no hPanel.
2. `cp config/config.exemplo.php config/config.php` e ajuste:
   - `'ambiente' => 'prod'`;
   - `url_editor` (`https://editor.meusites.com.br`), `dominio_sites` (`meusites.com.br`),
     `protocolo_sites` (`https`);
   - `db` (DSN, usuário, senha);
   - `segredo_app` e `segredo_ip`: gere cada um com
     `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Em produção o sistema recusa o valor de
     exemplo e segredos com menos de 16 caracteres;
   - `smtp` e `'email_modo' => 'smtp'`.
3. `php app/cli/migrar.php` e `php app/cli/criar-usuario.php …` pelo SSH.

### 6. Cron

No hPanel (Avançado → Cron Jobs), a cada minuto:

```
* * * * * /caminho/do/php82 /home/uXXXX/domains/editor.meusites.com.br/app/cli/cron.php >> /home/uXXXX/domains/editor.meusites.com.br/var/logs/cron.log 2>&1
```

Use o binário do PHP 8.2+ indicado pelo painel (o `php` padrão do SSH pode ser outra versão). O cron
envia os e-mails que ficaram na fila, executa republicações e agenda a limpeza diária (06:00 UTC:
leads além da retenção, tokens vencidos, sessões, tarefas antigas, fotos órfãs). O e-mail de um
lead normalmente sai logo depois da resposta ao visitante; o cron é a garantia caso falhe. Se o
painel não aceitar "a cada minuto", a cada 5 minutos também funciona.

### 7. E-mail (SMTP, SPF, DKIM, DMARC)

- Crie a caixa remetente (ex.: `nao-responda@meusites.com.br`) no hPanel e use
  `smtp.hostinger.com`, porta 465 com `'seguranca' => 'ssl'` (ou 587 com `'tls'`).
- No DNS do domínio remetente: **SPF** incluindo a Hostinger, **DKIM** com a chave que o hPanel
  mostra e **DMARC** (comece com `p=none` e um endereço para relatórios; endureça depois). Sem isso
  os avisos de lead tendem a cair no spam.
- Opcional: `email_leads_copia` recebe uma cópia de todo lead (ex.: atendimento da agência).
- Teste: envie um formulário de um site publicado e confira a caixa do dono.

### 8. Backup

- **Banco, diário**: `mysqldump --single-transaction` por cron, guardando alguns dias.
- **`media/` e `config/config.php`, semanal** (ou diário, se entram muitas fotos).
- **`sites/`**: pode ser regenerada a partir do banco e da mídia (`php app/cli/republicar-todos.php`),
  mas copiar também é barato.
- Cópia **fora do servidor** (ex.: Google Drive via `rclone`). O backup automático da Hostinger
  ajuda, mas não substitui uma cópia sua.
- Teste a restauração pelo menos uma vez.

### Atualizar a biblioteca ou o código

Uma mudança em template, CSS ou texto padrão só chega aos sites já publicados quando eles são
republicados:

```bash
php app/cli/republicar-todos.php                       # enfileira (o cron executa)
php app/cli/republicar-todos.php --agora               # republica agora, com relatório
php app/cli/republicar-todos.php --agora --site=slug   # um site só
```

Atenção: republicar usa o **documento salvo** do site, que pode ter alterações ainda não
publicadas. Sites com pendências (ex.: WhatsApp inválido) ficam com a versão que já estava no ar.

---

## Checklist de produção

- [ ] `'ambiente' => 'prod'` (esconde detalhes de erro, cookie de sessão `Secure`, erros só no log).
- [ ] `segredo_app` e `segredo_ip` novos, longos e aleatórios; nunca os do exemplo.
- [ ] `config/config.php` fora do git, com permissão `600`.
- [ ] HTTPS no editor e em todos os subdomínios; `protocolo_sites` = `https`.
- [ ] `sites/.htaccess` com o domínio certo no lugar de `SEU-DOMINIO`.
- [ ] Raízes web só em `public_html/` e `sites/`. Conferir que `https://editor…/app/`,
      `/config/`, `/vendor/` e `/media/` não abrem.
- [ ] `media/`, `sites/`, `var/` graváveis; o resto, só leitura para o PHP se possível.
- [ ] Limites de upload do PHP (16 MB por arquivo, 20 MB por requisição) aplicados: o
      `public_html/.htaccess` define para mod_php e LiteSpeed; em PHP-FPM use `.user.ini` ou o
      painel.
- [ ] `email_modo` = `smtp`, SMTP testado, SPF/DKIM/DMARC publicados.
- [ ] Cron ativo (ver `var/logs/cron.log`).
- [ ] Backup do banco e da mídia rodando, com cópia fora do servidor.
- [ ] `confiar_cloudflare` = `true` **só** com a Cloudflare na frente.
- [ ] Um usuário `admin` criado; sem usuários de teste.
- [ ] Publicar um site de teste, abrir, enviar o formulário e receber o e-mail.

---

## Limitações conhecidas

- **Domínio próprio de cliente**: o roteador (`sites/_roteador.php`) e a tabela `dominios` já
  existem, mas não há tela nem rota da API para cadastrar e verificar domínios. Fica para a fase 2.
- **Acesso do cliente**: o papel `cliente` e a tabela `site_acessos` existem (o cliente só vê os sites
  ligados a ele e não cria, duplica nem arquiva sites), mas não há tela para dar acesso; hoje isso
  é feito direto no banco.
- **Peso do site**: HTML + CSS ficam em torno de 99 KB sem compressão, acima da meta de 80 KB do
  PDF. Com gzip (que o `sites/.htaccess` liga) ficam em torno de 22 KB transferidos. O teste ponta a
  ponta mede os bytes transferidos.
- **Consentimento**: a faixa é simples (Aceitar / Recusar) e os scripts de terceiros só carregam
  depois do aceite. Não há o "modo avançado" do Google (pings sem cookies antes do aceite) nem
  escolha por categoria.
- **Republicar** usa o documento salvo, não a última versão publicada (ver acima).
- **Usuário do banco sem permissão de alterar estrutura** (pedido do PDF): o hPanel costuma dar
  todas as permissões ao usuário do banco. Se não for possível separar, fica como risco aceito.
- **Busca de CEP** no editor usa o ViaCEP (serviço externo). Se ele falhar, o endereço é digitado à
  mão. O site publicado não chama terceiros, exceto GTM/GA4/Pixel quando configurados e o mapa do
  Google quando o visitante clica nele.
- **Ícones de odontologia** (Healthicons) não têm versão duotone: no acabamento Moderno aparecem em
  traço.
- **Segundo fator de login**: a coluna `totp_segredo` existe, mas o login em duas etapas não foi
  implementado.
- **Sessões em arquivo** (`var/sessoes/`): servem para um servidor só. Com mais de um servidor seria
  preciso guardar as sessões em lugar compartilhado.
- **PageSpeed 90+ no celular** é meta do PDF, mas não há teste automático de PageSpeed; os testes
  medem peso, ausência de rolagem horizontal e erros de console.
- O `.htaccess` dos sites foi testado em Apache (`bin/testar.sh --apache`); o comportamento no
  LiteSpeed da Hostinger ainda precisa ser conferido na primeira implantação.
