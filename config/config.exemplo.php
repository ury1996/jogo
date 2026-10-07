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
    // segredo_ip é a chave do HMAC-SHA256 que identifica IPs sem guardá-los (LGPD).
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

    // Quantas publicações anteriores de cada site ficam guardadas para "voltar à anterior".
    'releases_mantidas' => 5,

    // Envio de fotos: tamanho máximo do arquivo e resolução máxima (megapixels) aceita.
    // O PHP também precisa permitir: upload_max_filesize e post_max_size (ver public_html/.htaccess).
    'limite_upload_mb' => 15,
    'max_megapixels' => 40,

    // Sessão do editor: expira após N dias sem uso.
    'sessao_dias' => 7,
];
