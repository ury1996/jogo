# Construtor Rankly · Especificação v0.2

| | |
|---|---|
| Versão | 0.2 · atualiza a v0.1 com o que foi construído |
| Data | 7 de outubro de 2026 |
| Base | Especificação v0.1 (PDF "Construtor Rankly · Especificação funcional e técnica", 6/10/2026) + código deste repositório |
| Contrato técnico | [`docs/ARQUITETURA.md`](ARQUITETURA.md) (quando os dois divergirem, vale o contrato) |
| Instalação e implantação | [`README.md`](../README.md) |

Este texto é para quem precisa saber **o que o sistema faz hoje** sem ler o código. Ele não repete a
v0.1 inteira: diz o que continua igual, o que mudou, onde cada melhoria está e o que ficou para
depois. As melhorias da análise da v0.1 aparecem como **[M1]…[M31]**, a mesma numeração usada nos
comentários do código.

---

## 1. Resumo

O MVP descrito na v0.1 está construído: assistente de 3 passos, editor na própria página,
salvamento automático, fotos, publicação em HTML estático por subdomínio, SEO automático,
formulário com leads e e-mail, rastreamento com faixa de consentimento, 4 nichos e 3 modelos.

O que muda em relação à v0.1, em poucas linhas:

1. **Mais coisas entraram no MVP**: listas de tamanho variável, histórico de versões com
   "voltar à publicação anterior", aba de configurações (SEO e rastreamento), redes sociais,
   exportar leads em CSV. Na v0.1 tudo isso era fase 2.
2. **O documento do site ganhou dados estruturados** (endereço, horários, registro profissional,
   redes) e **especialidades dentro de cada nicho** (ex.: odontologia, medicina e psicologia em
   Clínicas), com tipo próprio no Google e conselho profissional.
3. **Publicar ficou mais seguro**: checklist antes de publicar (erros bloqueiam), bloqueio de
   depoimentos e números de exemplo, troca atômica por link simbólico e retorno à versão anterior.
4. **O site publicado ficou mais completo**: páginas de privacidade, de agradecimento e 404; CSP
   com hash dos scripts; captura da origem da campanha (UTM, gclid); mapa que só carrega ao clicar.
5. **JS e PHP geram o mesmo HTML byte a byte**, conferido por um teste que roda todas as
   combinações da biblioteca.

---

## 2. O que continua igual à v0.1

- Produto, público por fase e princípios (cap. 1).
- Três peças: editor no navegador, API em PHP, gerador estático (cap. 2). A decisão 2.5 foi
  mantida: **o servidor gera o HTML** com Mustache, e o editor usa os mesmos templates para a
  prévia.
- Paleta calculada a partir de uma cor, 4 pares de fontes, 3 acabamentos, fundos alternados
  automáticos, container queries para celular (cap. 5).
- Assistente em 3 passos com miniaturas reais e "Continuar de onde parou" (cap. 6).
- Edição de texto no próprio site, fotos, seções, estilo, desfazer/refazer com 80 passos,
  salvamento automático com 1,5 s de espera, cópia no IndexedDB sem conexão, conflito 409 com
  "Carregar a versão mais nova" / "Manter a minha" (cap. 7).
- Algoritmo de ícones automáticos: palavra mais longa vence, palavras curtas só casam inteiras,
  empate pela ordem do arquivo (cap. 8).
- Metas de desempenho do site publicado (cap. 9.5), com a ressalva do peso (seção 6).
- Regras de conteúdo por profissão: advocacia sem depoimentos, clínicas sem "antes e depois",
  rodapé com registro profissional (cap. 11).

---

## 3. Mudanças por capítulo da v0.1

### Cap. 2 · Arquitetura

- Estrutura de pastas definitiva no `README.md` e no contrato (§1). As raízes web são só
  `public_html/` (editor e API) e `sites/` (sites publicados). `app/`, `config/`, `media/` e `var/`
  ficam fora da web.
- Composer traz só `mustache/mustache` (e PHPUnit para testes). Node é usado só para
  desenvolvimento, testes e para gerar `icones.json` e copiar as fontes (`ferramentas/`).
- Banco: MySQL 8 / MariaDB 10.6+ em produção; SQLite nos testes. As datas são calculadas no PHP
  (UTC), sem funções de data do banco, para o mesmo SQL rodar nos dois.

### Cap. 3 · Modelo de dados

- **Documento versão 2** (contrato §2). Diferenças principais:
  - opção de seção por **nome estável** (`"opcao": "cards-flutuantes"`) e não por posição **[M1]**;
  - itens de lista com **id** próprio e ordem guardada em `listas` **[M2]**;
  - `especialidade` **[M7]** e `dados` estruturados **[M6]**;
  - `confirmados`: alegações que o usuário confirmou como verdadeiras (seção 3, cap. 9).
  - Documentos da versão 1 (opção por número) são convertidos automaticamente (`migrar`).
- **Tabelas novas**: `redefinicoes_senha`, `tarefas` (fila) **[M14]**, `eventos` (quem mudou o quê)
  **[M27]**, `limites` (tentativas) e `migracoes`. `versoes` guarda também os textos padrão
  efetivos no momento da publicação (`resolvidos`) **[M5]**. `leads` ganhou `email` e `origem`
  (UTM etc.) **[M22]**. `midia` ganhou `tipo` (foto/logo), `formato` (webp/svg) e `hash`.
- **API**: a lista completa está no contrato §6. Rotas novas em relação à v0.1: login, saída,
  "esqueci a senha" e redefinição; lista de sites; duplicar e arquivar; validar antes de publicar;
  reverter; versões; leads em CSV; marcar lead como lido; excluir lead; texto alternativo da foto;
  entrega das fotos ao editor; fontes. A rota de domínio próprio continua fase 2.

### Cap. 4 · Biblioteca

- **32 opções**, não 31: a própria tabela 4.1 da v0.1 somava 32. Os ids estão no contrato §3.2.
- Arquivos de template por **id da opção** (`servicos/blocos.mustache`), não `opcao-3.mustache`.
- **Listas de tamanho variável já no MVP** (pergunta 4.2 respondida): serviços 3–8, perguntas 3–10,
  clientes 3–8, com "Adicionar item" e "Remover item" na própria seção. Diferenciais, números,
  passos, equipe e depoimentos ficam com 3; a lista do "Sobre" com 4.
- Tom `cor` (faixa fixa na cor principal) somado aos tons da v0.1.
- Um **lint** confere todos os templates e CSS: sem cores ou fontes fixas, `{{{ }}}` só para HTML
  gerado pelo sistema, nenhum texto usado como condição, responsivo só com as duas faixas de
  container.

### Cap. 5 · Design automático

- Fórmulas da paleta iguais às da v0.1, com arredondamento definido para dar o mesmo resultado em
  JS e PHP.
- Tokens novos para acessibilidade **[M18]**: `--p-fino` (usa o tom escuro em traços quando a cor é
  clara demais), `--p-sobre-escuro` e `--on-p-sobre-escuro` (a cor principal ganha um tom mais claro
  quando não tem contraste sobre seções escuras).
- No site publicado, as container queries viram media queries e as unidades `cqi` viram `vw`
  **[M16]** (o "bloco equivalente" do risco 5.5, mas como conversão completa, não como bloco extra).

### Cap. 6 · Assistente

- Passo 3 pede também a **especialidade** dentro do nicho **[M7]**. Ela define o segmento usado nos
  textos ("Dentista"), o tipo no Google (schema.org), o conselho profissional e se o número de
  registro é obrigatório.
- Especialidades atuais:

  | nicho | especialidades (registro obrigatório em negrito) |
  |---|---|
  | Advocacia | **advocacia (OAB)** |
  | Finanças | **contabilidade (CRC)**, consultoria financeira, crédito |
  | Empresas | **engenharia (CREA)**, indústria, serviços empresariais |
  | Clínicas | **odontologia (CRO)**, **medicina (CRM)**, estética, **fisioterapia (CREFITO)**, **psicologia (CRP)** |

### Cap. 7 · Editor

- Texto editado com `contenteditable="plaintext-only"` e lido por `textContent` **[M3]** (a v0.1
  dizia `innerText`, que traz a caixa alta do CSS para o documento).
- **Retokenização [M4]**: se a pessoa digita o nome ou a cidade do negócio dentro de um texto, o
  sistema guarda `{nome}` / `{cidade}` no lugar. Assim, trocar o nome depois continua atualizando o
  texto. Se o texto editado ficar igual ao padrão, ele volta a ser "padrão" e recebe melhorias
  futuras da biblioteca.
- Colagem que passa do limite é cortada com aviso (a v0.1 só bloqueava a digitação).
- Painel com **quatro abas**: Seções, Estilo, Dados e **Config.** (SEO e rastreamento, que eram fase
  2). A aba Dados tem endereço com busca de CEP (ViaCEP), horários por dia, registro profissional e
  redes sociais **[M6]**.
- **Histórico de publicações** no editor: lista de versões, "Carregar no editor" (como alteração
  desfazível, sem publicar) e "Voltar à publicação anterior" **[M10]**. Era fase 2.
- Painel "Meus sites" com status, link e contador de **leads não lidos** **[M9]**; ações editar, ver,
  leads, duplicar, arquivar.
- Continua como na v0.1: fotos reduzidas no navegador para 2400 px (JPEG 85%), prévia local
  enquanto sobem, cabeçalho sempre primeiro e rodapé sempre último, uma seção de cada tipo,
  arrastar na lista e setas no celular, tema claro/escuro, tudo por teclado.

### Cap. 8 · Ícones

- Phosphor + Healthicons, gerados por `ferramentas/construir-icones.mjs`. Os ids da v0.1 com prefixo
  `h-` viraram nomes próprios (ex.: `braces`, `implant`).
- No site publicado, ícones repetidos na página viram um sprite SVG (`<symbol>`/`<use>`) para
  reduzir o HTML.
- Escolha: manual → automática pelo título → padrão do nicho para a posição → círculo.

### Cap. 9 · Publicação

- **Checklist antes de publicar** (`POST /validar`) **[M5][M6]**. Erros que bloqueiam: WhatsApp
  inválido, nome vazio, profissão regulada sem número de registro, seções fora de ordem ou
  repetidas, e **alegações de exemplo** (contrato §2.4): depoimentos, números, nota do Google e
  clientes que ainda são o texto padrão. Números, nota e clientes podem ser confirmados pelo
  usuário como verdadeiros; depoimento de exemplo nunca. Avisos (não bloqueiam): fotos faltando,
  cor clara demais, termos proibidos pelo conselho, e **textos padrão que mudaram na biblioteca
  desde a última publicação**, com antes e depois **[M5]**.
- **Troca atômica por link simbólico [M10]**: cada publicação vai para
  `sites/.releases/{slug}/{versão}-{token}/` e o link `sites/{slug}` passa a apontar para ela de uma
  vez. As 5 últimas publicações ficam guardadas; "reverter" volta o link para a anterior.
- `sites/.htaccess` com proteção contra laço de reescrita **[M11]** e um roteador PHP
  (`sites/_roteador.php`) para a página 404 de cada site e, na fase 2, para domínios próprios.
- **Fotos**: só as variantes usadas são copiadas para `img/`, com cache de 1 ano **[M12]**.
- **CSS [M16]**: base + seções usadas + paleta + fontes do par, minificado. Até 30 KB vai embutido;
  acima disso, arquivo com hash no nome. Fontes de reserva com `size-adjust` para a página não
  "pular" quando a fonte carrega.
- **Páginas extras [M24]**: `/privacidade/` (política gerada com os dados do dono, o formulário, a
  retenção e os rastreadores, se houver), `/obrigado/` (útil como URL de conversão) e `404.html`,
  todas com o mesmo cabeçalho e rodapé.
- **SEO**: como na v0.1, com o tipo do Google vindo da especialidade. **Sem nota agregada
  (`AggregateRating`)**, porque seria autodeclarada. Favicon SVG com a inicial na cor, ou PNG
  32/180 gerado a partir do logo.
- **CSP [M26]** por `<meta>`, com hash `sha256` dos scripts embutidos (HTML estático não aceita
  nonce). GTM, GA4 e Pixel só entram na CSP quando configurados.
- **Mapa [M17]**: o site mostra uma fachada; o iframe do Google Maps só carrega quando o visitante
  clica.
- **Republicar todos** (`app/cli/republicar-todos.php`), previsto no cap. 12, existe e usa a fila.

### Cap. 10 · Formulários, leads e rastreamento

- O formulário envia para **`/_lead` no próprio domínio do site** (mesma origem: sem CORS e sem
  depender do endereço da API) **[M19]**. `POST /api/lead/{slug}` continua existindo.
- Anti-spam como na v0.1 (pote de mel, 3 s mínimos, 5 envios por IP por hora), com o IP guardado
  só como **HMAC-SHA256 com segredo** (IPv6 agrupado por /64) **[M21]**.
- E-mail ao dono por **cliente SMTP próprio** (SSL ou STARTTLS) **[M20]**, com "Responder" indo para
  o e-mail do visitante quando informado. O e-mail entra na **fila de tarefas [M14]**: sai logo
  depois da resposta ao visitante e, se falhar, o cron tenta de novo.
- **Origem do lead [M22]**: o site guarda `utm_*`, `gclid`, `gbraid`, `wbraid`, página de entrada e
  referência durante a visita e manda junto com o formulário. O painel mostra a origem.
- Painel de leads com marcar como lido, **excluir (LGPD)** e **exportar CSV** (era fase 2).
- Rastreamento: eventos `rankly_whatsapp_click` (com a posição do botão), `rankly_form_submit` e
  `rankly_phone_click` no `dataLayer`. **Faixa de consentimento [M23]** só quando há GTM, GA4 ou
  Pixel: modo de consentimento do Google começa em "negado" e os scripts de terceiros só carregam
  depois de "Aceitar". A escolha fica guardada no navegador e pode ser alterada por um botão na
  página de privacidade.

### Cap. 11 · Conteúdo por nicho

- Formato do pacote de nicho no contrato §3.6, com `especialidades`, `listas` padrão e
  `conformidade` (conselho, aviso e termos proibidos que geram aviso no checklist).
- Textos gerais e "novo item" de cada lista ficam em `nichos/comum.json`.

### Cap. 12 · Segurança, LGPD e operação

- **Login [M27]**: sessão própria em arquivo com cookie `HttpOnly`, `SameSite=Lax` e `Secure` em
  produção; CSRF em toda rota que altera dados; login e "esqueci a senha" só aceitos da origem do
  editor; 5 tentativas erradas por e-mail+IP em 15 minutos; link de redefinição válido por 1 hora;
  registro de eventos na tabela `eventos` (login, criação, salvamentos agrupados a cada 10 minutos,
  publicação, reversão, fotos, exclusão e exportação de leads). Ainda não há tela para consultar
  esse registro.
- **Uploads [M15][M26]**: tipo conferido pelo conteúdo, limite de tamanho e de megapixels, foto
  sempre recodificada (remove EXIF, inclusive GPS, depois de corrigir a orientação), WebP em 480,
  960 e 1600 px (logos em 160, 320 e 640). Logo SVG passa por limpeza que recusa scripts, eventos e
  recursos externos, e é entregue com CSP própria. A pasta `media/` não é servida nem executa PHP;
  o editor recebe as fotos por uma rota que exige sessão.
- **Fila de tarefas [M14]** no banco, executada por `app/cli/cron.php`: e-mails, republicação e
  limpeza diária (leads além de 12 meses, tokens vencidos, sessões, tarefas antigas e fotos sem uso
  há 30 dias).
- **Em produção**, segredos de exemplo ou curtos são recusados; erros vão só para o log.
- Backup: procedimento no `README.md`. Não há script de backup no repositório.

---

## 4. Melhorias [M1]–[M31]

A análise da v0.1 numerou 31 melhorias. O contrato e o código marcam com o número aquelas que
mudaram algum comportamento. A tabela traz cada uma com o local principal no código.

| # | Melhoria | Onde está |
|---|---|---|
| M1 | Opção de seção por id estável, nunca por posição | `biblioteca/secoes/*/manifest.json`; conversão de documentos antigos em `compartilhado/documento.mjs` ≡ `app/Preparo/Documento.php` (`migrar`) |
| M2 | Itens de lista com id; ordem em `listas`; remover um item não "ressuscita" outro | `compartilhado/textos.mjs` ≡ `app/Preparo/Textos.php`; `editor/operacoes.mjs`; testes `tests/js/compartilhado-textos.test.mjs`, `tests/e2e/editor-revisao.spec.mjs` |
| M3 | Edição com `plaintext-only` e leitura por `textContent` | `public_html/editor/js/editor/edicao-texto.mjs` |
| M4 | Retokenização de nome e cidade; texto igual ao padrão volta a ser padrão | `retokenizar` em `compartilhado/textos.mjs` ≡ `Preparo/Textos.php`; `editor/edicao-texto.mjs` |
| M5 | Aviso de textos padrão alterados desde a última publicação; `versoes.resolvidos` | `app/Gerador/Validador.php`, `Gerador/Montagem.php`; `editor/publicar.mjs` |
| M6 | Dados estruturados do negócio (endereço, horários, registro, redes); registro obrigatório em profissão regulada | `compartilhado/dados.mjs` ≡ `Preparo/Dados.php`; `app/Lib/ValidadorDocumento.php`; `Gerador/Validador.php`; `editor/painel-dados.mjs` |
| M7 | Especialidades dentro do nicho (segmento, schema.org, conselho) | `biblioteca/nichos/*.json`; `Documento::especialidade`; lint em `tests/js/conteudo.test.mjs` |
| M8 | Teste de paridade JS ≡ PHP byte a byte | `tests/paridade/`, `tests/js/compartilhado-paridade.test.mjs` |
| M9 | Painel "Meus sites" com leads não lidos | `public_html/editor/js/sites.mjs`; `GET /api/sites` em `app/Api/Sites.php` |
| M10 | Publicação atômica por link simbólico, 5 releases guardadas, reverter | `app/Gerador/Publicador.php`, `Gerador::reverter`; `tests/e2e/publicacao.spec.mjs` |
| M11 | `.htaccess` dos sites sem laço de reescrita; roteador PHP | `sites/.htaccess`, `sites/_roteador.php`, `app/Gerador/ServidorEstatico.php` |
| M12 | Só as variantes de foto usadas, com cache de 1 ano | `app/Gerador/Gerador.php` (cópias), regras de cache em `sites/.htaccess` |
| M13 | Domínio próprio: decisão em aberto entre Hostinger (adicionar domínio à mão no hPanel) e Cloudflare for SaaS. A base já existe: tabela `dominios` e roteador PHP por host | `sites/_roteador.php`, `app/Gerador/ServidorEstatico.php`, `app/Lib/Sites.php` (`porHost`) |
| M14 | Fila de tarefas no banco + cron | `app/Lib/Tarefas.php`, `app/Lib/Executores.php`, `app/cli/cron.php` |
| M15 | Pipeline de fotos e logos (tipo real, recodificação, EXIF, variantes, megapixels) | `app/Lib/Imagem.php`, `app/Lib/Midia.php`, `app/Api/Midia.php`; `editor/fotos.mjs` |
| M16 | CSS publicado: container → media, `cqi` → `vw`, fontes de reserva com `size-adjust`, embutido até 30 KB | `app/Gerador/Css.php`; `biblioteca/base.css`; `ferramentas/copiar-fontes.mjs` |
| M17 | Fachada do mapa (iframe só ao clicar) | `app/Gerador/ScriptSite.php`; `biblioteca/secoes/contato/` |
| M18 | Acessibilidade: tokens de contraste, `alt` padrão, "pular para o conteúdo" | `compartilhado/paleta.mjs` ≡ `Preparo/Paleta.php`; `Preparo/Html.php`; `biblioteca/base.css` §14 |
| M19 | Formulário na mesma origem do site (`/_lead`), sem CORS e coerente com a CSP | `sites/_lead.php`, `sites/.htaccess`, `app/Lib/Leads.php` |
| M20 | Cliente SMTP próprio e e-mail ao dono | `app/Lib/Email/Smtp.php`, `app/Lib/Email/` |
| M21 | IP só como HMAC com segredo (LGPD), IPv6 agrupado por /64; limite por IP | `app/Lib/IpHash.php`, `app/Lib/LimiteTaxa.php`, `app/Lib/Leads.php` |
| M22 | Origem da campanha (UTM, gclid, gbraid, wbraid) no lead | `app/Gerador/ScriptSite.php`, `app/Lib/Leads.php`, `public_html/editor/js/leads.mjs` |
| M23 | Faixa de consentimento com modo de consentimento do Google | `app/Gerador/ScriptSite.php`, `app/Gerador/Css.php` |
| M24 | Páginas de privacidade, obrigado e 404 | `app/Gerador/PaginasExtras.php` |
| M25 | Editor/API e sites dos clientes em domínios separados (`url_editor` × `dominio_sites`); na fase 3, inscrever o domínio dos sites na Public Suffix List | `config/config.exemplo.php`; pendência de operação |
| M26 | CSP com hash no site publicado; limpeza de SVG; SVG servido sem executar | `app/Gerador/Seo.php`, `app/Lib/Svg.php`, `app/Api/Midia.php` |
| M27 | Login, sessão, CSRF, "esqueci a senha", registro de eventos | `app/Api/Auth.php`, `app/Lib/Sessao.php`, `app/Lib/Csrf.php`, `app/Lib/Eventos.php` |
| M28 | Estimativa da fase 1 revista (9 a 12 semanas). É processo, não código | — |
| M29 | Começar por uma fatia vertical. Foi seguido: o fluxo ponta a ponta está testado | `tests/e2e/fluxo.spec.mjs` |
| M30 | Teste visual automático em vez de revisão manual: capturas estáveis dos 12 modelos em 1280 e 390 px | `tests/e2e/sites.spec.mjs` (capturas em `var/e2e/capturas/`) |
| M31 | "1 dia por nicho" só vale se as seções existentes bastarem; os próximos nichos precisam de seções novas. É processo, não código | — |

---

## 5. Decisões da v0.1 (cap. 14): situação

| # | Pergunta | Situação na v0.2 |
|---|---|---|
| 1 | Onde gerar o HTML | **Decidido**: servidor, com templates compartilhados e teste de paridade. |
| 2 | Listas variáveis no MVP | **Decidido e feito**, para mais listas do que o recomendado (serviços, perguntas e clientes). |
| 3 | Uma página ou várias | **Decidido**: uma página no MVP (mais privacidade, obrigado e 404). Várias páginas na fase 3. |
| 4 | Domínio dos subdomínios | **Em aberto**. O código só precisa do valor em `dominio_sites`. |
| 5 | DNS e SSL curinga na Hostinger | **Em aberto** (seção 6). |
| 6 | Banco de fotos | Fase 3, como recomendado. |
| 7 | Depoimentos de exemplo | **Decidido e feito**: publicação bloqueada com depoimento de exemplo; vale também para números, nota e clientes, que podem ser confirmados. |
| 8 | Nota real do Google | Fase 2/3. Hoje a nota é um texto que precisa ser trocado ou confirmado. |
| 9 | Quem publica | Hoje admin e equipe. O papel "cliente" já existe no banco, sem tela; regra final na fase 2. |
| 10 | Próximos 4 nichos | Em aberto (sugestão da v0.1: energia solar, controle de pragas, barbearia, escolas). |
| 11 | Exportar para WordPress | Não no MVP. |
| 12 | Preço e planos | Depois da fase 1. |

---

## 6. Decisões em aberto

### 6.1 Subdomínios na Hostinger ou Cloudflare na frente

O sistema precisa de DNS curinga (`*.dominio`) e certificado curinga. Ainda não foi confirmado se o
Cloud Professional emite SSL curinga e aceita apontar o subdomínio curinga para uma pasta fora de
`public_html`.

- **Só Hostinger**: menos peças. Depende de o painel aceitar as duas coisas.
- **Cloudflare gratuita na frente**: resolve DNS e SSL curinga com certeza e dá proteção extra
  contra ataques. Custa mais uma conta para administrar, e o sistema precisa de
  `confiar_cloudflare = true` para ler o IP real do visitante.

O mesmo vale para o **domínio próprio dos clientes** (fase 2): na Hostinger, cada domínio precisa de
SSL emitido pelo painel; com Cloudflare, existe o recurso de domínios de clientes (pago a partir de
certo volume). O roteador PHP e a tabela `dominios` já funcionam nos dois casos; falta a tela e a
verificação de DNS.

**Recomendação**: testar no painel antes da primeira implantação. Se qualquer um dos dois itens
falhar, Cloudflare.

### 6.2 Consentimento "básico" ou "avançado"

Hoje é o **modo básico**: nada do Google ou da Meta carrega antes de "Aceitar". É o mais seguro para
a LGPD, mas o Google Ads perde as conversões de quem recusa ou ignora a faixa. O **modo avançado**
carrega a tag antes, com consentimento negado, e manda sinais sem cookies que o Google usa para
estimar essas conversões. Ganha medição e exige revisar a política de privacidade.

**Decisão necessária**: manter o básico ou oferecer o avançado como opção por site.

### 6.3 Peso do HTML + CSS acima da meta de 80 KB

Medido nos sites gerados: cerca de **99 KB sem compressão** e **cerca de 22 KB com gzip**. O peso se
divide entre o CSS (em torno de 43 KB: o `base.css` inteiro, com as regras dos três acabamentos, mais
o CSS das seções usadas) e o HTML (45 a 55 KB, com os ícones SVG e o script embutidos).

O servidor comprime HTML e CSS (o `sites/.htaccess` liga o gzip), então o que trafega fica bem
abaixo de 80 KB. O teste ponta a ponta usa essa medida: menos de 80 KB transferidos e, como
segurança, menos de 128 KB sem compressão; JavaScript abaixo de 5 KB.

**Decisão necessária**: aceitar a meta como "bytes transferidos" (situação atual) ou investir em
cortar o CSS por acabamento e fonte usados para chegar a 80 KB sem compressão.

### 6.4 Outros pontos menores

- **Usuário do banco sem permissão de alterar estrutura** (cap. 12): pode não ser possível na
  Hostinger, que costuma dar todas as permissões ao usuário do banco.
- **ViaCEP no editor**: é a única chamada a terceiros feita pelo editor. Se for um problema, o
  endereço pode ser digitado à mão (a busca já é tolerante a falhas).
- **Ícones de odontologia em traço** no acabamento Moderno (risco aceito na v0.1).
- **Republicar todos** publica o documento salvo, inclusive alterações ainda não publicadas.
  Alternativa: republicar a partir da última versão publicada.

---

## 7. O que ficou para a fase 2

- Acesso do cliente ao próprio site: tela de convites e papéis (dono, editor). O banco e a checagem
  de acesso na API já existem.
- Domínio próprio com verificação de DNS e SSL automáticos, e redirecionamento 301 do subdomínio.
- Shift+Enter para criar parágrafo nos textos longos.
- Ponto focal das fotos.
- Prévia no celular por QR code com link temporário.
- Mensagem inicial do WhatsApp editável.
- Autocompletar cidade com a lista do IBGE.
- Registro anônimo de títulos que não casaram com nenhum ícone.
- Link do perfil no Google para a nota de avaliações.
- 4 nichos novos.
- Login em duas etapas (a coluna `totp_segredo` já existe).
- Alerta por e-mail quando uma publicação falhar (hoje fica no log).
- Cloudflare Turnstile no formulário, se o anti-spam atual não bastar.
- Contêiner GTM modelo exportado em JSON.

Já feitos e que eram fase 2 na v0.1: listas variáveis, versões e restauração, aba de configurações
(SEO, rastreamento, redes sociais), exportar leads.

## 8. O que ficou para a fase 3

- Cadastro aberto, planos e cobrança recorrente (Pix e cartão).
- Banco de fotos por nicho.
- Textos reescritos por IA a partir de uma frase sobre o negócio.
- Várias páginas por site (uma por serviço).
- Termos de uso, central de ajuda, painel administrativo.
- Miniaturas do assistente como imagens geradas (com muitos nichos).

---

## 9. Como a qualidade é conferida

| suíte | o que garante |
|---|---|
| Testes JS (`node --test`) | lógica compartilhada, estado do editor, operações, lint da biblioteca e do conteúdo dos nichos |
| PHPUnit | preparo, banco, imagens, SVG, e-mail, fila, leads, API completa, gerador e publicador |
| Paridade | JS e PHP produzem o mesmo HTML para todas as combinações de nicho, modelo, especialidade, acabamento, fonte, cor e conteúdo **[M8]** |
| Ponta a ponta (Playwright) | o fluxo inteiro num navegador real, os 12 modelos em computador e celular, CSP sem violações, formulário com e sem JavaScript, consentimento, reverter, casos difíceis do editor |

Comandos no `README.md` (`bin/testar.sh` roda tudo).
