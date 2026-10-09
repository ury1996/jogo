<?php

/**
 * POST /_lead no host do próprio site ([M19–M22], contrato §8). O .htaccess (e o
 * router-dev.php) reescrevem /_lead para cá. Identifica o site pelo host (subdomínio ou
 * domínio próprio ativo), grava o lead com Lib\Leads::receber e responde:
 * - fetch/XHR: JSON {ok:true} ou {ok:false, erro:{codigo, mensagem}};
 * - formulário sem JavaScript: 303 para /obrigado/ (ou uma página simples com o erro).
 * Se a pasta sites/ não estiver dentro do projeto, defina RANKLY_RAIZ com a raiz do projeto.
 * No modo demonstração (sites_no_caminho) quem inclui este arquivo é public_html/s.php, com
 * $slugDoCaminho definido: o site vem do caminho (/s/{slug}/_lead), não do host.
 */

declare(strict_types=1);

use Rankly\Http\Requisicao;
use Rankly\Http\Resposta;

header_remove('X-Powered-By');

$cabecalhosFixos = static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: strict-origin-when-cross-origin');
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    $cabecalhosFixos();
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Use o formulário do site para enviar a sua mensagem.\n";
    exit;
}

$raizProjeto = getenv('RANKLY_RAIZ') ?: dirname(__DIR__);
try {
    /** @var \Rankly\Aplicacao $app */
    $app = require $raizProjeto . '/app/bootstrap.php';
} catch (\Throwable $e) {
    error_log('Rankly: falha ao iniciar o recebimento de leads: ' . $e->getMessage());
    $cabecalhosFixos();
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"erro":{"codigo":"indisponivel","mensagem":"Não foi possível enviar agora. Tente de novo em instantes ou fale pelo WhatsApp."}}';
    exit;
}

$req = Requisicao::doGlobais();
$host = strtolower((string) ($req->cabecalho('host') ?? ''));
$slugDoCaminho = isset($slugDoCaminho) && is_string($slugDoCaminho) ? $slugDoCaminho : null;
$raizDoSite = $slugDoCaminho !== null ? '/s/' . $slugDoCaminho . '/' : '/';
$erro = static function (int $status, string $codigo, string $mensagem) use ($req, $raizDoSite): Resposta {
    return $req->querJson()
        ? Resposta::json(['ok' => false, 'erro' => ['codigo' => $codigo, 'mensagem' => $mensagem]], $status)
        : \Rankly\Api\Leads::paginaErro($mensagem, $status, null, $raizDoSite);
};

try {
    // Só aceita envios da própria página do site (Origin, quando o navegador informa).
    // "null" também é recusado: é o que manda um iframe isolado (sandbox) ou data: de outra
    // página, que assim faria o navegador de cada visitante dela enviar leads falsos (cada
    // um com seu IP, driblando o limite por IP).
    $origem = $req->cabecalho('origin');
    if ($origem !== null && $origem !== '') {
        $hostOrigem = strtolower((string) parse_url($origem, PHP_URL_HOST));
        $porta = parse_url($origem, PHP_URL_PORT);
        if ($porta !== null) {
            $hostOrigem .= ':' . $porta;
        }
        // No modo demonstração o host público (url_editor) pode ser diferente do que chega ao
        // PHP atrás de um proxy (Codespaces, túnel): aceita os dois.
        $hostsAceitos = [$host];
        if ($slugDoCaminho !== null) {
            $editor = parse_url((string) $app->config('url_editor', ''));
            if (is_array($editor) && isset($editor['host'])) {
                $hostsAceitos[] = strtolower($editor['host']) . (isset($editor['port']) ? ':' . $editor['port'] : '');
            }
        }
        if (!in_array($hostOrigem, $hostsAceitos, true)) {
            $resposta = $erro(403, 'origem', 'Envio recusado: use o formulário do próprio site.');
        }
    }
    if (!isset($resposta)) {
        $site = $slugDoCaminho !== null
            ? \Rankly\Lib\Sites::porSlug($app, $slugDoCaminho)
            : \Rankly\Lib\Sites::porHost($app, $host);
        if ($site === null || $site['status'] !== 'publicado') {
            $resposta = $erro(404, 'site_nao_encontrado', 'Site não encontrado.');
        } else {
            $resposta = \Rankly\Api\Leads::responderPublico($app, $req, $site, $raizDoSite . 'obrigado/');
        }
    }
} catch (\Throwable $e) {
    $app->log()->excecao($e, ['etapa' => '_lead', 'host' => $host]);
    $resposta = $erro(500, 'erro_interno', 'Não foi possível enviar agora. Tente de novo em instantes ou fale pelo WhatsApp.');
}

$resposta->cabecalho('X-Content-Type-Options', 'nosniff')
    ->cabecalho('Cache-Control', 'no-store')
    ->cabecalhoPadrao('Referrer-Policy', 'strict-origin-when-cross-origin');
$resposta->enviar();

// Tarefas criadas por este envio (e-mail ao dono) rodam depois de a resposta sair.
\Rankly\Lib\Executores::processarAposResposta($app);
