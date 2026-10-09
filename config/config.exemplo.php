<?php

/**
 * Configuração do Construtor Rankly (contrato §11.3).
 *
 * Copie este arquivo para config/config.php (que fica fora do git) e ajuste os valores.
 * Também é possível apontar outro arquivo pela variável de ambiente RANKLY_CONFIG.
 * Chaves ausentes usam os padrões de app/Lib/Config.php.
 */

return [
    // "dev" mostra detalhes de erro na API e grava e-mails em arquivo; "prod" esconde detalhes
    // e marca o cookie de sessão como Secure (exige HTTPS).
    'ambiente' => 'dev',

    // Endereço do editor/API, sem barra no fim. Usado nos links de e-mail (redefinir senha).
    'url_editor' => 'http://localhost:8080',

    // Domínio dos sites publicados: cada site fica em {slug}.{dominio_sites}.
    // Produção: 'sitesrankly.com.br' com 'https'. Desenvolvimento: 'localhost:8081' com 'http'.
    'dominio_sites' => 'localhost:8081',
    'protocolo_sites' => 'http',

    // Banco: MySQL 8 / MariaDB 10.6+ (utf8mb4). Em produção, use um usuário sem permissão de
    // alterar a estrutura no dia a dia (rode as migrações com um usuário administrativo).
    'db' => [
        'driver' => 'mysql',
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=rankly;charset=utf8mb4',
        'usuario' => 'rankly',
        'senha' => 'rankly',
        // Testes usam SQLite: ['driver' => 'sqlite', 'dsn' => 'sqlite:/caminho/teste.sqlite']
    ],

    // Pastas (relativas à raiz do projeto ou absolutas).
    'dir_sites' => 'sites',     // sites publicados (raiz web do curinga *.dominio_sites)
    'dir_media' => 'media',     // fotos enviadas (fora da web)
    'dir_var' => 'var',         // cache, logs, sessões, e-mails de dev, travas

    // Segredos: gere cada um com  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // segredo_ip é a chave do HMAC-SHA256 que identifica IPs sem guardá-los (LGPD). Em produção
    // ("prod") o valor de exemplo abaixo, ou um segredo com menos de 16 caracteres, é recusado.
    'segredo_app' => 'troque-por-um-segredo-longo-e-aleatorio',
    'segredo_ip' => 'troque-por-outro-segredo-longo-e-aleatorio',

    // SMTP (Hostinger: smtp.hostinger.com, porta 465 com 'ssl' ou 587 com 'tls').
    'smtp' => [
        'host' => 'smtp.hostinger.com',
        'porta' => 465,
        'seguranca' => 'ssl',          // tls (STARTTLS) | ssl | nenhuma
        'usuario' => 'nao-responda@sitesrankly.com.br',
        'senha' => '',
        'remetente' => 'nao-responda@sitesrankly.com.br',
        'nome_remetente' => 'Sites Rankly',
        'tempo_limite' => 15,          // segundos por operação
    ],

    // "smtp" envia de verdade; "arquivo" grava cada e-mail em var/emails/*.eml (desenvolvimento).
    'email_modo' => 'arquivo',

    // Opcional: endereço que recebe uma cópia de todo e-mail de lead (ex.: atendimento da agência).
    'email_leads_copia' => '',

    // Leads com mais de N meses são excluídos pela limpeza diária (LGPD).
    'retencao_leads_meses' => 12,

    // true só se o servidor estiver atrás da Cloudflare: o IP do visitante vem de CF-Connecting-IP.
    'confiar_cloudflare' => false,

    // true só se um proxy na própria máquina (Caddy, Nginx) recebe as visitas e repassa o IP real em
    // X-Real-IP. O cabeçalho só é aceito quando a conexão vem de um endereço interno.
    'confiar_proxy_local' => false,

    // Quantas publicações anteriores de cada site ficam guardadas para "voltar à anterior".
    'releases_mantidas' => 5,

    // Envio de fotos: tamanho máximo do arquivo e resolução máxima (megapixels) aceita.
    // O PHP também precisa permitir: upload_max_filesize e post_max_size (ver public_html/.htaccess).
    'limite_upload_mb' => 15,
    'max_megapixels' => 40,

    // Sessão do editor: expira após N dias sem uso.
    'sessao_dias' => 7,

    // IA que escreve os textos do site a partir de uma descrição do negócio.
    // provedor: "gemini" (Google AI Studio), "simulado" (textos de teste, sem internet) ou
    // "desligado" (esconde os botões de IA). A chave é criada em https://aistudio.google.com/apikey
    // e também pode vir da variável de ambiente GEMINI_API_KEY (tem prioridade se 'chave' estiver vazia).
    // Sem chave, o provedor gemini fica indisponível e o editor esconde os botões.
    // modelo_reserva: um ou mais modelos (separados por vírgula), tentados na ordem quando o principal
    // estoura a cota gratuita, está fora do ar ou deixou de existir (o Google aposenta modelos antigos;
    // os nomes "-latest" sempre apontam para o mais novo). Lista: https://ai.google.dev/gemini-api/docs/models
    'ia' => [
        'provedor' => 'gemini',
        'chave' => '',
        'modelo' => 'gemini-3.8-flash',
        'modelo_reserva' => 'gemini-3.5-flash-lite,gemini-flash-latest',
        'tempo_limite' => 90,          // segundos por pedido
        'limite_por_hora' => 30,       // pedidos por usuário por hora
        'revisao' => true,             // 2ª rodada: a IA reescreve os textos que o revisor de copy apontar
    ],

    // Banco de imagens Pixabay: "Buscar no banco de imagens" no editor baixa a foto para o site
    // (com o crédito do autor). Chave grátis em https://pixabay.com/api/docs/ (com a conta aberta,
    // a chave aparece na seção "Parameters", item "key"); também pode vir da variável de ambiente
    // PIXABAY_API_KEY (usada se 'chave' estiver vazia). Sem chave, o editor mostra a opção com a
    // explicação de como ligar.
    'pixabay' => [
        'chave' => '',
        'limite_por_hora' => 120,      // buscas + importações por usuário por hora (o Pixabay aceita 100/min por chave)
    ],
];
