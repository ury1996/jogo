<?php

/**
 * Instalador do Construtor Rankly para hospedagem compartilhada (ex.: Hostinger), sem SSH.
 *
 * Como usar: envie este arquivo e o rankly-hospedagem.zip para a pasta pública de um subdomínio
 * vazio (Gerenciador de Arquivos do painel) e abra https://seu-subdominio/instalar.php.
 *
 * O que faz:
 *   1. confere o servidor (versão do PHP, extensões, links simbólicos, permissão de gravação);
 *   2. pede o banco MySQL, o primeiro usuário e (opcional) as chaves da IA, do Pixabay e o e-mail;
 *   3. põe o sistema FORA da pasta pública (ao lado da public_html, onde a internet não alcança)
 *      e copia para a pasta do subdomínio só a parte web (editor, API);
 *   4. grava a configuração, cria as tabelas e o usuário e apaga o pacote e a si mesmo;
 *   5. (opcional) com um token do GitHub só de leitura, liga a atualização automática: o cron
 *      confere a cada 5 minutos se o GitHub publicou versão nova e se atualiza sozinho.
 *
 * Atualizar: envie o pacote novo e este arquivo de novo e abra a mesma página. Com o sistema
 * instalado, ele pede o e-mail e a senha de um administrador antes de mexer em qualquer coisa.
 * Configuração, fotos, sites publicados e banco são mantidos; os sites são republicados.
 */

declare(strict_types=1);

const PACOTE = 'rankly-hospedagem.zip';
const PASTA_PROJETO = 'rankly-sistema';
const PHP_MINIMO = '8.2.0';
/** Repositório de onde a hospedagem busca as versões novas (atualização automática). */
const REPO_GITHUB = 'ury1996/jogo';

error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(600);
@ini_set('memory_limit', '512M');
session_name('rk_instalador');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$web = __DIR__;
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

/* ------------------------------------------------------------------ utilidades */

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $campo): string
{
    return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
}

/** Endereço pelo qual o instalador foi aberto (esquema + host). */
function enderecoAtual(): array
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = (string) preg_replace('/[^a-z0-9.:\-]/', '', $host);
    return [$https ? 'https' : 'http', $host];
}

/**
 * Pasta do projeto: ao lado da public_html mais alta do caminho (fora da web).
 * /home/u1/domains/dominio/public_html/editor → /home/u1/domains/dominio/rankly-sistema
 */
function pastaProjetoPadrao(string $web): string
{
    $partes = explode('/', rtrim($web, '/'));
    $i = array_search('public_html', $partes, true);
    $base = $i !== false && $i > 0 ? implode('/', array_slice($partes, 0, $i)) : dirname($web);
    return $base . '/' . PASTA_PROJETO;
}

/** Projeto já instalado (pelo rankly-raiz.php desta pasta), ou null. */
function projetoInstalado(string $web): ?string
{
    $arq = $web . '/rankly-raiz.php';
    if (!is_file($arq)) {
        return null;
    }
    $raiz = (static fn (string $f): mixed => include $f)($arq);
    return is_string($raiz) && is_file($raiz . '/config/config.php') ? $raiz : null;
}

function apagarArvore(string $dir): void
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            apagarArvore($dir . '/' . $item);
        }
    }
    @rmdir($dir);
}

/** Copia uma árvore (arquivos sobrescritos; pastas criadas). */
function copiarArvore(string $de, string $para): void
{
    if (!is_dir($para) && !@mkdir($para, 0755, true)) {
        throw new RuntimeException("Não consegui criar a pasta {$para}.");
    }
    foreach (scandir($de) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $o = $de . '/' . $item;
        $d = $para . '/' . $item;
        if (is_dir($o) && !is_link($o)) {
            copiarArvore($o, $d);
        } elseif (!@copy($o, $d)) {
            throw new RuntimeException("Não consegui copiar {$item} para {$para}.");
        }
    }
}

/** Arquivos desta pasta que não são do instalador, do pacote nem de uma instalação anterior. */
function arquivosEstranhos(string $web): array
{
    $nossos = ['.', '..', basename(__FILE__), PACOTE, '.htaccess', 'default.php', '.well-known',
        'editor', 'api', 'index.php', 's.php', 'rankly-raiz.php', '.htaccess.antes-rankly', '.user.ini', 'error_log'];
    $estranhos = [];
    foreach (scandir($web) ?: [] as $item) {
        if (!in_array($item, $nossos, true)) {
            $estranhos[] = $item;
        }
    }
    // index.php que não é do Rankly também conta (outro site na pasta).
    if (is_file($web . '/index.php') && !str_contains((string) file_get_contents($web . '/index.php'), 'Location: /editor/')) {
        $estranhos[] = 'index.php';
    }
    return $estranhos;
}

/** Testa se o PHP consegue criar links simbólicos (o sistema usa para trocar a versão publicada). */
function linksSimbolicosFuncionam(string $base): bool
{
    if (!function_exists('symlink')) {
        return false;
    }
    $dir = $base . '/.rk-teste-' . bin2hex(random_bytes(3));
    if (!@mkdir($dir, 0755, true)) {
        return false;
    }
    @mkdir($dir . '/alvo');
    @file_put_contents($dir . '/alvo/x.txt', 'ok');
    $ok = @symlink('alvo', $dir . '/link') && @file_get_contents($dir . '/link/x.txt') === 'ok';
    apagarArvore($dir);
    return $ok;
}

/** Requisitos: lista de [ok, obrigatório, texto, como resolver]. */
function requisitos(string $web, string $base): array
{
    $r = [];
    $r[] = [version_compare(PHP_VERSION, PHP_MINIMO, '>='), true, 'PHP ' . PHP_VERSION . ' (precisa 8.2 ou mais novo)',
        'No painel: Avançado → Configuração do PHP → escolha a versão 8.3 (ou 8.2) e salve. Depois recarregue esta página.'];
    $r[] = [extension_loaded('gd') && function_exists('imagewebp'), true, 'Extensão GD com WebP (fotos)',
        'No painel: Configuração do PHP → Extensões do PHP → ative "gd".'];
    $r[] = [extension_loaded('pdo_mysql'), true, 'Extensão pdo_mysql (banco)', 'Na Configuração do PHP, ative "pdo_mysql" (e "pdo").'];
    $r[] = [extension_loaded('mbstring'), true, 'Extensão mbstring', 'Na Configuração do PHP, ative "mbstring".'];
    $r[] = [extension_loaded('fileinfo'), true, 'Extensão fileinfo', 'Na Configuração do PHP, ative "fileinfo".'];
    $r[] = [extension_loaded('curl'), true, 'Extensão curl (IA e banco de imagens)', 'Na Configuração do PHP, ative "curl".'];
    $r[] = [class_exists('ZipArchive'), true, 'Extensão zip (abrir o pacote)', 'Na Configuração do PHP, ative "zip".'];
    $r[] = [extension_loaded('openssl'), true, 'Extensão openssl (e-mail seguro)', 'Na Configuração do PHP, ative "openssl".'];
    $r[] = [extension_loaded('exif'), false, 'Extensão exif (fotos de celular na posição certa)', 'Opcional: na Configuração do PHP, ative "exif".'];
    $r[] = [is_writable($web), true, 'Gravar na pasta do subdomínio', 'A pasta ' . $web . ' precisa permitir gravação (permissão 755).'];
    $pai = is_dir($base) ? $base : dirname($base);
    $r[] = [is_writable($pai), true, 'Gravar fora da pasta pública (' . $pai . ')',
        'Esta hospedagem não deixa o PHP gravar fora da pasta pública. Fale com o suporte da hospedagem.'];
    $r[] = [is_writable($pai) && linksSimbolicosFuncionam($pai), true, 'Links simbólicos (troca da versão publicada dos sites)',
        'A hospedagem bloqueia a função symlink do PHP. Peça ao suporte para liberar.'];
    $r[] = [is_file($web . '/' . PACOTE), true, 'Pacote ' . PACOTE . ' nesta pasta', 'Envie o arquivo ' . PACOTE . ' para esta mesma pasta.'];
    return $r;
}

/** Testa a conexão com o MySQL; devolve null ou a mensagem de erro em português. */
function testarBanco(string $host, string $nome, string $usuario, string $senha): ?string
{
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$nome};charset=utf8mb4", $usuario, $senha,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $pdo->query('SELECT 1');
        return null;
    } catch (Throwable $e) {
        $m = $e->getMessage();
        return match (true) {
            str_contains($m, '1045') => 'Usuário ou senha do banco incorretos.',
            str_contains($m, '1049') => 'O banco "' . $nome . '" não existe. Confira o nome completo (ex.: u123456789_rankly).',
            str_contains($m, '1044') => 'O usuário não tem acesso a esse banco.',
            str_contains($m, '2002') => 'Não achei o servidor do banco "' . $host . '". Na Hostinger é "localhost".',
            default => 'Não consegui conectar ao banco: ' . $m,
        };
    }
}

/** config/config.php novo (devolve o conteúdo). */
function configNova(array $c): string
{
    $cfg = [
        'ambiente' => 'prod',
        'url_editor' => $c['url'],
        // Sites publicados em {url}/s/{nome-do-site}/ (sem subdomínio curinga).
        'sites_no_caminho' => true,
        'dominio_sites' => $c['host'],
        'protocolo_sites' => $c['esquema'],
        'db' => [
            'driver' => 'mysql',
            'dsn' => 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_nome'] . ';charset=utf8mb4',
            'usuario' => $c['db_usuario'],
            'senha' => $c['db_senha'],
        ],
        'segredo_app' => bin2hex(random_bytes(32)),
        'segredo_ip' => bin2hex(random_bytes(32)),
        'email_modo' => $c['smtp_usuario'] !== '' ? 'smtp' : 'arquivo',
        'smtp' => [
            'host' => $c['smtp_host'],
            'porta' => (int) $c['smtp_porta'],
            'seguranca' => (int) $c['smtp_porta'] === 465 ? 'ssl' : 'tls',
            'usuario' => $c['smtp_usuario'],
            'senha' => $c['smtp_senha'],
            'remetente' => $c['smtp_usuario'] !== '' ? $c['smtp_usuario'] : 'nao-responda@' . preg_replace('/:\d+$/', '', $c['host']),
            'nome_remetente' => 'Sites',
        ],
        'ia' => ['provedor' => $c['gemini'] !== '' ? 'gemini' : 'desligado', 'chave' => $c['gemini']],
        'pixabay' => ['chave' => $c['pixabay']],
        'atualizacao' => ['github_repo' => REPO_GITHUB, 'github_token' => $c['github'], 'dir_web' => $c['dir_web']],
    ];
    return "<?php\n\n// Gerado pelo instalador em " . gmdate('Y-m-d H:i') . " UTC. Os valores que faltam vêm dos padrões\n"
        . "// (app/Lib/Config.php). Guarde uma cópia deste arquivo: ele tem as senhas e os segredos.\n\n"
        . 'return ' . var_export($cfg, true) . ";\n";
}

/** Troca valores de primeiro nível ou aninhados (ia.chave, pixabay.chave) de um config existente. */
function ajustarConfig(string $arquivo, array $trocas): void
{
    $cfg = (static fn (string $f): mixed => require $f)($arquivo);
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php inválido.');
    }
    foreach ($trocas as $chave => $valor) {
        $ref = &$cfg;
        foreach (explode('.', $chave) as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                $ref[$parte] ??= [];
            }
            $ref = &$ref[$parte];
        }
        $ref = $valor;
        unset($ref);
    }
    file_put_contents($arquivo, "<?php\n\n// Ajustado pelo instalador em " . gmdate('Y-m-d H:i') . " UTC.\n\nreturn " . var_export($cfg, true) . ";\n");
    @chmod($arquivo, 0600);
}

/* ------------------------------------------------------------------ estado */

[$esquema, $host] = enderecoAtual();
$url = $esquema . '://' . $host;
$instalado = projetoInstalado($web);
$projeto = $instalado ?? pastaProjetoPadrao($web);
$caminhoUrl = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$naRaiz = dirname($caminhoUrl) === '/' || dirname($caminhoUrl) === '\\';
$erros = [];
$feito = null;

/* ------------------------------------------------------------------ login (atualização) */

if ($instalado !== null && empty($_SESSION['admin_ok']) && ($_POST['acao'] ?? '') === 'entrar') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $erros[] = 'A página expirou. Recarregue e tente de novo.';
    } else {
        try {
            /** @var \Rankly\Aplicacao $app */
            $app = require $instalado . '/app/bootstrap.php';
            $u = \Rankly\Lib\Usuarios::porEmail($app, \Rankly\Lib\Usuarios::normalizarEmail(post('email')));
            if ($u !== null && ($u['papel'] ?? '') === 'admin' && !empty($u['ativo'])
                && password_verify((string) ($_POST['senha'] ?? ''), (string) $u['senha_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_ok'] = true;
            } else {
                sleep(2);
                $erros[] = 'E-mail ou senha incorretos (precisa ser um administrador do sistema).';
            }
        } catch (Throwable $e) {
            $erros[] = 'Não consegui abrir o sistema instalado: ' . $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------ instalar / atualizar */

if (($_POST['acao'] ?? '') === 'instalar' && ($instalado === null || !empty($_SESSION['admin_ok']))) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $erros[] = 'A página expirou. Recarregue e tente de novo.';
    }
    foreach (requisitos($web, $projeto) as [$ok, $obrigatorio, $texto]) {
        if (!$ok && $obrigatorio) {
            $erros[] = 'Falta: ' . $texto . '.';
        }
    }
    $c = [
        'url' => $url, 'host' => $host, 'esquema' => $esquema,
        'db_host' => post('db_host') ?: 'localhost', 'db_nome' => post('db_nome'), 'db_usuario' => post('db_usuario'),
        'db_senha' => (string) ($_POST['db_senha'] ?? ''),
        'nome' => post('nome'), 'email' => post('email'), 'senha' => (string) ($_POST['senha'] ?? ''),
        'gemini' => preg_replace('/\s+/', '', post('gemini')), 'pixabay' => preg_replace('/\s+/', '', post('pixabay')),
        'smtp_host' => post('smtp_host') ?: 'smtp.hostinger.com', 'smtp_porta' => post('smtp_porta') ?: '465',
        'smtp_usuario' => post('smtp_usuario'), 'smtp_senha' => (string) ($_POST['smtp_senha'] ?? ''),
        'github' => preg_replace('/\s+/', '', post('github')), 'dir_web' => $web,
    ];
    if ($c['github'] !== '' && !preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $c['github'])) {
        $erros[] = 'O token do GitHub não parece certo (começa com github_pat_). Copie de novo.';
    }
    if ($instalado === null) {
        if ($c['db_nome'] === '' || $c['db_usuario'] === '') {
            $erros[] = 'Preencha o nome do banco e o usuário do banco.';
        } elseif (($e = testarBanco($c['db_host'], $c['db_nome'], $c['db_usuario'], $c['db_senha'])) !== null) {
            $erros[] = $e;
        }
        if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido para o primeiro usuário.';
        }
        if (mb_strlen($c['senha']) < 8) {
            $erros[] = 'A senha do primeiro usuário precisa ter pelo menos 8 caracteres.';
        }
        if ($c['senha'] !== (string) ($_POST['senha2'] ?? '')) {
            $erros[] = 'As duas senhas do primeiro usuário não conferem.';
        }
        $estranhos = arquivosEstranhos($web);
        if ($estranhos !== []) {
            $erros[] = 'Esta pasta já tem outro site (' . implode(', ', array_slice($estranhos, 0, 5))
                . '). Instale num subdomínio novo, com a pasta vazia.';
        }
    }
    if (!$naRaiz) {
        $erros[] = 'O sistema precisa ficar na raiz de um domínio ou subdomínio (ex.: https://editor.seudominio.com.br/instalar.php), não numa subpasta.';
    }

    if ($erros === []) {
        $temp = dirname($projeto) . '/.rankly-novo-' . bin2hex(random_bytes(4));
        $antigo = null;
        try {
            // 1. Abre o pacote fora da pasta pública.
            $zip = new ZipArchive();
            if ($zip->open($web . '/' . PACOTE) !== true || !$zip->extractTo($temp)) {
                throw new RuntimeException('Não consegui abrir o ' . PACOTE . '. Envie de novo (o envio pode ter sido interrompido).');
            }
            $zip->close();
            $novo = $temp . '/rankly';
            if (!is_file($novo . '/app/bootstrap.php') || !is_file($novo . '/vendor/autoload.php')) {
                throw new RuntimeException('O pacote está incompleto. Gere/baixe o ' . PACOTE . ' de novo.');
            }

            // 2. Põe o projeto no lugar (atualização: mantém config, fotos, sites e var).
            if ($instalado === null) {
                if (file_exists($projeto)) {
                    throw new RuntimeException("Já existe a pasta {$projeto}. Apague-a pelo Gerenciador de Arquivos (ou renomeie) e tente de novo.");
                }
                if (!@rename($novo, $projeto)) {
                    throw new RuntimeException("Não consegui criar {$projeto}.");
                }
            } else {
                $antigo = $projeto . '.antigo-' . gmdate('YmdHis');
                if (!@rename($projeto, $antigo)) {
                    throw new RuntimeException('Não consegui separar a versão antiga.');
                }
                if (!@rename($novo, $projeto)) {
                    @rename($antigo, $projeto);
                    throw new RuntimeException('Não consegui pôr a versão nova no lugar (a antiga foi mantida).');
                }
                foreach (['config/config.php', 'media', 'sites', 'var'] as $manter) {
                    if (file_exists($antigo . '/' . $manter)) {
                        apagarArvore($projeto . '/' . $manter);
                        if (!@rename($antigo . '/' . $manter, $projeto . '/' . $manter)) {
                            throw new RuntimeException("Não consegui manter {$manter} da versão antiga (ela está em {$antigo}).");
                        }
                    }
                }
            }
            // Defesa extra: se um dia a pasta do projeto ficar exposta, o servidor recusa tudo nela.
            @file_put_contents($projeto . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
            foreach (['var', 'media', 'sites'] as $gravavel) {
                if (!is_dir($projeto . '/' . $gravavel)) {
                    @mkdir($projeto . '/' . $gravavel, 0755, true);
                }
            }

            // 3. Parte web na pasta do subdomínio.
            if (is_file($web . '/.htaccess') && !str_contains((string) file_get_contents($web . '/.htaccess'), 'Construtor Rankly')) {
                @rename($web . '/.htaccess', $web . '/.htaccess.antes-rankly');
            }
            apagarArvore($web . '/editor'); // sem arquivos velhos do editor
            copiarArvore($projeto . '/public_html', $web);
            @unlink($web . '/router-dev.php');
            file_put_contents($web . '/rankly-raiz.php', "<?php\n\n// Pasta do projeto (fora da web). Gerado pelo instalador.\n\ndeclare(strict_types=1);\n\nreturn "
                . var_export($projeto, true) . ";\n");
            if (is_file($web . '/default.php')) {
                @unlink($web . '/default.php');
            }

            // 4. Configuração.
            $arqConfig = $projeto . '/config/config.php';
            if ($instalado === null) {
                file_put_contents($arqConfig, configNova($c));
                @chmod($arqConfig, 0600);
            } else {
                $trocas = ['url_editor' => $url, 'dominio_sites' => $host, 'protocolo_sites' => $esquema,
                    'atualizacao.dir_web' => $web, 'atualizacao.github_repo' => REPO_GITHUB];
                if ($c['github'] !== '') {
                    $trocas['atualizacao.github_token'] = $c['github'];
                }
                if ($c['gemini'] !== '') {
                    $trocas['ia.chave'] = $c['gemini'];
                    $trocas['ia.provedor'] = 'gemini';
                }
                if ($c['pixabay'] !== '') {
                    $trocas['pixabay.chave'] = $c['pixabay'];
                }
                ajustarConfig($arqConfig, $trocas);
            }

            // 5. Banco, usuário e sites.
            /** @var \Rankly\Aplicacao $app */
            $app = require $projeto . '/app/bootstrap.php';
            $db = $app->db();
            (new \Rankly\Lib\Migrador($db, \Rankly\Lib\Migrador::dirPadrao($db)))->aplicar();
            $republicados = 0;
            $falhas = [];
            if ($instalado === null) {
                $problema = \Rankly\Lib\Usuarios::problemaSenha($c['senha']);
                if ($problema !== null) {
                    throw new RuntimeException($problema);
                }
                $email = \Rankly\Lib\Usuarios::normalizarEmail($c['email']);
                if (\Rankly\Lib\Usuarios::porEmail($app, $email) === null) {
                    \Rankly\Lib\Usuarios::criar($app, $c['nome'] !== '' ? $c['nome'] : $email, $email, 'admin', $c['senha']);
                }
            } else {
                // Mudanças nos modelos só chegam aos sites no ar quando eles são republicados.
                foreach ($db->todos("SELECT id, slug FROM sites WHERE status = 'publicado' ORDER BY id") as $s) {
                    try {
                        \Rankly\Lib\Executores::republicar($app, (int) $s['id'], null);
                        $republicados++;
                    } catch (Throwable $e) {
                        $falhas[] = $s['slug'];
                    }
                }
                apagarArvore($antigo);
            }

            // 6. Limpeza: pacote, temporários e este instalador.
            apagarArvore($temp);
            @unlink($web . '/' . PACOTE);
            $feito = ['atualizacao' => $instalado !== null, 'email' => $c['email'], 'republicados' => $republicados, 'falhas' => $falhas,
                'projeto' => $projeto,
                'automatica' => $c['github'] !== '' || ($instalado !== null && (string) ($app->config('atualizacao.github_token', '')) !== ''),
                'apagou' => @unlink(__FILE__)];
            $_SESSION = [];
        } catch (Throwable $e) {
            apagarArvore($temp);
            $erros[] = $e->getMessage();
            error_log('Rankly instalador: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

/* ------------------------------------------------------------------ página */

$requisitos = requisitos($web, $projeto);
$tudoOk = !in_array(false, array_map(static fn (array $r): bool => $r[0] || !$r[1], $requisitos), true);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalar o Construtor Rankly</title>
<style>
:root{--pri:#5b3df5;--ok:#1f8a4c;--erro:#c0392b;--alerta:#b7791f;--linha:#e4e2ee;--fundo:#f7f6fb;--texto:#1d1b26;--suave:#6b6880}
*{box-sizing:border-box}body{margin:0;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--fundo);color:var(--texto)}
main{max-width:760px;margin:0 auto;padding:32px 18px 64px}h1{font-size:1.7rem;margin:0 0 4px}h2{font-size:1.15rem;margin:28px 0 10px}
.sub{color:var(--suave);margin:0 0 24px}.cartao{background:#fff;border:1px solid var(--linha);border-radius:14px;padding:20px 22px;margin:16px 0}
.req{list-style:none;margin:0;padding:0}.req li{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--linha)}.req li:last-child{border:0}
.req b{flex:none;width:22px}.ok b{color:var(--ok)}.falta b{color:var(--erro)}.opc b{color:var(--alerta)}.req small{display:block;color:var(--suave)}
label{display:block;font-weight:600;margin:14px 0 4px}input{width:100%;padding:10px 12px;border:1px solid #cfcbe0;border-radius:9px;font:inherit}
.ajuda{font-size:.9rem;color:var(--suave);margin:4px 0 0}.duas{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
@media(max-width:600px){.duas{grid-template-columns:1fr}}
button{margin-top:22px;width:100%;padding:14px;border:0;border-radius:11px;background:var(--pri);color:#fff;font:700 1.05rem system-ui;cursor:pointer}
button[disabled]{opacity:.5;cursor:not-allowed}.erros{background:#fdecea;border:1px solid #f5c2bc;color:#7b241c;border-radius:12px;padding:12px 18px}
.sucesso{background:#e8f6ee;border:1px solid #b6e2c8;border-radius:12px;padding:16px 20px}code{background:#efedf6;padding:2px 6px;border-radius:6px;font-size:.92em;word-break:break-all}
details summary{cursor:pointer;font-weight:600}a{color:var(--pri)}
</style>
</head>
<body>
<main>
<h1>Construtor Rankly</h1>
<p class="sub"><?= $instalado !== null ? 'Atualizar a instalação' : 'Instalação na hospedagem' ?> · <?= h($url) ?></p>

<?php if ($feito !== null): ?>
  <div class="sucesso">
    <h2 style="margin-top:0"><?= $feito['atualizacao'] ? 'Sistema atualizado.' : 'Pronto! O sistema está instalado.' ?></h2>
    <p><a href="/editor/"><strong>Abrir o editor: <?= h($url) ?>/editor/</strong></a></p>
    <?php if (!$feito['atualizacao']): ?>
      <p>Entre com <strong><?= h($feito['email']) ?></strong> e a senha que você escolheu.</p>
    <?php else: ?>
      <p><?= (int) $feito['republicados'] ?> site(s) republicado(s) com a versão nova.
      <?= $feito['falhas'] ? 'Não deu para republicar: ' . h(implode(', ', $feito['falhas'])) . ' (abra cada um no editor e publique de novo).' : '' ?></p>
    <?php endif; ?>
    <p>Os sites publicados ficam em <code><?= h($url) ?>/s/nome-do-site/</code>.</p>
  </div>
  <div class="cartao">
    <h2 style="margin-top:0">Último passo (recomendado): tarefa agendada</h2>
    <p>Garante o envio dos e-mails que falharem, a limpeza diária e a atualização automática. No painel:
    <strong>Avançado → Cron Jobs</strong> (ou procure "Cron" na barra lateral), frequência <strong>a cada minuto</strong> (ou a cada 5 minutos):</p>
    <p><strong>Jeito 1 — tipo "PHP":</strong> no campo do arquivo, cole<br><code><?= h($feito['projeto']) ?>/app/cli/cron.php</code></p>
    <p><strong>Jeito 2 — tipo "Personalizado"</strong> (se o jeito 1 não aceitar): cole o comando<br><code>/usr/bin/php <?= h($feito['projeto']) ?>/app/cli/cron.php</code></p>
    <p class="ajuda">Para conferir: depois de uns minutos, clique em "Ver saída" (View output) na lista de tarefas. Vazio ou uma linha com a data é bom sinal; uma mensagem de erro, me mande.</p>
  </div>
  <div class="cartao">
    <p style="margin:0"><?= $feito['automatica'] ? 'Atualização automática <strong>ligada</strong>: com a tarefa agendada acima, as versões novas chegam sozinhas.' : 'Atualização automática desligada (sem token do GitHub). Para ligar depois, rode o instalador de novo e informe o token.' ?></p>
  </div>
  <div class="cartao">
    <p style="margin:0">Arquivos do sistema: <code><?= h($feito['projeto']) ?></code> (fora da pasta pública).
    <?= $feito['apagou'] ? 'O instalador e o pacote foram apagados.' : '<strong>Apague o arquivo instalar.php desta pasta pelo Gerenciador de Arquivos.</strong>' ?></p>
  </div>

<?php elseif ($instalado !== null && empty($_SESSION['admin_ok'])): ?>
  <?php if ($erros): ?><div class="erros"><?php foreach ($erros as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
  <div class="cartao">
    <p>O sistema já está instalado aqui. Para atualizar, entre com um <strong>administrador</strong> do sistema.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="entrar">
      <label for="email">E-mail</label><input id="email" name="email" type="email" required>
      <label for="senha">Senha</label><input id="senha" name="senha" type="password" required>
      <button>Entrar</button>
    </form>
  </div>

<?php else: ?>
  <?php if ($erros): ?><div class="erros"><?php foreach ($erros as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>

  <div class="cartao">
    <h2 style="margin-top:0">1. Servidor</h2>
    <ul class="req">
    <?php foreach ($requisitos as [$ok, $obrigatorio, $texto, $como]): ?>
      <li class="<?= $ok ? 'ok' : ($obrigatorio ? 'falta' : 'opc') ?>"><b><?= $ok ? '✓' : ($obrigatorio ? '✗' : '!') ?></b>
        <span><?= h($texto) ?><?php if (!$ok): ?><small><?= h($como) ?></small><?php endif; ?></span></li>
    <?php endforeach; ?>
    <?php if ($esquema !== 'https'): ?>
      <li class="opc"><b>!</b><span>Endereço sem HTTPS<small>Ative o SSL deste subdomínio no painel (Segurança → SSL) e abra esta página com https:// antes de instalar. Sem HTTPS o login não fica protegido.</small></span></li>
    <?php endif; ?>
    <?php if (!$naRaiz): ?>
      <li class="falta"><b>✗</b><span>Fora da raiz do endereço<small>Abra o instalador na raiz de um subdomínio (ex.: https://editor.seudominio.com.br/instalar.php), não numa subpasta.</small></span></li>
    <?php endif; ?>
    </ul>
    <p class="ajuda">O sistema vai para <code><?= h($projeto) ?></code> (fora da pasta pública) e a parte web para esta pasta.</p>
  </div>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="instalar">
    <?php if ($instalado === null): ?>
    <div class="cartao">
      <h2 style="margin-top:0">2. Banco de dados MySQL</h2>
      <p class="ajuda">Crie no painel em <strong>Bancos de dados → Gerenciamento</strong> e copie os dados aqui (o nome e o usuário começam com <code>u…_</code>).</p>
      <div class="duas">
        <div><label for="db_nome">Nome do banco</label><input id="db_nome" name="db_nome" value="<?= h(post('db_nome')) ?>" placeholder="u123456789_rankly" required></div>
        <div><label for="db_usuario">Usuário do banco</label><input id="db_usuario" name="db_usuario" value="<?= h(post('db_usuario')) ?>" placeholder="u123456789_rankly" required></div>
        <div><label for="db_senha">Senha do banco</label><input id="db_senha" name="db_senha" type="password" required></div>
        <div><label for="db_host">Servidor</label><input id="db_host" name="db_host" value="<?= h(post('db_host') ?: 'localhost') ?>"></div>
      </div>
    </div>
    <div class="cartao">
      <h2 style="margin-top:0">3. Seu acesso ao sistema</h2>
      <label for="nome">Seu nome</label><input id="nome" name="nome" value="<?= h(post('nome')) ?>">
      <label for="email">E-mail</label><input id="email" name="email" type="email" value="<?= h(post('email')) ?>" required>
      <div class="duas">
        <div><label for="senha">Senha (8 ou mais caracteres)</label><input id="senha" name="senha" type="password" minlength="8" required></div>
        <div><label for="senha2">Repita a senha</label><input id="senha2" name="senha2" type="password" minlength="8" required></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="cartao">
      <h2 style="margin-top:0"><?= $instalado === null ? '4. ' : '' ?>Chaves (opcional)</h2>
      <p class="ajuda"><?= $instalado === null ? 'Dá para colocar depois rodando o instalador de novo.' : 'Deixe em branco para manter as atuais.' ?> Como pegar: guia COMO-TESTAR, parte 3.</p>
      <label for="gemini">Chave da IA (Google Gemini)</label><input id="gemini" name="gemini" placeholder="AQ.… ou AIza…">
      <label for="pixabay">Chave do banco de imagens (Pixabay)</label><input id="pixabay" name="pixabay" placeholder="12345678-…">
    </div>
    <div class="cartao">
      <h2 style="margin-top:0">Atualização automática (opcional)</h2>
      <p class="ajuda">Com um token do GitHub <strong>só de leitura</strong>, cada versão nova que passar nos testes chega aqui sozinha em até 5 minutos (precisa da tarefa agendada ligada). Como criar: guia HOSPEDAGEM, parte 7.<?= $instalado !== null ? ' Deixe em branco para manter o atual.' : '' ?></p>
      <label for="github">Token do GitHub</label><input id="github" name="github" placeholder="github_pat_…">
    </div>
    <?php if ($instalado === null): ?>
    <div class="cartao">
      <details>
        <summary>5. E-mail de aviso de contatos (opcional)</summary>
        <p class="ajuda">Para o dono do site receber um e-mail a cada contato. Crie uma caixa no painel (Emails), ex.: <code>nao-responda@seudominio.com.br</code>. Sem isso, os contatos aparecem só no painel do sistema.</p>
        <div class="duas">
          <div><label for="smtp_usuario">E-mail remetente</label><input id="smtp_usuario" name="smtp_usuario" type="email" value="<?= h(post('smtp_usuario')) ?>"></div>
          <div><label for="smtp_senha">Senha desse e-mail</label><input id="smtp_senha" name="smtp_senha" type="password"></div>
          <div><label for="smtp_host">Servidor SMTP</label><input id="smtp_host" name="smtp_host" value="<?= h(post('smtp_host') ?: 'smtp.hostinger.com') ?>"></div>
          <div><label for="smtp_porta">Porta</label><input id="smtp_porta" name="smtp_porta" value="<?= h(post('smtp_porta') ?: '465') ?>"></div>
        </div>
      </details>
    </div>
    <?php endif; ?>
    <button <?= $tudoOk && $naRaiz ? '' : 'disabled' ?>><?= $instalado === null ? 'Instalar' : 'Atualizar agora' ?></button>
    <?php if (!$tudoOk): ?><p class="ajuda">Resolva os itens marcados com ✗ e recarregue a página.</p><?php endif; ?>
  </form>
<?php endif; ?>
</main>
</body>
</html>
