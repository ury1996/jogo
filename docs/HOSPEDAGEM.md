# Colocar o sistema na sua hospedagem (Hostinger, sem VPS)

Funciona numa hospedagem comum da Hostinger (planos Premium, Business ou Cloud), **sem SSH e sem
Docker**: você envia dois arquivos pelo Gerenciador de Arquivos, abre uma página e preenche um
formulário. Leva uns 15 minutos.

No fim você terá:

- o editor em `https://editor.seudominio.com.br/editor/`;
- os sites publicados em `https://editor.seudominio.com.br/s/nome-do-site/`;
- os contatos dos formulários no painel do sistema (e, se configurar, por e-mail).

> Seu site principal (`seudominio.com.br`) **não é afetado**: o sistema fica num subdomínio
> separado. O instalador se recusa a instalar numa pasta que já tem outro site.

---

## 1. Criar o subdomínio

No hPanel: **Sites → (seu site) → Painel → Domínios → Subdomínios**.

1. Crie o subdomínio `editor` (ou outro nome que preferir, ex.: `sistema`).
2. Deixe a pasta que o painel sugerir (algo como `public_html/editor`). Anote.

Em seguida, confira o **SSL** do subdomínio em **Segurança → SSL**: a Hostinger costuma emitir
sozinho em alguns minutos. Abra `https://editor.seudominio.com.br` e veja se aparece o cadeado.

## 2. Versão do PHP

No hPanel: **Avançado → Configuração do PHP** → escolha **8.3** (ou 8.2) e salve.

A versão vale para o site inteiro, inclusive o principal. Sites e WordPress atualizados funcionam
normalmente no 8.2/8.3.

## 3. Banco de dados

No hPanel: **Bancos de dados → Gerenciamento**:

1. Crie um banco novo (ex.: `rankly`). O painel acrescenta um prefixo: o nome completo fica como
   `u123456789_rankly`, e o usuário também.
2. Defina uma senha e **anote os três dados**: nome do banco, usuário e senha.

## 4. Enviar os dois arquivos

Você precisa de:

- **`rankly-hospedagem.zip`**: o sistema (uns 12 MB);
- **`instalar.php`**: o instalador.

**Onde baixar:** em <https://github.com/ury1996/jogo/releases/tag/hospedagem> (logado no GitHub),
na lista **Assets**. O GitHub atualiza essa página sozinho a cada versão que passa nos testes.

Outra opção é gerar no seu computador, com o Docker Desktop. Na pasta do projeto, rode
`docker compose run --rm --no-deps rankly php bin/empacotar-hospedagem.php`. Os dois arquivos
aparecem na pasta `dist/`.

No hPanel: **Arquivos → Gerenciador de Arquivos**:

1. Entre na pasta do subdomínio (a da parte 1, ex.: `public_html/editor`).
2. Se houver um arquivo `default.php` lá, pode deixar: o instalador apaga.
3. Clique em **Enviar** (Upload) e envie os dois arquivos. **Não descompacte o ZIP**: o instalador
   faz isso.

## 5. Instalar

Abra **`https://editor.seudominio.com.br/instalar.php`** no navegador.

1. **Servidor:** tudo precisa estar com ✓. Se algo aparecer com ✗, a própria tela diz como resolver
   (quase sempre é ativar uma extensão em **Configuração do PHP → Extensões**). Resolva e
   recarregue a página.
2. **Banco de dados:** cole o nome, o usuário e a senha da parte 3. O servidor é `localhost`.
3. **Seu acesso:** seu nome, e-mail e uma senha (8 ou mais caracteres). É com eles que você entra no
   sistema.
4. **Chaves (opcional):** a da IA (Gemini) e a do Pixabay, as mesmas do seu `.env`. Dá para pôr
   depois.
5. **E-mail de aviso (opcional):** para o dono do site receber um e-mail a cada contato. Crie antes
   uma caixa em **Emails** (ex.: `nao-responda@seudominio.com.br`) e informe o endereço e a senha
   dela.
6. **Atualização automática (opcional, recomendado):** o token do GitHub só de leitura (parte 7).
7. Clique em **Instalar**.

Na tela de "Pronto!", clique em **Abrir o editor** e entre com o seu e-mail e senha.

O instalador:

- põe os arquivos do sistema na pasta `rankly-sistema`, **ao lado** da `public_html`, onde a internet
  não alcança;
- copia só a parte web (editor e API) para a pasta do subdomínio;
- cria as tabelas e o seu usuário;
- **apaga o ZIP e a si mesmo** no final.

## 6. Tarefa agendada (recomendado)

A tela final mostra o caminho do arquivo. No hPanel: **Avançado → Cron Jobs**:

1. **Jeito 1 — tipo "PHP"** (recomendado, usa a mesma versão do PHP do site): no campo do arquivo,
   cole o caminho mostrado, parecido com
   `/home/u123456789/domains/seudominio.com.br/rankly-sistema/app/cli/cron.php`.
2. **Jeito 2 — tipo "Personalizado"** (Custom), se o jeito 1 não aceitar: cole
   `/usr/bin/php /home/u123456789/domains/seudominio.com.br/rankly-sistema/app/cli/cron.php`.
3. Frequência **a cada minuto** (ou a cada 5 minutos) → salvar.

Para conferir, depois de alguns minutos clique em **Ver saída** (View output) na tarefa. Se aparecer
"O cron está rodando com o PHP 7.x/8.0/8.1", o comando usou um PHP antigo: troque para o jeito 1.

Sem o cron o sistema funciona, inclusive o e-mail de contato, que sai na hora. O cron serve de
garantia: reenvia o que falhou e faz a limpeza diária dos dados antigos.

## 7. Atualizações automáticas (recomendado)

Com isto ligado, **toda melhoria enviada ao GitHub chega sozinha à hospedagem**, sem você fazer
nada:

1. a cada envio, o GitHub roda todos os testes do sistema;
2. só se tudo passar, ele monta o pacote da hospedagem e deixa guardado no próprio GitHub (release
   "hospedagem");
3. na hospedagem, a tarefa agendada (parte 6) confere a cada 5 minutos se há pacote novo. Se
   houver, baixa e instala sozinha, mantendo banco, fotos, sites e contatos, e republica os sites.

Nenhuma senha da hospedagem fica no GitHub: é a hospedagem que busca. Versão com teste falhando
nunca chega ao ar. Se a troca falhar no meio, a versão anterior volta.

Para ligar, informe no instalador um **token do GitHub só de leitura** (campo "Atualização
automática"). Se já instalou sem ele, rode o instalador de novo (parte 9) e informe o token.

### Como criar o token

1. Entre em <https://github.com/settings/personal-access-tokens/new> (logado no GitHub).
2. **Token name:** `Hospedagem Rankly`. **Expiration:** o maior prazo que aparecer (ou *No
   expiration*). Quando vencer, as atualizações param até você trocar o token.
3. **Repository access:** *Only select repositories* → `ury1996/jogo`.
4. **Permissions → Repository permissions → Contents:** *Read-only*. Não marque mais nada.
5. **Generate token** e copie o código (começa com `github_pat_`). Ele só aparece uma vez.

### Conferir se está funcionando

- No GitHub, aba **Actions** do repositório: cada envio aparece como "Pacote para a hospedagem".
  Com o sinal verde, o pacote foi publicado; vermelho quer dizer que algum teste falhou e nada foi
  enviado para a hospedagem.
- Na hospedagem, o arquivo `rankly-sistema/VERSAO.txt` mostra o commit instalado, e
  `rankly-sistema/var/atualizacao.json` mostra a última verificação e o último erro, se houver.
  Os dois aparecem no Gerenciador de Arquivos.

## 8. Token do GitHub (resumo)

Só leitura, só do repositório `ury1996/jogo` (ver a parte 7). Ele fica guardado apenas na
configuração do sistema na hospedagem (`rankly-sistema/config/config.php`, fora da pasta pública).

## 9. Atualizar à mão (sem a atualização automática)

1. Envie de novo os dois arquivos (`rankly-hospedagem.zip` novo + `instalar.php`) para a mesma pasta
   do subdomínio.
2. Abra `https://editor.seudominio.com.br/instalar.php`.
3. Ele pede o **e-mail e a senha de um administrador** do sistema. Ninguém mais consegue usar o
   instalador.
4. (Opcional) cole chaves novas da IA ou do Pixabay.
5. Clique em **Atualizar agora**.

Banco, fotos, sites, contatos e configuração são mantidos. Os sites publicados são republicados com
a versão nova. O ZIP e o instalador são apagados no fim.

---

## Problemas comuns

| Sintoma | Solução |
|---|---|
| A página do instalador mostra o código ou baixa o arquivo | O PHP não está ativo nessa pasta: confira se o arquivo está na pasta do subdomínio e se ele tem a extensão `.php`. |
| ✗ PHP 8.x (precisa 8.2 ou mais novo) | Parte 2. Depois recarregue a página do instalador. |
| ✗ em uma extensão (gd, zip, pdo_mysql…) | **Configuração do PHP → Extensões do PHP**: marque a extensão e salve. |
| "Esta pasta já tem outro site" | Você está na pasta do site principal ou numa pasta com outros arquivos. Use a pasta do subdomínio novo (parte 1). |
| "Fora da raiz do endereço" | Abra o instalador direto no subdomínio (`https://editor.seudominio.com.br/instalar.php`), não em `seudominio.com.br/editor/instalar.php`. |
| "Usuário ou senha do banco incorretos" / "banco não existe" | Copie de novo os dados da parte 3, com o prefixo `u…_` no nome e no usuário. |
| Aviso "Endereço sem HTTPS" | Espere o SSL do subdomínio ficar pronto (parte 1) e abra a página com `https://`. |
| Depois de instalar, a página dá erro 500 | Confira a versão do PHP (parte 2). O registro de erros fica em `rankly-sistema/var/logs/`. |
| Esqueci a senha | Na tela de entrada do editor, use "Esqueci minha senha" (com o e-mail configurado), ou peça a outro administrador. |
| As atualizações automáticas pararam | Veja `rankly-sistema/var/atualizacao.json` ("ultimoErro"). Em geral é o token vencido: crie outro (parte 7) e rode o instalador de novo informando-o. Confira também se a tarefa agendada (parte 6) está ativa e se o último "Pacote para a hospedagem" na aba Actions do GitHub está verde. |
| A IA demora e dá erro de tempo | Na hospedagem compartilhada o limite de tempo é menor. Tente de novo, ou use descrições mais curtas. |

## O que muda em relação à instalação "completa"

Esta instalação usa o **modo de sites no mesmo endereço**: cada site fica em
`editor.seudominio.com.br/s/nome-do-site/`. Assim dispensa subdomínio curinga e certificado especial,
que nem todo plano oferece.

O passo seguinte, para atender clientes com endereço próprio (`nomedocliente.seudominio.com.br` ou
o domínio do cliente), exige subdomínio curinga e SSL curinga. Veja a seção "Implantação na
Hostinger" do `README.md`.

## Para quem for mexer

- Pacote: `bin/empacotar-hospedagem.php`. Instalador: `ferramentas/hospedagem/instalar.php`.
- Atualização automática: `.github/workflows/hospedagem.yml` (testes → pacote → release
  `hospedagem`, com `codigo=<commit>` no texto) e `app/Lib/Atualizador.php` (chamado pelo
  `app/cli/cron.php`; à mão: `php app/cli/atualizar.php` ou `--estado`). A troca do código acontece
  num cron e as migrações e a republicação no seguinte, já com o código novo carregado.
- A parte web acha o projeto pelo `rankly-raiz.php` da pasta pública. O instalador grava nele o
  caminho absoluto.
- Configuração gerada: `ambiente` `prod`, `sites_no_caminho` `true`, MySQL, segredos aleatórios,
  `email_modo` `smtp` (se informado) ou `arquivo`.
- Testado num Apache + MySQL com a mesma estrutura de pastas da Hostinger
  (`/home/u…/domains/dominio/public_html/editor`): instalar, entrar, criar pelo assistente, enviar
  foto, publicar, receber contato, atualizar, cron, recusas (subpasta, pasta com outro site, senha de
  administrador errada) e nenhum arquivo interno acessível pela web. O servidor da Hostinger é o
  LiteSpeed, que lê os mesmos `.htaccess`.
