# Construtor Rankly — Contrato de arquitetura (v0.2)

Este documento é a **fonte da verdade técnica** do código. Ele implementa a especificação v0.1
(PDF "Construtor Rankly · Especificação funcional e técnica") **com as melhorias da análise**
(numeradas aqui como `[M1]`…`[M31]`, na ordem da análise). Quando este documento e o PDF
divergirem, vale este documento.

Idioma do código: nomes de domínio, comentários e mensagens em **português** (como na
especificação). Identificadores técnicos genéricos (`get`, `render`, `id`) podem ficar em inglês.

---

## 0. Visão rápida

```
Editor (navegador, JS puro, módulos ES)  ⇄  API (PHP 8.2+)  →  Gerador estático (PHP)
        │                                      │                    │
        └── compartilhado/*.mjs  ≡ paridade ≡  app/Preparo/*.php ───┘
                (mesma lógica, mesmo HTML, testado por tests/paridade)
```

- O editor renderiza a prévia com **mustache.js** + `compartilhado/preparo.mjs`.
- O servidor publica com **mustache.php** + `app/Preparo/Preparo.php`.
- Os dois produzem **o mesmo HTML byte a byte** para o mesmo documento (`modo: "publicar"`).
- O site publicado é uma pasta estática servida por link simbólico atômico `[M10]`.

---

## 1. Estrutura de pastas

```
README.md
docs/ARQUITETURA.md            ← este arquivo
docs/especificacao-v0.2.md     ← especificação atualizada (texto para humanos)
composer.json  package.json
config/config.exemplo.php      ← copiar para config/config.php (fora do git)
app/                           ← PHP, namespace Rankly\ (PSR-4), NÃO acessível pela web
  bootstrap.php                ← autoload, config, tratamento de erros; devolve Rankly\Aplicacao
  Aplicacao.php                ← contêiner simples: config(), db(), biblioteca(), agora()
  Http/                        ← Router, Requisicao, Resposta, ErroHttp
  Api/                         ← controladores (Auth, Sites, Midia, Biblioteca, Leads, Publicacao, Versoes)
  Lib/                         ← Db, Config, Sessao, Csrf, LimiteTaxa, IpHash, Slug, Imagem,
                                 Telefone, Tarefas, Log, Email/Smtp, Email/Mailer, Svg
  Preparo/                     ← PORTE PHP de compartilhado/*.mjs (paridade obrigatória)
  Gerador/                     ← Validador, Gerador, Css, Seo, PaginasExtras, ScriptSite, Publicador, Favicon
  cli/                         ← migrar.php, criar-usuario.php, cron.php, republicar-todos.php
  sql/mysql/*.sql  sql/sqlite/*.sql
biblioteca/                    ← compartilhada entre editor e servidor (servida via API)
  base.css                     ← tokens, reset, componentes, acabamentos, fontes (classes)
  parciais/*.mustache          ← parciais compartilhadas (ex.: formulario.mustache)
  secoes/{tipo}/manifest.json
  secoes/{tipo}/{opcaoId}.mustache
  secoes/{tipo}/estilo.css
  nichos/comum.json  nichos/{nicho}.json
  modelos/{modelo}.json
  icones/icones.json
  fontes/fontes.json  fontes/*.woff2
public_html/                   ← raiz web do editor/API
  .htaccess                    ← /api/* → api/index.php; nega arquivos ocultos
  index.php                    ← redireciona para /editor/
  api/index.php                ← front controller da API
  editor/index.html
  editor/css/editor.css        ← interface (painéis, modais, botões) — tema claro/escuro
  editor/css/tela.css          ← realces de edição sobre o site (contornos, barrinhas)
  editor/js/app.mjs …          ← ver §9
  editor/js/compartilhado/*.mjs
  editor/js/vendor/mustache.mjs
  router-dev.php               ← roteador para `php -S` (desenvolvimento)
sites/                         ← raiz web dos sites publicados (curinga *.dominio)
  .htaccess  _lead.php  _roteador.php  router-dev.php   (versionados)
  .releases/{slug}/{versao}-{token}/…  (gerado)
  {slug} → .releases/{slug}/…          (link simbólico, gerado)
media/                         ← fotos enviadas (fora da web; .htaccess "Require all denied")
ferramentas/                   ← scripts Node de construção (ícones, fontes, vendor)
tests/js  tests/php  tests/paridade  tests/e2e
bin/                           ← dev.sh, testar.sh
```

---

## 2. Documento do site (versaoEsquema 2)

```jsonc
{
  "versaoEsquema": 2,
  "nicho": "clinicas",                 // id do pacote em biblioteca/nichos
  "especialidade": "odontologia",      // [M7] id dentro de nicho.especialidades
  "modelo": "moderno",                 // id em biblioteca/modelos
  "estilo": {
    "cor": "#c23b6e",                  // hex minúsculo #rrggbb
    "fonte": "editorial",              // classica | editorial | moderna | amigavel | nobre | clara | geometrica
    "acabamento": "moderno",           // classico | moderno | direto | elegante | suave | impacto
    "whatsappFlutuante": true
  },
  "dados": {                           // [M6] dados estruturados
    "nome": "Clínica Sorriso Vivo",
    "cidade": "Jundiaí", "uf": "SP",
    "whatsapp": "(11) 98765-4321",     // como digitado; normalizar só dígitos ao usar
    "telefone": "", "email": "",
    "logo": null,                      // id de mídia ou null
    "endereco": { "cep": "", "logradouro": "", "numero": "", "complemento": "", "bairro": "" },
                                       // cidade/uf do endereço = dados.cidade / dados.uf
    "horarios": { "seg": ["08:00","18:00"], "ter": ["08:00","18:00"], "qua": ["08:00","18:00"],
                  "qui": ["08:00","18:00"], "sex": ["08:00","18:00"], "sab": ["08:00","12:00"], "dom": null },
                                       // null = fechado; objeto inteiro pode ser {} (não informado)
    "registro": { "numero": "", "uf": "", "responsavel": "" },
                                       // conselho vem da especialidade (ex.: CRO); responsavel = responsável técnico
    "redes": { "instagram": "", "facebook": "", "linkedin": "", "youtube": "", "google": "" }
  },
  "secoes": [ { "tipo": "header", "opcao": "simples" }, { "tipo": "hero", "opcao": "cards-flutuantes" } ],
                                       // [M1] opção por id estável, nunca por índice
  "textos":  { "hero.titulo": "Cuidado com o seu sorriso…" },  // só o que foi editado
  "listas":  { "serv": ["1", "2", "nk3f"] },                     // [M2] ordem dos itens; ausente = padrão do nicho
  "imagens": { "hero.img": "m_91c0de2a", "serv.1.img": "m_0a1b2c3d" },
  "icones":  { "serv.1": "tooth" },    // só escolhas manuais; ausente = automático
  "confirmados": ["num", "aval"],      // [M-checklist] grupos de "alegações" confirmadas pelo usuário
  "rastreamento": { "gtm": "", "ga4": "", "metaPixel": "" },
  "seo": { "titulo": null, "descricao": null }
}
```

### 2.1 Chaves de conteúdo

- **Escalar**: `{grupo}.{campo}` — grupo `/^[a-z]+$/`, campo `/^[a-z][a-z0-9]*$/`. Ex.: `hero.titulo`, `sobre.img2`.
- **Item de lista**: `{lista}.{id}.{campo}` — lista `/^[a-z]+$/`, id `/^[a-z0-9]+$/`, campo `/^[a-z]+$/`. Ex.: `serv.1.t`, `faq.nk3f.q`.
- **Ícone**: `{lista}.{id}` (só no mapa `icones`).
- 2 segmentos = escalar; 3 segmentos = item de lista. Nomes de campo proibidos para escalares: `itens`, `qtd`, `tem`, `p1`…`p9`.
- Itens padrão do nicho têm ids `"1"`, `"2"`, … ; itens novos criados no editor têm id `"n"` + 4 caracteres base36 aleatórios (ex.: `nk3f`). Como o padrão é por **id** e não por posição, remover um item nunca "ressuscita" outro `[M2]`.
- Cada chave é **definida** (tipo, limite) em exatamente um manifesto, mas qualquer template pode **exibir** qualquer chave.

### 2.2 Lista canônica de grupos, listas e campos

| Grupo/lista | Escalares (`grupo.campo`) | Lista: campos do item | Itens (mín–máx) | Manifesto dono |
|---|---|---|---|---|
| `hero` | eyebrow, titulo, texto, cta, cta2, img, img2, selo | — | — | hero |
| `form` | titulo, nota, botao | — | — | hero |
| `dif` | eyebrow, titulo, img | `dif`: t, d, ic | 3–3 | diferenciais |
| `cli` | titulo | `cli`: t | 3–8 | clientes |
| `sobre` | eyebrow, titulo, texto, img, img2, cta | `sobrel`: t | 4–4 | sobre |
| `serv` | eyebrow, titulo, texto, link | `serv`: t, d, img, ic | 3–8 | servicos |
| `num` | img | `num`: v, l | 3–3 | numeros |
| `passos` | eyebrow, titulo, texto, img | `passos`: t, d | 3–3 | passos |
| `equipe` | eyebrow, titulo, texto | `equipe`: n, c, f | 3–3 | equipe |
| `dep` | eyebrow, titulo, img | `dep`: t, n, c | 3–3 | depoimentos |
| `aval` | nota, txt | — | — | depoimentos |
| `faq` | eyebrow, titulo, texto, img, ajuda | `faq`: q, a | 3–10 | faq |
| `cta` | titulo, texto, botao, img | — | — | cta |
| `contato` | eyebrow, titulo, texto, botao, mapa, img | — | — | contato |
| `atalhos` | r1, r2, r3, d1, d2, d3 | — | — | atalhos |
| `rodape` | sobre, texto | — | — | rodape |

**Limites de caracteres (`max`) — valores únicos usados por manifestos e textos dos nichos:**

| campo | max | campo | max |
|---|---|---|---|
| `*.eyebrow` | 40 | `*.titulo` (exceto hero) | 80 |
| `hero.titulo` | 90 | `*.texto` (padrão) | 220 |
| `hero.texto` | 200 | `sobre.texto` (texto-longo) | 420 |
| botões: `hero.cta`, `hero.cta2`, `sobre.cta`, `cta.botao`, `form.botao`, `contato.botao` | 28 | `serv.link`, `contato.mapa` | 24 |
| `hero.selo` | 40 | `form.titulo` / `form.nota` | 60 / 120 |
| `cli.titulo` / item `cli.t` | 60 / 30 | item `sobrel.t` | 60 |
| itens `dif.t` / `dif.d` | 40 / 140 | itens `serv.t` / `serv.d` | 40 / 160 |
| itens `num.v` / `num.l` | 8 / 40 | itens `passos.t` / `passos.d` | 40 / 160 |
| itens `equipe.n` / `equipe.c` | 40 / 50 | itens `dep.t` / `dep.n` / `dep.c` | 240 / 40 / 50 |
| `aval.nota` / `aval.txt` | 4 / 60 | itens `faq.q` / `faq.a` | 100 / 320 |
| `faq.ajuda` | 120 | `cta.texto` / `contato.texto` | 180 / 200 |
| `rodape.sobre` / `rodape.texto` | 200 / 120 | `atalhos.r1`…`r3` / `atalhos.d1`…`d3` | 40 / 120 |

`ic` é um campo virtual do tipo ícone (guardado em `icones["{lista}.{id}"]`, automático a partir de `t`).
`img`, `img2`, `f` são campos de imagem (guardados em `imagens`) — inclusive `num.img` e `contato.img` (fotos de fundo).
Endereço, horário, e-mail, telefone, registro e redes **não são textos**: vêm de `dados` `[M6]`.

### 2.3 Regras de resolução de texto (`textoEfetivo`)

```
valor = doc.textos[chave]
     ?? nicho.textos[chave]
     ?? comum.textos[chave]
     ?? (item de lista sem padrão → comum.novoItem[lista][campo])
     ?? ""
valor = substituirVariaveis(valor, dados)   // {nome} {cidade} {segmento} — sempre, inclusive em texto editado
```

- `{nome}` → `dados.nome` (vazio → `nicho.exemplo.nome`); `{cidade}` → `dados.cidade` (vazio → `nicho.exemplo.cidade`);
  `{segmento}` → `especialidade.segmento` (ex.: "Dentista").
- **Retokenização `[M4]`** (só no editor, ao confirmar uma edição): ocorrências exatas (sensível a maiúsculas) de
  `dados.nome` (se ≥ 3 caracteres) e `dados.cidade` (se ≥ 3) viram `{nome}` / `{cidade}`. Se o resultado ficar igual ao
  padrão (nicho/comum), a chave é **removida** de `textos` (volta a propagar).
- Texto apagado por completo volta ao padrão (remove a chave) com aviso "Texto restaurado ao padrão…".
- **Lista efetiva**: `doc.listas[lista]` se existir; senão `nicho.listas[lista]`; senão `comum.listas[lista]`.
  Itens além do máximo do manifesto são ignorados na renderização.

### 2.4 Alegações (checklist de publicação)

Grupos de "alegação": `dep` (depoimentos), `num` (números), `aval` (nota do Google), `cli` (clientes).
Se uma seção visível exibe um desses grupos, e algum texto dele ainda é o **padrão** (não está em `doc.textos`),
a publicação é **bloqueada** — a menos que o grupo esteja em `doc.confirmados` (o usuário confirmou que o padrão é
verdadeiro). Exceção: `dep` nunca pode ser confirmado (depoimento de exemplo é sempre fictício).

---

## 3. Biblioteca

### 3.1 Manifesto de seção (`biblioteca/secoes/{tipo}/manifest.json`)

```jsonc
{
  "tipo": "servicos",
  "nome": "Serviços",
  "ordem": 6,                         // ordem sugerida ao adicionar
  "fixa": null,                       // "inicio" (header) | "fim" (rodape) | null
  "ancora": "servicos",               // id HTML da seção (único na página)
  "menu": "Serviços",                 // rótulo no menu do cabeçalho; null = não entra no menu
  "opcoes": [
    { "id": "cards", "nome": "Cards", "descricao": "Cards com ícone e \"Saiba mais\".", "tom": "claro",
      "sizes": { "serv.*.img": "(max-width: 760px) 100vw, 33vw" } },
    { "id": "blocos", "nome": "Blocos", "descricao": "…", "tom": "claro" }
  ],
  "campos": {
    "serv.eyebrow": { "tipo": "texto", "max": 40, "rotulo": "Rótulo acima do título" },
    "serv.titulo":  { "tipo": "texto", "max": 80 },
    "serv.texto":   { "tipo": "texto-longo", "max": 220 },
    "serv.link":    { "tipo": "texto", "max": 24 }
  },
  "listas": {
    "serv": {
      "repete": [3, 8],
      "rotuloItem": "serviço",
      "campos": {
        "t":   { "tipo": "texto", "max": 40 },
        "d":   { "tipo": "texto-longo", "max": 160 },
        "img": { "tipo": "imagem", "proporcao": "4:3", "rotulo": "Foto do serviço" },
        "ic":  { "tipo": "icone", "automatico": "t" }
      }
    }
  },
  "alegacoes": []                     // grupos de §2.4 que esta seção exibe (ex.: ["dep","aval"])
}
```

- `tom` ∈ `claro | escuro | branco | tom-claro | cor` (`cor` = faixa fixa na cor principal).
- `tipo` de campo ∈ `texto | texto-longo | imagem | icone`.
- `sizes`: mapa chave→valor do atributo `sizes`; `*` casa qualquer id de item. Padrão: `"100vw"`.
- `campos` de imagem podem ter `"fundo": true` (foto de fundo: botão "Enviar foto de fundo" no editor).

### 3.2 Catálogo de seções e opções (ids estáveis `[M1]`)

| tipo | opções (id → nome) | tom |
|---|---|---|
| `header` | `simples` Simples · `barra` Com barra de contato · `info` Com informações e menu em faixa | branco |
| `hero` | `cards-flutuantes` Foto com cards flutuantes · `fundo-cards` Foto de fundo com cards · `formulario` Com formulário · `centralizado` Centralizado · `inset` Em cartão · `dividido` Dividido · `retrato` Retrato com forma · `titulo-gigante` Título gigante | claro · escuro · cor · claro · claro · claro · claro · escuro |
| `atalhos` | `faixa` Faixa de informações · `cards` Cards sobrepostos | escuro · claro |
| `diferenciais` | `faixa-icones` Faixa de ícones · `foto-selo` Foto com selo · `numerados` Numerados | claro |
| `clientes` | `faixa` Faixa · `grade` Grade | claro |
| `sobre` | `duas-fotos` Duas fotos com selo · `foto-numeros` Foto larga com números · `assinatura` Com assinatura · `manifesto` Manifesto | claro |
| `servicos` | `cards` · `lista` · `cards-foto` Cards com foto · `blocos` Blocos · `fotos-sobrepostas` Fotos com card sobreposto · `foto-fundo` Fotos de fundo · `linhas-numeradas` Linhas numeradas | claro (×6) · escuro |
| `numeros` | `faixa-clara` · `faixa-cor` · `foto-fundo` Sobre foto · `fantasma` Algarismos fantasma | claro · cor · escuro · claro |
| `passos` | `linha-tempo` Linha do tempo · `lista-foto` Lista com foto · `destaque-primeiro` Primeiro em destaque | claro |
| `equipe` | `fotos-nome` Fotos com nome · `compacta` · `retratos-altos` Retratos altos · `cards-horizontais` Cards horizontais | claro |
| `depoimentos` | `cards-nota` Cards com nota · `destaque-foto` Destaque com foto · `faixa-escura` Faixa escura · `mosaico` Mosaico | claro · claro · escuro · claro |
| `faq` | `centralizada` · `foto-ajuda` Com foto e ajuda · `caixa` Em caixa · `duas-colunas` Duas colunas | claro |
| `cta` | `faixa-cor` Faixa na cor · `caixa-clara` Caixa clara · `foto-fundo` Foto de fundo · `telefone` Telefone em destaque · `pessoa` Com pessoa | cor · claro · escuro · escuro · cor |
| `contato` | `formulario` Com formulário · `mapa` Com mapa · `foto-card` Formulário sobre foto · `escuro` Escuro | claro · claro · escuro · escuro |
| `rodape` | `completo` · `simples` · `marca-gigante` Marca gigante | escuro |

(60 opções. As 32 primeiras vêm do PDF; as demais, da análise de 29 templates de referência — ver
`docs/especificacao-v0.2.md`. O tipo `atalhos` é novo: faixa/cards de WhatsApp, horário e endereço logo
abaixo do destaque, com valores de `dados` e rótulos `atalhos.r*`/`d*`.)

### 3.3 Templates Mustache — regras obrigatórias

1. O template gera **só o conteúdo** da seção. O invólucro é gerado pelo preparo (§5.4).
2. **Nenhuma cor ou fonte fixa**: só tokens CSS (§4).
3. Todo elemento com texto editável tem `data-k="{chave}"` e **contém apenas o texto** (nenhum elemento filho).
   Em listas: `data-k="{{k}}.t"` (onde `k` = `"serv.1"`).
4. Espaço de imagem: `<div class="rk-foto" data-img="{chave}" style="--ar:4/3">{{{…html}}}</div>`.
   Imagem de fundo: `<div class="rk-fundo" data-img="{chave}" data-fundo>{{{…html}}}</div>`.
5. Ícone: `<span class="rk-ic" data-ic="{{k}}">{{{ic.svg}}}</span>`.
6. Container de lista variável: `data-li="{lista}"` no elemento pai; cada item: `data-it="{{id}}"`.
7. **Triplo bigode `{{{ }}}` só para** `….html` e `….svg` (gerados pelo preparo, já seguros). Teste de lint confere.
8. **Nunca** usar texto como condição de seção (`{{#c.hero.texto}}`): "0" é falso no PHP e verdadeiro no JS.
   Condições só com os booleanos preparados (`tem`, `vazio`, `temLogo`, …).
9. Botões de WhatsApp: `href="{{d.whatsappLink}}" data-ev="whatsapp" data-pos="{tipo}" target="_blank" rel="noopener"`.
   Telefone: `href="{{d.telefoneLink}}" data-ev="telefone"`.
10. Títulos: só o hero usa `<h1>`; demais seções `<h2>`; itens `<h3>`.
11. Formulário: usar a parcial `{{> formulario}}` (contato e hero-formulário).
12. Ícones decorativos fixos (seta, check, estrela, pin…) vêm de `u.*` (ícones utilitários, §5.3), nunca SVG colado.

### 3.4 CSS de seção (`estilo.css`)

- Seletores começam com `.rk-sec--{tipo}` ou `.rk-op--{tipo}-{opcao}`; classes internas prefixadas pelo tipo
  (`.serv-card`, `.hero-selo`). Nunca estilizar elementos soltos (`h2 {}`) fora desse escopo.
- Responsivo **só** com `@container site (max-width: 1060px)` e `@container site (max-width: 760px)`.
  O gerador converte para `@media` na publicação `[M16]`.
- Sem cores literais (hex/rgb/hsl) — só `var(--…)`. Exceção: `transparent`, `currentColor`.
- Acabamento muda detalhes via `.k-classico …`, `.k-moderno …`, `.k-direto …` como ancestral.

### 3.5 Modelos (`biblioteca/modelos/{id}.json`)

```json
{ "id": "moderno", "nome": "Moderno", "descricao": "Cantos arredondados, cards flutuando sobre as fotos e grade de serviços em blocos.",
  "personalidade": "Estilo Framer…", "acabamento": "moderno",
  "secoes": [ ["header","simples"], ["hero","cards-flutuantes"], ["clientes","faixa",{"so":["empresas"]}],
              ["diferenciais","faixa-icones"], ["servicos","blocos"], ["sobre","foto-numeros"],
              ["equipe","fotos-nome",{"so":["advocacia"]}], ["passos","linha-tempo"],
              ["depoimentos","destaque-foto",{"exceto":["advocacia"]}], ["faq","centralizada"],
              ["cta","caixa-clara"], ["contato","mapa"], ["rodape","completo"] ] }
```

Receitas (do PDF, §4.3): Clássico, Moderno, Direto. Filtros `so` / `exceto` por nicho.

**Modelos exclusivos de nicho**: além dos 3 gerais, cada nicho tem 4 modelos próprios (16 no total), com
`"nichos": ["advocacia"]` (só aparecem e só valem para esses nichos), `"ordem"` (posição na galeria: os
exclusivos 1–4 primeiro, os gerais 10–12 depois) e `"padrao": { "cor", "fonte" }` (cor e fonte iniciais
quando o nicho não tem `padroesPorModelo` para o modelo). O acabamento pode ser qualquer um dos 6.

| nicho | modelos exclusivos (acabamento) |
|---|---|
| advocacia | `tribuna` (elegante) · `boutique` (moderno) · `retrato` (clássico) · `institucional` (impacto) |
| financas | `tradicao` (clássico) · `patrimonio` (elegante) · `digital` (moderno) · `pratico` (impacto) |
| empresas | `agencia` (impacto) · `industrial` (clássico) · `leve` (suave) · `executivo` (moderno) |
| clinicas | `acolher` (suave) · `essencia` (elegante) · `vital` (moderno) · `agenda` (impacto) |

### 3.6 Pacote de nicho (`biblioteca/nichos/{id}.json`)

```jsonc
{
  "id": "clinicas", "nome": "Clínicas", "descricao": "Odontologia, saúde e estética", "ordem": 4,
  "especialidades": [                                     // [M7]
    { "id": "odontologia", "nome": "Odontologia", "segmento": "Dentista", "schemaOrg": "Dentist",
      "conselho": "CRO", "registroObrigatorio": true, "rotuloRegistro": "CRO",
      "iconesCategoria": "saude" },
    { "id": "medicina", "nome": "Clínica médica", "segmento": "Clínica médica", "schemaOrg": "MedicalClinic",
      "conselho": "CRM", "registroObrigatorio": true, "rotuloRegistro": "CRM" }
  ],
  "exemplo": { "nome": "Clínica Sorriso Vivo", "cidade": "Jundiaí", "uf": "SP", "whatsapp": "(11) 98765-4321" },
  "padroesPorModelo": { "classico": { "cor": "#2a7f86", "fonte": "amigavel" }, "moderno": {…}, "direto": {…} },
  "menu": { "servicos": "Tratamentos" },
  "mensagemWhatsapp": "Olá! Vi o site e gostaria de agendar uma avaliação.",
  "iconesPadrao": { "dif": ["heart", "x-ray", "clock"], "serv": ["tooth", "braces", "implant", "sparkle"] },
                                                          // por posição; repete ciclicamente
  "listas": { "serv": ["1","2","3","4"], "faq": ["1","2","3","4"] },   // ordem padrão; demais listas: ver comum
  "textos": { "hero.titulo": "Cuidado com o seu sorriso, do primeiro atendimento ao resultado", "serv.1.t": "Ortodontia", … },
  "conformidade": { "conselho": "CFO/CFM", "aviso": "…", "termosProibidos": ["garantido", "sem dor", …] }
}
```

`comum.json`: `{ "textos": {…}, "listas": { "dif": ["1","2","3"], … }, "novoItem": { "serv": { "t": "Novo serviço", "d": "…" }, … } }`.
Nichos iniciais: `advocacia`, `financas`, `empresas`, `clinicas`. ~90 textos cada, seguindo o cap. 11 (OAB 205/2021,
CFO/CFM, CRC, CREA): sem promessa de resultado; advocacia sem depoimentos.

### 3.7 Ícones (`biblioteca/icones/icones.json`)

```jsonc
{ "versao": 1,
  "icones": [   // ORDEM IMPORTA: do mais específico ao mais genérico (desempate do algoritmo)
    { "id": "tooth", "nome": "Dente", "categoria": "saude", "palavras": ["dente","odontologia","dentista"],
      "svg": { "fino": "<svg …>", "duotone": "<svg …>", "preenchido": "<svg …>" } }
  ],
  "utilitarios": { "seta": {…mesmo formato…}, "check": {…}, "estrela": {…}, "pin": {…}, "relogio": {…},
                   "telefone": {…}, "email": {…}, "whatsapp": {…}, "instagram": {…}, "facebook": {…},
                   "linkedin": {…}, "youtube": {…}, "google": {…}, "mais": {…}, "menos": {…}, "aspas": {…},
                   "mapa": {…}, "menu": {…}, "fechar": {…}, "calendario": {…}, "circulo": {…} } }
```

- `palavras` já normalizadas (minúsculas, sem acento). Peso por acabamento: Clássico → `fino` (Phosphor *light*),
  Moderno → `duotone`, Direto → `preenchido` (*fill*). Healthicons: `fino` = outline, `preenchido` = filled,
  `duotone` = outline (risco aceito no PDF).
- SVG normalizado: sem `width/height`, com `viewBox`, `fill="currentColor"` (ou `stroke="currentColor"`),
  `aria-hidden="true" focusable="false"`, sem `<title>`, sem atributos `on*`.
- Gerado por `ferramentas/construir-icones.mjs` a partir de `@phosphor-icons/core` e `healthicons` (MIT).

### 3.8 Fontes (`biblioteca/fontes/fontes.json`)

```json
{ "pares": {
  "classica":  { "nome": "Clássica",  "titulos": "Libre Caslon Text", "texto": "Source Sans 3", "descricao": "Serifada tradicional" },
  "editorial": { "nome": "Editorial", "titulos": "DM Serif Display",  "texto": "DM Sans",       "descricao": "Serifa marcante" },
  "moderna":   { "nome": "Moderna",   "titulos": "Manrope",           "texto": "Manrope",       "descricao": "Sem serifa, firme" },
  "amigavel":  { "nome": "Amigável",  "titulos": "Nunito",            "texto": "Nunito",        "descricao": "Arredondada e leve" },
  "nobre":     { "nome": "Nobre",     "titulos": "Cormorant Garamond", "texto": "Mulish",       "descricao": "Serifa fina e elegante" },
  "clara":     { "nome": "Clara",     "titulos": "Plus Jakarta Sans", "texto": "Plus Jakarta Sans", "descricao": "Limpa e acolhedora" },
  "geometrica": { "nome": "Geométrica", "titulos": "Sora",            "texto": "Sora",          "descricao": "Larga e tecnológica" } },
  "arquivos": [ { "familia": "Manrope", "arquivo": "manrope-latin-wght-normal.woff2", "peso": "200 800", "estilo": "normal" } ] }
```

Só subconjunto `latin`. Classe `.f-{par}` no elemento raiz define `--ft` e `--fb`.

---

## 4. Tokens CSS e `base.css`

### 4.1 Paleta (gerada a partir de uma cor — `compartilhado/paleta.mjs` ≡ `Preparo/Paleta.php`)

Conversão hex→HSL (h em graus [0,360), s e l em [0,100]); HSL→hex com `arred(x) = floor(x + 0.5)` por canal
(**não usar** `Math.round`/`round` — paridade). Tokens:

| token | fórmula (H da cor; S, L da cor) |
|---|---|
| `--p` | a cor |
| `--on-p` | `#ffffff` ou `#14161a`, o de maior contraste WCAG com `--p` |
| `--p-ink` | L = min(L, 36) |
| `--p-dark` | L = max(L − 14, 10) |
| `--p-soft` | S = min(S, 60), L = 93 |
| `--p-soft2` | S = min(S, 50), L = 84 |
| `--p-tint` | S = min(S, 35), L = 97 |
| `--deep` | S = min(S, 40), L = 12 |
| `--ink` | S = 22, L = 13 |
| `--muted` | S = 9, L = 40 |
| `--line` | S = 16, L = 89 |
| `--p-fino` | `--p` se L ≤ 75; senão `--p-ink` (elementos finos: ícones em traço, linhas) |
| `--p-sobre-escuro` | `--p` se contraste(`--p`, `--deep`) ≥ 3; senão S = min(S,80), L = 68 `[M18]` |
| `--on-p-sobre-escuro` | `#ffffff`/`#14161a` com maior contraste sobre `--p-sobre-escuro` |

`claraDemais = L > 75` → o editor mostra o aviso do PDF §5.1.
Saída CSS: `.rk{--p:#c23b6e;--on-p:#ffffff;…}` na ordem da tabela, sem espaços.

### 4.2 Tokens de contexto (definidos em base.css pela classe de fundo)

`.rk-bg--branco`, `.rk-bg--tom`, `.rk-bg--escuro`, `.rk-bg--cor` definem:
`--bg`, `--fg` (texto), `--fg2` (texto secundário), `--linha`, `--card` (fundo de card, **oposto** ao da seção),
`--card-fg`, `--destaque` (cor de acento legível neste fundo), `--btn-bg`, `--btn-fg`.

### 4.3 Tokens de acabamento (`.k-classico | .k-moderno | .k-direto | .k-elegante | .k-suave | .k-impacto`)

`--r` (cantos de botão), `--rc` (cantos de card/foto), `--ri` (cantos da caixa de ícone), `--sh` (sombra),
`--sh-hover`. Valores do PDF §5.3 (Clássico 2px/4px; Moderno 999px/24–28px; Direto 12px/16px).
Acabamentos novos: **Elegante** (cantos 0, botões em caixa alta espaçada, fotos em arco, ícones finos),
**Suave** (pílula/34px, formas orgânicas, sombras tingidas, ícones duotone) e **Impacto** (4px/8px, títulos
maiores, sombra deslocada na cor, ícones preenchidos). Peso dos ícones: elegante → `fino`, suave → `duotone`,
impacto → `preenchido`.

### 4.4 Tokens de fonte e escala

`--ft`, `--fb`, `--ft-peso`, `--t-h1`, `--t-h2`, `--t-h3`, `--t-lead`, `--t-p`, `--t-small` (com `clamp()` e
unidades `cqi` — o gerador converte `cqi`→`vw` na publicação), `--wrap: 1180px`, `--gut`, `--sec-py`.

### 4.5 Componentes de `base.css` (vocabulário usado pelos templates)

| classe | uso |
|---|---|
| `.rk` | raiz (body publicado / contêiner da prévia). `container: site / inline-size`. Reset escopado. |
| `.rk-sec` `.rk-sec--{tipo}` `.rk-op--{tipo}-{opcao}` `.rk-bg--{fundo}` | invólucro de seção (gerado) |
| `.rk-wrap` | largura máxima + respiro lateral |
| `.rk-eyebrow` | rótulo acima do título (Clássico: traço fino antes; Moderno: pílula com ponto; Direto: quadradinho na cor) |
| `.rk-h1` `.rk-h2` `.rk-h3` `.rk-lead` `.rk-p` `.rk-small` | tipografia |
| `.rk-btn` `.rk-btn--sec` `.rk-btn--claro` `.rk-link` | botões (Clássico: caixa alta espaçada; Moderno: pílula; Direto: sombra na cor) |
| `.rk-card` | card com `--card`, `--rc`, `--sh`; hover por acabamento |
| `.rk-ic` | ícone (Clássico: traço fino; Moderno: duotone em caixa suave; Direto: preenchido em bloco de cor) |
| `.rk-foto` `.rk-foto--deco` `.rk-foto__vazio` `.rk-fundo` | fotos, enfeite atrás (moldura/bloco), vazio, foto de fundo |
| `.rk-selo` | card flutuante sobre foto |
| `.rk-avatares` `.rk-estrelas` `.rk-check` | detalhes |
| `.rk-form` `.rk-campo` `.rk-input` `.rk-hp` | formulário (`.rk-hp` = pote de mel escondido) |
| `.rk-logo` `.rk-logo__img` `.rk-logo__ini` | logo ou inicial num quadrado na cor |
| `.rk-menu` | menu do cabeçalho |
| `.rk-wa` | botão flutuante de WhatsApp |
| `.rk-mapa` | fachada do mapa (carrega o iframe só ao clicar) `[M17]` |
| `.rk-sr` `.rk-pular` | acessibilidade (somente leitor de tela; "pular para o conteúdo") `[M18]` |

`prefers-reduced-motion` desliga transições. Foco visível em todos os interativos.

---

## 5. Preparo (lógica compartilhada, PARIDADE OBRIGATÓRIA)

Arquivos JS em `public_html/editor/js/compartilhado/` e PHP em `app/Preparo/` (classes estáticas, mesmos nomes):

| JS (`*.mjs`) | PHP (`Rankly\Preparo\…`) | funções |
|---|---|---|
| `texto.mjs` | `Texto` | `semAcentos`, `normalizar` (minúsculas + sem acento + espaços colapsados), `escapeHtml`, `arred`, `codificarUri` |
| `paleta.mjs` | `Paleta` | `hexParaRgb`, `rgbParaHsl`, `hslParaHex`, `luminancia`, `contraste`, `gerarPaleta(hex)` → `{tokens, claraDemais}`, `cssPaleta(tokens)` |
| `tons.mjs` | `Tons` | `calcularFundos(tons[])` → `['branco'|'tom'|'escuro'|'cor']` |
| `icones.mjs` | `Icones` | `escolherIcone(titulo, icones)` → id\|null; `iconeDoItem(doc, lib, lista, id, posicao)` → id; `svg(lib, id, acabamento)` |
| `dados.mjs` | `Dados` | `soDigitos`, `validarWhatsapp`, `formatarTelefone`, `linkWhatsapp(numero, msg)`, `linkTelefone`, `formatarEndereco`, `formatarHorarios`, `formatarRegistro`, `inicial` |
| `textos.mjs` | `Textos` | `textoEfetivo(doc, lib, chave)`, `substituirVariaveis(txt, contexto)`, `retokenizar(txt, dados)` (só JS precisa, mas PHP tem para teste), `itensLista(doc, lib, lista)`, `novoIdItem()` (só JS), `ehPadrao(doc, chave)` |
| `documento.mjs` | `Documento` | `criarDocumento({nicho, especialidade, modelo, dados, estilo?}, lib)`, `receitaModelo(lib, modelo, nicho)`, `aplicarModelo(doc, lib, modelo)`, `migrar(doc)`, `registroCampos(lib)` → mapa chave→definição, `especialidade(doc, lib)` |
| `preparo.mjs` | `Preparo` | `prepararSite(doc, lib, opcoes)` → `{html, cssPaleta, classesRaiz, secoes:[{tipo,opcao,fundo,ancora,html}], avisos}` |

### 5.1 Escape e codificação (fonte nº 1 de divergência)

```
escapeHtml(s): & → &amp;  < → &lt;  > → &gt;  " → &quot;  ' → &#39;   (nada mais)
```
- JS: `Mustache.escape = escapeHtml` (sobrescrever o padrão, que também escapa `/`, `` ` `` e `=`).
- PHP: `new Mustache_Engine(['escape' => fn($v) => Texto::escapeHtml((string)$v), 'partials' => …])`.
- `codificarUri(s)` = comportamento de `encodeURIComponent`. PHP:
  `strtr(rawurlencode($s), ['%21'=>'!','%2A'=>'*','%27'=>"'",'%28'=>'(','%29'=>')'])`.
- `semAcentos`: **mapa explícito** de caracteres latinos acentuados (áàâãäåéèêëíìîïóòôõöúùûüçñýÿ e maiúsculas),
  nos dois lados — não depender de `Normalizer`/intl.
- `normalizar`: `semAcentos` → minúsculas (`toLowerCase`/`mb_strtolower`) → espaços colapsados → trim.

### 5.2 Fundos alternados (PDF §5.4, com o tom `cor`)

```
anterior = "branco"
para cada seção (na ordem, incluindo header e rodapé):
  tom = opção.tom
  se tom == "claro":  fundo = (anterior == "branco") ? "tom" : "branco"
  senão: fundo = { "branco":"branco", "tom-claro":"tom", "escuro":"escuro", "cor":"cor" }[tom]
  anterior = fundo
```

### 5.3 View (contexto) passado a cada template

```jsonc
{
  "c": {                                // conteúdo: grupos aninhados
    "hero": { "titulo": "…", "img": { "html": "<img …>", "vazio": false }, … },
    "serv": { "titulo": "…", "qtd": 4, "tem": true,
              "itens": [ { "id":"1", "k":"serv.1", "i":1, "primeiro":true, "ultimo":false, "par":false,
                           "ini":"",          // inicial do campo "n" sem tratamento (Dra. Beatriz → B)
                           "t":"Ortodontia", "d":"…", "img": {"html":"…","vazio":true},
                           "ic": {"id":"braces", "svg":"<svg…>"} } ],
              "p1": { …item 1… }, "p2": { … }, "p3": { … } }      // atalhos posicionais (até p9)
  },
  "d": {                                // dados derivados (já formatados)
    "nome", "cidade", "uf", "cidadeUf", "inicial",
    "whatsapp", "whatsappLink", "temWhatsapp",
    "telefone", "telefoneLink", "temTelefone", "email", "emailLink", "temEmail",
    "endereco", "enderecoLinhas": ["…","…"], "temEndereco", "mapaLink", "mapaEmbed",
    "horarios": [ {"dias":"Seg a Sex","horas":"8h às 18h"} ], "horariosTexto", "temHorarios",
    "registro", "temRegistro",
    "redes": [ {"rede":"instagram","rotulo":"Instagram","url":"…","svg":"…"} ], "temRedes",
    "logo": { "html": "…", "temLogo": true }, "ano": 2026, "segmento": "Dentista"
  },
  "s": { "tipo": "servicos", "opcao": "blocos", "fundo": "branco", "escuro": false, "indice": 4 },
  "e": { "acabamento": "moderno", "fonte": "editorial", "classico": false, "moderno": true, "direto": false },
  "u": { "seta": "<svg…>", "check": "…", "estrela": "…", … },   // ícones utilitários no peso do acabamento
  "menu": [ { "rotulo": "Tratamentos", "href": "#servicos" } ],
  "modo": { "editor": true, "publicar": false }
}
```

- Sempre presentes: todo item tem todos os campos da lista (string vazia se vazio) — evita "vazamento" de contexto.
- Imagem → `{ html, vazio }`. `html` montado por `Html::imagem` / `imagemHtml` (§5.5).
- Ícone de item: `iconeDoItem` = `icones["lista.id"]` (manual) → `escolherIcone(t)` → `nicho.iconesPadrao[lista][posição % n]`
  → `"circulo"`.
- `d.ano` vem de `opcoes.ano` (nunca do relógio dentro do preparo — paridade).

### 5.4 Invólucro de seção e página

```html
<div class="rk-sec rk-sec--{tipo} rk-op--{tipo}-{opcao} rk-bg--{fundo}" id="{ancora}" data-sec="{indice}">{html do template}</div>
```
`prepararSite` concatena os invólucros com `\n` e, se `estilo.whatsappFlutuante` e há WhatsApp, acrescenta
`<a class="rk-wa" href="…" data-ev="whatsapp" data-pos="flutuante" target="_blank" rel="noopener" aria-label="Conversar no WhatsApp">{svg}</a>`.
`classesRaiz` = `"rk k-{acabamento} f-{fonte}"`. O header recebe `id="topo"`; o primeiro conteúdo após o header
recebe o alvo do link "pular para o conteúdo" (`id` da âncora).

### 5.5 HTML de imagem (`imagemHtml` ≡ `Html::imagem`)

Entrada: `{chave, midiaId, rotulo, alt, sizes, lcp}` + `opcoes.midia[midiaId] = {largura, altura, variantes:[480,960,1600], alt, tipo:"foto"|"logo", formato:"webp"|"svg"}`
e `opcoes.urlMidia = "/api/media/{id}/{w}"` (editor) ou `"img/{id}-{w}.{ext}"` (publicação). Substituições:
`{id}`, `{w}` (largura da variante, ou `orig` para SVG) e `{ext}` (`webp`, ou `svg` quando `formato == "svg"`).
Fotos têm variantes `[480, 960, 1600]` (só as menores que o original, mais o original reduzido se < 1600);
logos têm `[160, 320, 640]`; SVG não tem variantes (`variantes: []`, usa `w = "orig"`).

**Logo** (`d.logo.html`): raster → `<img class="rk-logo__img" src="{url 320 ou maior ≤ 320}" srcset="{url320} 1x, {url640} 2x" width="{w}" height="{h}" alt="{nome}">`
(srcset só com as variantes existentes; `width/height` da variante usada em `src`); SVG → `<img class="rk-logo__img" src="{url orig}" width="{largura}" height="{altura}" alt="{nome}">`;
sem logo → `<span class="rk-logo__ini" aria-hidden="true">{inicial}</span>`.

- Com mídia: `<img src="{url(maior variante ≤ 960, ou a menor)}" srcset="{url480} 480w, {url960} 960w, {url1600} 1600w" sizes="{sizes}" width="{largura da maior variante}" height="{altura proporcional, arred}" alt="{alt}" loading="{lcp?'eager':'lazy'}" decoding="async"{lcp?' fetchpriority="high"':''}>`
  (só as variantes existentes, em ordem crescente; atributos exatamente nesta ordem).
- Sem mídia, modo editor: `<span class="rk-foto__vazio">{rotulo} · enviar</span>`.
- Sem mídia, modo publicar: `<span class="rk-foto__vazio" aria-hidden="true"></span>`.
- `alt` padrão `[M18]`: item de lista → texto `t`/`n` do item; escalar → `rotulo` do campo; + `" — {nome}"`.
  `midia.alt` (texto alternativo salvo) tem prioridade.
- `lcp` = true para as imagens da primeira seção após o header.
- Editor pode passar `opcoes.midia[id].local = "blob:…"` → `src` local, sem `srcset` (prévia durante upload; fora da paridade).

### 5.6 Menu

Seções presentes cujo manifesto tem `menu` (não nulo), na ordem da página, rótulo de `nicho.menu[tipo]` ?? `manifest.menu`,
`href = "#" + ancora`, no máximo 5.

### 5.7 Opções de `prepararSite`

```
{ modo: "editor" | "publicar", urlMidia: string, midia: {id: {...}}, ano: number }
```

---

## 6. API (PHP) — `/api/*`, JSON

Convenções: respostas JSON UTF-8; erro = `{"erro":{"codigo":"…","mensagem":"texto em português pronto para o usuário"}}`.
Sessão por cookie `rk_sessao` (HttpOnly, Secure em produção, SameSite=Lax). Toda rota que altera dados exige o
cabeçalho `X-CSRF-Token` (exceto login, esqueci, redefinir e leads públicos). Datas em ISO 8601 UTC.

| Método e rota | Corpo / resposta | Fase |
|---|---|---|
| `POST /api/auth/login` | `{email, senha}` → `{usuario, csrf}`; 429 após 5 falhas/15 min por e-mail+IP | MVP |
| `POST /api/auth/logout` | → `{ok:true}` | MVP |
| `GET /api/auth/eu` | → `{usuario, csrf}` ou 401 | MVP |
| `POST /api/auth/esqueci` | `{email}` → sempre `{ok:true}`; envia link (token 1 h) `[M27]` | MVP |
| `POST /api/auth/redefinir` | `{token, senha}` → `{ok:true}` | MVP |
| `GET /api/biblioteca` | → bundle (§6.1), com `ETag` | MVP |
| `GET /api/fontes/{arquivo}` | woff2 com cache longo | MVP |
| `GET /api/sites` | → `{sites:[{id,slug,nome,nicho,modelo,status,atualizadoEm,publicadoEm,url,leadsNaoLidos}]}` `[M9]` | MVP |
| `POST /api/sites` | `{nicho, especialidade, modelo, dados, estilo?}` → 201 `{site}` | MVP |
| `GET /api/sites/{id}` | → `{site:{id,slug,status,revisao,documento,url,publicadoVersao,publicadoEm}, midia:{id:{…}}}` | MVP |
| `PUT /api/sites/{id}` | `{revisao, documento}` → `{revisao}`; 409 `{erro, revisaoAtual, documento}` | MVP |
| `DELETE /api/sites/{id}` | arquiva (status `arquivado`) | MVP |
| `POST /api/sites/{id}/duplicar` | → 201 `{site}` | MVP |
| `POST /api/sites/{id}/validar` | → `{erros:[{codigo,mensagem,chave?,secao?}], avisos:[…], textosPadraoAlterados:[{chave,antes,depois}]}` `[M5]` | MVP |
| `POST /api/sites/{id}/publicar` | → `{url, versao}`; 422 `{erro, erros:[…]}` | MVP |
| `POST /api/sites/{id}/reverter` | volta o link para a publicação anterior → `{url, versao}` `[M10]` | MVP |
| `GET /api/sites/{id}/versoes` | → `{versoes:[{numero, publicadoEm, publicadoPor}]}` | MVP |
| `GET /api/sites/{id}/versoes/{n}` | → `{documento}` (o editor aplica como alteração desfazível) | MVP |
| `GET /api/sites/{id}/leads?pagina=1` | → `{leads:[…], total, pagina}` | MVP |
| `GET /api/sites/{id}/leads.csv` | CSV (UTF-8 com BOM, `;`) | MVP |
| `PATCH /api/leads/{id}` | `{lido: bool}` | MVP |
| `DELETE /api/leads/{id}` | exclusão (LGPD) | MVP |
| `POST /api/media` | multipart `arquivo`, `site_id`, `tipo` (foto\|logo) → 201 `{midia:{id,largura,altura,variantes,alt,tipo}}` | MVP |
| `PATCH /api/media/{id}` | `{alt}` | MVP |
| `GET /api/media/{id}/{w}` | imagem da variante (sessão obrigatória; `w` = 480\|960\|1600\|orig) | MVP |
| `POST /api/lead/{slug}` e `POST /_lead` (no host do site) | público; ver §8 | MVP |

### 6.0b Fotos de exemplo e banco de imagens

- `biblioteca/fotos/` — fotos de exemplo (WebP 480/960/1600) + `fotos.json`
  (`{fotos: {id: {largura, altura, variantes, alt, fonte}}, nichos: {nicho: {cena, gente_f, gente_m}}}`),
  geradas por `ferramentas/construir-fotos.php`. Entram no bundle como `fotos`.
- `Fotos::imagensDeExemplo(doc, lib)` (`compartilhado/fotos.mjs` ≡ `Preparo/Fotos.php`, com paridade): chave →
  foto para os espaços vazios, na ordem das seções; equipe pelo gênero do nome; destaque `retrato` e
  chamada `pessoa` usam a mesma pessoa do 1º/2º da equipe.
- `POST /api/sites` já preenche os espaços (as fotos viram mídias do site, `origem = "exemplo:{id}"`,
  hard link quando possível); `"fotosExemplo": false` desliga. `POST /api/sites/{id}/fotos-exemplo {revisao}`
  completa os espaços vazios (troca de modelo). `GET /api/fotos-exemplo/{id}-{w}.webp` serve as prévias do assistente.
- Banco de imagens (Pixabay, `Lib/Pixabay.php`): `GET /api/banco-imagens` (`{disponivel}`),
  `GET /api/banco-imagens/buscar?q=&pagina=&orientacao=` (respostas em cache por 24 h em `var/cache/pixabay`),
  `POST /api/banco-imagens/importar {site_id, foto_id}` → mídia com `origem = "pixabay:{id}"` e `credito`.
  Download só de `pixabay.com`/`cdn.pixabay.com` (redirecionamentos conferidos); CSP do editor libera esses hosts em `img-src`.
- Validação da publicação: aviso `fotos_exemplo` com as fotos de exemplo ainda exibidas.

### 6.1 Bundle da biblioteca

```jsonc
{ "versao": "sha1 dos arquivos",
  "secoes": { "servicos": { "manifest": {…}, "templates": { "cards": "…mustache…" }, "css": "…" } },
  "parciais": { "formulario": "…" },
  "baseCss": "…", "modelos": { "moderno": {…} }, "nichos": { "clinicas": {…} }, "comum": {…},
  "icones": {…icones.json…}, "fontes": {…fontes.json…} }
```
`Rankly\Preparo\Biblioteca::carregar(string $dir): array` monta exatamente esta estrutura (o gerador e a API usam a
mesma). Em JS a biblioteca é o próprio JSON do bundle.

### 6.2 Banco de dados

MySQL 8 / MariaDB 10.6+ (`utf8mb4`) em produção; SQLite nos testes. SQL portátil (sem funções de data do banco —
o PHP passa as datas `Y-m-d H:i:s` UTC; sem upsert específico). Esquemas em `app/sql/mysql/001_inicial.sql` e
`app/sql/sqlite/001_inicial.sql`; `app/cli/migrar.php` aplica os pendentes (tabela `migracoes`).

| tabela | colunas |
|---|---|
| `usuarios` | id, nome, email (único), senha_hash, papel (admin\|equipe\|cliente), ativo, totp_segredo (null), criado_em, ultimo_login_em |
| `redefinicoes_senha` | id, usuario_id, token_hash, expira_em, usado_em |
| `sites` | id, dono_id, slug (único), nome, nicho, modelo, documento (JSON), revisao, status (rascunho\|publicado\|arquivado), publicado_versao, publicado_em, criado_em, atualizado_em, atualizado_por |
| `site_acessos` | site_id, usuario_id, papel (dono\|editor) — fase 2 |
| `versoes` | id, site_id, numero, documento (JSON), resolvidos (JSON: textos padrão efetivos), biblioteca_versao, release, publicado_por, publicado_em |
| `midia` | id (`m_` + 8 hex), site_id, tipo (foto\|logo), mime, largura, altura, bytes, variantes (JSON), hash (sha256), texto_alt, criado_em |
| `dominios` | id, site_id, dominio (único), status (pendente\|verificado\|ativo), ssl_ate, criado_em, verificado_em — fase 2 |
| `leads` | id, site_id, nome, telefone, email, mensagem, origem (JSON: utm_*, gclid, gbraid, wbraid, pagina, referencia) `[M22]`, ip_hash, criado_em, lido_em |
| `tarefas` | id, tipo, payload (JSON), status (pendente\|executando\|feita\|falhou), tentativas, executar_em, erro, criado_em, atualizado_em `[M14]` |
| `eventos` | id, site_id, usuario_id, tipo, detalhe (JSON), criado_em (registro de quem mudou o quê) `[M27]` |
| `limites` | chave (PK), contagem, janela_inicio (limite de tentativas) |
| `migracoes` | nome (PK), aplicada_em |

---

## 7. Gerador e publicação

Etapas (`Rankly\Gerador\Gerador::publicar(int $siteId, int $usuarioId)`):

1. **Validar** (`Validador`): esquema; WhatsApp válido (10/11 dígitos, DDD válido); `header` primeiro e `rodape` último;
   seções sem duplicata; opções existentes; nicho regulado (`especialidade.registroObrigatorio`) sem `dados.registro.numero`
   → erro `[M6]`; alegações padrão não confirmadas (§2.4) → erro; nome vazio → erro. Avisos: fotos faltando, cor clara
   demais, textos padrão alterados desde a última publicação `[M5]`, termos de conformidade (`nicho.conformidade.termosProibidos`).
2. **Preparar e renderizar** com `Preparo::prepararSite(doc, lib, {modo:"publicar", urlMidia:"img/{id}-{w}.webp", …})`;
   remove `data-k|data-img|data-ic|data-li|data-it|data-sec|data-fundo` do HTML (mantém `data-ev`, `data-pos`, `data-form`).
3. **CSS**: `base.css` + `estilo.css` só dos tipos usados + `cssPaleta` + `@font-face` do par escolhido (`fontes/…`) +
   regras de fonte de reserva com `size-adjust`/`ascent-override` `[M16]`; converte `@container site (…)` → `@media (…)`
   e `cqi` → `vw` `[M16]`; minifica. ≤ 30 KB → `<style>` embutido; senão `assets/site.{hash8}.css`.
4. **Fontes**: copia só os `.woff2` do par; `<link rel="preload">` da fonte de títulos.
5. **Imagens**: copia as variantes usadas para `img/{id}-{w}.webp` (cache 1 ano) `[M12]`.
6. **JS mínimo embutido** (`ScriptSite`, ~2–3 KB): envio do formulário via `fetch('/_lead')` (sem JS: post normal +
   303 para `/obrigado/`), `_t` (tempo de preenchimento), captura de `utm_*`/`gclid`/`gbraid`/`wbraid` em
   `sessionStorage` → campos ocultos `[M22]`, eventos `dataLayer` (`rankly_whatsapp_click` com `posicao`,
   `rankly_form_submit`, `rankly_phone_click`) usando `navigator.sendBeacon`-friendly `eventCallback`/timeout,
   fachada do mapa (carrega o iframe ao clicar) `[M17]`, faixa de consentimento (só se houver rastreamento; modo de
   consentimento do Google com padrão `denied`) `[M23]`.
7. **SEO**: `<title>` (`seo.titulo` ?? `"{nome} · {segmento} em {cidade}"`), meta description (`seo.descricao` ?? texto do
   hero cortado em ~155 sem quebrar palavra), canonical, Open Graph (foto do hero 1600), JSON-LD com
   `especialidade.schemaOrg`, nome, url, telefone, endereço (`PostalAddress`), `openingHoursSpecification`, `sameAs`
   (redes), `FAQPage` se houver perguntas. **Sem `AggregateRating`** (autodeclarado). `lang="pt-BR"`, `sitemap.xml`,
   `robots.txt`, favicon (SVG com a inicial na cor, ou PNG 32/180 gerado do logo).
8. **Páginas extras** `[M24]`: `/privacidade/` (política gerada: dono, formulário, retenção de 12 meses, rastreadores
   se houver, contato do controlador), `/obrigado/` (URL de conversão), `404.html`. Mesmo cabeçalho/rodapé.
9. **CSP** por `<meta http-equiv>` com hash `sha256` dos scripts embutidos (HTML estático não aceita nonce) `[M26]`;
   GTM/GA4/Pixel liberados só se configurados.
10. **Gravar com troca atômica** `[M10]`: escreve em `sites/.releases/{slug}/{versao}-{token}/`, cria link temporário e
    `rename()` sobre `sites/{slug}` (atômico). Mantém as 5 últimas releases. `reverter` aponta o link para a anterior.
11. **Versionar**: linha em `versoes` (documento, `resolvidos`, `biblioteca_versao`, release); `sites.status = publicado`.

URL: `https://{slug}.{config.dominio_sites}` (dev: `http://{slug}.localhost:8081`).

### 7.1 `sites/.htaccess` (curinga) `[M11]`

```apache
Options -Indexes +SymLinksIfOwnerMatch
RewriteEngine On
# só na primeira passada (evita loop: depois da reescrita as regras rodam de novo)
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteRule (^|/)\. - [F]
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteRule ^_lead$ _lead.php [L]
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteCond %{HTTP_HOST} ^([a-z0-9-]+)\.SEU-DOMINIO\.com\.br$ [NC]
RewriteCond %{DOCUMENT_ROOT}/%1 -d
RewriteRule ^(.*)$ /%1/$1 [L]
# domínio próprio (fase 2): roteador PHP
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteRule ^ _roteador.php [L]
```
Cabeçalhos: cache longo para `img/`, `fontes/`, `assets/`; `ErrorDocument 404` por site via roteador.

---

## 8. Leads `[M19][M20][M21][M22]`

- Endpoint **na mesma origem** do site: `POST /_lead` (reescrito para `sites/_lead.php`, que identifica o site pelo host:
  subdomínio → slug; senão tabela `dominios`). `POST /api/lead/{slug}` continua existindo.
- Campos: `nome` (obrigatório, ≤ 80), `telefone` (obrigatório, 10–11 dígitos), `email` (opcional), `mensagem` (≤ 1000),
  pote de mel `empresa_site` (preenchido → descarta em silêncio com sucesso falso), `_t` (ms desde o carregamento;
  presente e < 3000 → descarta), `utm_source|utm_medium|utm_campaign|utm_term|utm_content|gclid|gbraid|wbraid|pagina|referencia`.
- Limite: 5 envios por IP por hora (`limites`), IP identificado por `HMAC-SHA256(ip, config.segredo_ip)` (nunca o IP puro).
  Atrás do Cloudflare usar `CF-Connecting-IP` só se `config.confiar_cloudflare`.
- Grava o lead, enfileira tarefa `email_lead` (e `notificar_lead` se configurado — extra) e responde
  `{ok:true}` (fetch) ou `303 Location: /obrigado/` (formulário sem JS).
- E-mail ao dono: assunto "Novo contato pelo site: {nome}", corpo texto + link "Chamar no WhatsApp"; `Reply-To` = e-mail do
  visitante se houver. SMTP próprio mínimo (`Lib/Email/Smtp.php`, STARTTLS/SSL, AUTH LOGIN).
- Retenção: tarefa diária exclui leads com mais de `config.retencao_leads_meses` (12).

---

## 9. Editor (SPA em JS puro)

`public_html/editor/index.html` carrega `js/app.mjs` (módulo). Rotas por hash:

| rota | módulo | tela |
|---|---|---|
| `#/entrar`, `#/redefinir?token=` | `login.mjs` | login, esqueci a senha, redefinir |
| `#/sites` | `sites.mjs` | painel: lista de sites, "Novo site", ações (editar, ver, leads, duplicar, arquivar) |
| `#/novo` → `#/novo/modelo` → `#/novo/dados` | `assistente.mjs` | assistente (3 passos), com "Continuar de onde parou" |
| `#/site/{id}/modelo` | `assistente.mjs` | trocar modelo de site existente ("Em uso") |
| `#/site/{id}` | `editor/editor.mjs` | editor |
| `#/site/{id}/leads` | `leads.mjs` | contatos recebidos |

Módulos de infraestrutura (contratos usados por todos):

- `api.mjs`: `api.get(p)`, `api.post(p, corpo)`, `api.put`, `api.patch`, `api.del`, `api.enviarArquivo(p, formData, aoProgresso)`
  (XHR). Lança `ErroApi {status, codigo, mensagem, dados}`. Guarda o CSRF recebido em `/auth/eu` e `/auth/login`.
- `biblioteca.mjs`: `carregarBiblioteca()` (uma vez) e `injetarCssSite(lib)` → `<style id="rk-css-site">` com `baseCss` +
  CSS de todas as seções + `@font-face` apontando para `/api/fontes/{arquivo}`.
- `previa.mjs`: `renderizarPrevia(alvo, doc, lib, {midia, modo:"editor"})` — escreve o HTML do `prepararSite` dentro de
  `alvo` (`.rk` + classes raiz + `<style>` da paleta); `escalar(moldura, alvo, larguraSite)` aplica `zoom`.
- `ui.mjs`: `el(tag, attrs, ...filhos)`, `aviso(msg, {acao:{rotulo, fn}, duracao})` (toast com `role="status"`),
  `modal({titulo, corpo, acoes, aoFechar})` (prende o foco, Esc fecha e devolve o foco), `confirmar(msg)`.
- `estado.mjs`: `class EstadoEditor` — `doc`, `revisao`, `midia`; `aplicar(fn, {rotulo, historico=true})` (fn recebe uma cópia
  e devolve o novo doc), `desfazer()`, `refazer()`, `podeDesfazer`, `podeRefazer`, `assinar(fn)`; histórico de 80 passos;
  salvamento automático (1,5 s sem mudanças → `PUT`), status `"salvando"|"salvo"|"offline"|"conflito"|"erro"`,
  cópia no IndexedDB quando sem conexão + nova tentativa a cada 10 s, `beforeunload` com alteração pendente,
  `aoConflito(cb)` para o modal "Carregar a versão mais nova" / "Manter a minha".

Comportamento do editor: PDF cap. 7 + melhorias:
- `contenteditable="plaintext-only"` e leitura por `textContent` `[M3]`; Enter encerra (campos curtos); colar = texto puro;
  contador a partir de 80% do limite; bloqueio no limite (colagem é cortada com aviso); retokenização `[M4]`.
- Ícone automático muda enquanto digita (atualiza só o `<span data-ic>`).
- Listas variáveis: botões "Adicionar item" / "Remover item" dentro da seção (respeitando `repete`).
- Fotos: canvas reduz para 2400 px (JPEG 85%); envio com barra de progresso; prévia local enquanto sobe.
- Painel com abas **Seções**, **Estilo**, **Dados**, **Config.** (SEO, rastreamento GTM/GA4/Pixel).
- Publicar: `POST /validar` → modal de checklist (erros bloqueiam; confirmar alegações; avisos; textos padrão alterados
  com antes/depois) → `POST /publicar` → modal com o link; "Voltar à publicação anterior".

---

## 10. Testes

| comando | o que cobre |
|---|---|
| `npm test` | `tests/js/*.test.mjs` (node:test): paleta, tons, ícones, textos/listas/retokenização, documento, preparo, lint de templates/CSS |
| `vendor/bin/phpunit -c tests/php/phpunit.xml` | PHP: Preparo (unidade), API com SQLite, imagens, leads, gerador/publicador (pasta temporária) |
| `npm run paridade` | renderiza 4 nichos × 3 modelos × variações (acabamento/fonte/cor/listas) em JS e PHP e compara byte a byte `[M8]` |
| `npm run e2e` | Playwright: entrar → assistente → editar → publicar → enviar formulário → lead no painel |

`bin/testar.sh` roda tudo. `bin/dev.sh` sobe `php -S localhost:8080` (editor/API) e `php -S localhost:8081` (sites).

---

## 11. Interfaces entre módulos (assinaturas fixas)

### 11.1 PHP — núcleo (`app/`, dono: backend)

```php
namespace Rankly;
final class Aplicacao {
    public static function iniciar(?array $config = null): self;     // null → carrega config/config.php
    public function config(string $chave, mixed $padrao = null): mixed; // chaves com ponto: 'db.dsn', 'smtp.host'
    public function raiz(): string;                                    // raiz do projeto
    public function dir(string $nome): string;                         // 'sites' | 'media' | 'var' | 'biblioteca' (absoluto, sem / final)
    public function db(): Lib\Db;
    public function biblioteca(): array;                               // Preparo\Biblioteca::carregar(dir('biblioteca')), cache em memória
    public function agora(): \DateTimeImmutable;                       // UTC; testes podem fixar com definirAgora()
    public function definirAgora(?\DateTimeImmutable $t): void;
    public function tarefas(): Lib\Tarefas;
    public function log(): Lib\Log;
    public function urlSite(string $slug): string;                     // protocolo_sites://{slug}.{dominio_sites}
}

namespace Rankly\Lib;
final class Db {
    public static function conectar(array $cfg): self;                 // cfg = config('db')
    public function pdo(): \PDO;
    public function driver(): string;                                  // 'mysql' | 'sqlite'
    public function um(string $sql, array $p = []): ?array;
    public function todos(string $sql, array $p = []): array;
    public function valor(string $sql, array $p = []): mixed;
    public function executar(string $sql, array $p = []): int;
    public function inserir(string $tabela, array $dados): int;
    public function atualizar(string $tabela, array $dados, array $onde): int;
    public function transacao(callable $fn): mixed;
}
final class Tarefas {
    public function enfileirar(string $tipo, array $payload = [], ?\DateTimeImmutable $quando = null): int;
    public function registrar(string $tipo, callable $executor): void;  // executor(array $payload, Aplicacao $app): void
    public function processar(int $limite = 20): int;                   // usado por app/cli/cron.php
}
final class Midia {                                                     // arquivos em media/{site_id}/{id}/
    public static function caminho(\Rankly\Aplicacao $app, int $siteId, string $id, string $arquivo): string;
    // arquivos: "orig.jpg" | "orig.png" | "orig.svg" | "{w}.webp"
    public static function mapaDoSite(\Rankly\Aplicacao $app, int $siteId): array; // id => {largura,altura,variantes,alt,tipo,formato}
}
final class Sites {
    public static function porId(\Rankly\Aplicacao $app, int $id): ?array;      // linha com 'documento' decodificado (array)
    public static function porHost(\Rankly\Aplicacao $app, string $host): ?array; // subdomínio de dominio_sites ou tabela dominios
}
final class Leads {
    /** @return array{ok: bool, ignorado?: bool, erro?: string, mensagem?: string, status?: int} */
    public static function receber(\Rankly\Aplicacao $app, array $site, array $campos, string $ip, string $userAgent = ''): array;
}
```

Tabela `midia` também tem a coluna `formato` (`webp` | `svg`).

### 11.2 PHP — gerador (`app/Gerador/`, dono: gerador)

```php
namespace Rankly\Gerador;
final class ErroValidacao extends \RuntimeException { public function __construct(public array $erros) {} }
final class Gerador {
    public function __construct(\Rankly\Aplicacao $app);
    /** @return array{erros: list<array>, avisos: list<array>, textosPadraoAlterados: list<array>} */
    public function validar(array $site): array;                   // $site = Lib\Sites::porId(...)
    /** @return array{url: string, versao: int} @throws ErroValidacao */
    public function publicar(array $site, int $usuarioId): array;
    /** @return array{url: string, versao: int} */
    public function reverter(array $site, int $usuarioId): array;
}
```

### 11.3 Configuração (`config/config.exemplo.php` devolve um array)

`ambiente` (dev|prod), `url_editor`, `dominio_sites`, `protocolo_sites`, `db` {driver, dsn, usuario, senha},
`dir_sites`, `dir_media`, `dir_var` (cache, logs, sessões, e-mails de dev), `segredo_app`, `segredo_ip`,
`smtp` {host, porta, seguranca (tls|ssl|nenhuma), usuario, senha, remetente, nome_remetente},
`email_modo` (smtp | arquivo — em dev grava `.eml` em `var/emails/`), `retencao_leads_meses` (12),
`confiar_cloudflare` (false), `releases_mantidas` (5), `limite_upload_mb` (15), `max_megapixels` (40).

### 11.4 JS — telas do editor

Cada módulo de tela exporta `export async function montar(alvo, params) → { desmontar?() }`.
`app.mjs` faz o roteamento por hash e chama `montar`. O editor é `editor/editor.mjs` com
`montar(alvo, { siteId })`.
