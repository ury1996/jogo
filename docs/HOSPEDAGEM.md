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

Para gerar uma versão nova no seu computador, com o Docker Desktop, rode na pasta do projeto:
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
6. Clique em **Instalar**.

Na tela de "Pronto!", clique em **Abrir o editor** e entre com o seu e-mail e senha.

O instalador:

- põe os arquivos do sistema na pasta `rankly-sistema`, **ao lado** da `public_html`, onde a internet
  não alcança;
- copia só a parte web (editor e API) para a pasta do subdomínio;
- cria as tabelas e o seu usuário;
- **apaga o ZIP e a si mesmo** no final.

## 6. Tarefa agendada (recomendado)

A tela final mostra um comando. No hPanel: **Avançado → Cron Jobs** → tipo **Personalizado**
(Custom) → cole o comando → frequência **a cada minuto** (ou a cada 5 minutos) → salvar.

O comando é parecido com este:

```
/opt/alt/php83/usr/bin/php /home/u123456789/domains/seudominio.com.br/rankly-sistema/app/cli/cron.php
```

Sem o cron o sistema funciona, inclusive o e-mail de contato, que sai na hora. O cron serve de
garantia: reenvia o que falhou e faz a limpeza diária dos dados antigos.

## 7. Atualizar para uma versão nova

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
- A parte web acha o projeto pelo `rankly-raiz.php` da pasta pública. O instalador grava nele o
  caminho absoluto.
- Configuração gerada: `ambiente` `prod`, `sites_no_caminho` `true`, MySQL, segredos aleatórios,
  `email_modo` `smtp` (se informado) ou `arquivo`.
- Testado num Apache + MySQL com a mesma estrutura de pastas da Hostinger
  (`/home/u…/domains/dominio/public_html/editor`): instalar, entrar, criar pelo assistente, enviar
  foto, publicar, receber contato, atualizar, cron, recusas (subpasta, pasta com outro site, senha de
  administrador errada) e nenhum arquivo interno acessível pela web. O servidor da Hostinger é o
  LiteSpeed, que lê os mesmos `.htaccess`.
