<?php

/**
 * Configuração do MODO DEMONSTRAÇÃO (bin/demo.sh, Docker, GitHub Codespaces).
 *
 * Tudo num endereço só: editor em /editor/ e sites publicados em /s/{slug}/ (sites_no_caminho),
 * banco SQLite e segredos gerados na primeira vez. Serve para testar e mostrar o sistema
 * para outras pessoas; NÃO é a configuração de produção (lá os sites ficam num domínio
 * próprio, com subdomínio por site — veja config.exemplo.php).
 *
 * Variáveis de ambiente (todas opcionais):
 *   RANKLY_URL       endereço público, sem barra no fim (ex.: https://rankly-teste.onrender.com).
 *                    Num Codespace é descoberto sozinho; sem nada, http://localhost:{PORTA}.
 *                    No Render e no Railway também é descoberto sozinho.
 *   PORTA / PORT     porta do servidor (8080)
 *   RANKLY_DADOS     pasta dos dados (banco, fotos, sites publicados, logs). Padrão: var/demo
 *   GEMINI_API_KEY   chave da IA (sem chave, a IA funciona em modo "simulado")
 *   PIXABAY_API_KEY  chave do banco de imagens Pixabay (grátis em https://pixabay.com/api/docs/)
 */

$porta = (int) (getenv('PORTA') ?: getenv('PORT') ?: 8080);
$url = rtrim((string) (getenv('RANKLY_URL') ?: getenv('RENDER_EXTERNAL_URL') ?: ''), '/');
if ($url === '' && getenv('RAILWAY_PUBLIC_DOMAIN')) {
    $url = 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN');
}
if ($url === '' && getenv('CODESPACE_NAME')) {
    $dominio = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN') ?: 'app.github.dev';
    $url = 'https://' . getenv('CODESPACE_NAME') . '-' . $porta . '.' . $dominio;
}
if ($url === '') {
    $url = 'http://localhost:' . $porta;
}

$dados = rtrim((string) (getenv('RANKLY_DADOS') ?: dirname(__DIR__) . '/var/demo'), '/');
foreach (['', '/media', '/sites', '/var'] as $sub) {
    if (!is_dir($dados . $sub)) {
        @mkdir($dados . $sub, 0775, true);
    }
}

// Segredos aleatórios, criados uma vez e guardados junto com os dados.
$arqSegredos = $dados . '/segredos.json';
$segredos = is_file($arqSegredos) ? json_decode((string) file_get_contents($arqSegredos), true) : null;
if (!is_array($segredos) || !isset($segredos['app'], $segredos['ip'])) {
    $segredos = ['app' => bin2hex(random_bytes(32)), 'ip' => bin2hex(random_bytes(32))];
    @file_put_contents($arqSegredos, json_encode($segredos));
    @chmod($arqSegredos, 0600);
}

$chaveIa = (string) getenv('GEMINI_API_KEY');

return [
    'ambiente' => 'dev',
    'url_editor' => $url,
    'sites_no_caminho' => true,
    'dominio_sites' => 'localhost:' . $porta,
    'protocolo_sites' => 'http',
    'db' => [
        'driver' => 'sqlite',
        'dsn' => 'sqlite:' . $dados . '/rankly.sqlite',
    ],
    'dir_sites' => $dados . '/sites',
    'dir_media' => $dados . '/media',
    'dir_var' => $dados . '/var',
    'segredo_app' => $segredos['app'],
    'segredo_ip' => $segredos['ip'],
    'email_modo' => 'arquivo',
    'ia' => [
        'provedor' => $chaveIa !== '' ? 'gemini' : 'simulado',
        'chave' => $chaveIa,
        'modelo' => trim((string) getenv('GEMINI_MODELO')) ?: 'gemini-3.8-flash',
        'modelo_reserva' => 'gemini-3.5-flash-lite,gemini-flash-latest',
        'tempo_limite' => 90,
        'limite_por_hora' => 30,
    ],
    'pixabay' => [
        'chave' => (string) getenv('PIXABAY_API_KEY'),
        'limite_por_hora' => 120,
    ],
];
