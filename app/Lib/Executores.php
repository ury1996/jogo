<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Executores das tarefas da fila [M14], registrados pelo cron e pela API
 * (que processa logo após responder, quando o servidor permite).
 */
final class Executores
{
    public static function registrarTodos(Aplicacao $app): void
    {
        $t = $app->tarefas();
        $t->registrar('email_lead', static function (array $p, Aplicacao $app): void {
            Leads::enviarEmail($app, (int) ($p['lead_id'] ?? 0));
        });
        $t->registrar('email_redefinicao', static function (array $p, Aplicacao $app): void {
            self::emailRedefinicao($app, (int) ($p['usuario_id'] ?? 0), (string) ($p['token'] ?? ''));
        }, true);
        $t->registrar('republicar', static function (array $p, Aplicacao $app): void {
            self::republicar($app, (int) ($p['site_id'] ?? 0), isset($p['usuario_id']) ? (int) $p['usuario_id'] : null);
        });
        $t->registrar('limpeza_diaria', static function (array $p, Aplicacao $app): void {
            self::limpezaDiaria($app);
        });
    }

    /**
     * Depois de enviar a resposta: libera o cliente (PHP-FPM/LiteSpeed) e executa as tarefas
     * enfileiradas nesta requisição (ex.: e-mail do lead), sem esperar o cron. No servidor
     * embutido (dev) só processa se os e-mails forem para arquivo (instantâneo).
     * Falhas ficam na fila para o cron tentar de novo.
     */
    public static function processarAposResposta(Aplicacao $app): void
    {
        $ids = $app->tarefas()->enfileiradasAgora();
        if ($ids === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif ($app->config('email_modo') !== 'arquivo') {
            return; // sem como liberar o cliente: o cron envia
        }
        try {
            self::registrarTodos($app);
            $app->tarefas()->processarIds($ids);
        } catch (\Throwable $e) {
            $app->log()->excecao($e, ['etapa' => 'tarefas apos resposta']);
        }
    }

    /** Garante uma limpeza diária na fila (próxima às 06:00 UTC = 03:00 em Brasília). */
    public static function agendarLimpeza(Aplicacao $app): void
    {
        if (!$app->tarefas()->existePendente('limpeza_diaria')) {
            $app->tarefas()->enfileirar('limpeza_diaria', [], self::proximaLimpeza($app));
        }
    }

    public static function proximaLimpeza(Aplicacao $app): \DateTimeImmutable
    {
        $agora = $app->agora();
        $hoje = $agora->setTime(6, 0);
        return $hoje > $agora ? $hoje : $hoje->modify('+1 day');
    }

    public static function emailRedefinicao(Aplicacao $app, int $usuarioId, string $token): void
    {
        $usuario = Usuarios::porId($app, $usuarioId);
        if ($usuario === null || (int) $usuario['ativo'] !== 1 || $token === '') {
            return;
        }
        $link = rtrim((string) $app->config('url_editor'), '/') . '/editor/#/redefinir?token=' . rawurlencode($token);
        $texto = implode("\n", [
            'Olá, ' . $usuario['nome'] . '.',
            '',
            'Recebemos um pedido para redefinir a sua senha do Construtor Rankly.',
            'Para criar uma senha nova, abra o link abaixo (vale por 1 hora e só pode ser usado uma vez):',
            '',
            $link,
            '',
            'Se não foi você que pediu, ignore este e-mail: a sua senha atual continua valendo.',
        ]);
        $app->mailer()->enviar([
            'para' => (string) $usuario['email'],
            'assunto' => 'Redefinição de senha · Construtor Rankly',
            'texto' => $texto,
        ]);
    }

    public static function republicar(Aplicacao $app, int $siteId, ?int $usuarioId): void
    {
        $site = Sites::porId($app, $siteId);
        if ($site === null || $site['status'] !== 'publicado') {
            return; // só republica o que está no ar
        }
        $app->gerador()->publicar($site, $usuarioId ?? (int) ($site['dono_id'] ?? 0));
    }

    /**
     * Limpeza diária: leads além da retenção (LGPD), tokens de senha vencidos, limites
     * antigos, sessões vencidas, tarefas concluídas há mais de 30 dias e mídia órfã
     * (sem uso em documento nem versão) com mais de 30 dias e buscas de ícones com mais de 7 dias.
     * Reagenda para o dia seguinte.
     */
    public static function limpezaDiaria(Aplicacao $app): void
    {
        $db = $app->db();
        $meses = max(1, (int) $app->config('retencao_leads_meses', 12));
        $resumo = [
            'leads' => $db->executar('DELETE FROM leads WHERE criado_em < ?', [$app->agoraSql("-{$meses} months")]),
            'redefinicoes' => $db->executar(
                'DELETE FROM redefinicoes_senha WHERE expira_em < ? OR usado_em IS NOT NULL',
                [$app->agoraSql()],
            ),
            'limites' => LimiteTaxa::limparVencidos($app, 86400),
            'sessoes' => Sessao::limparVencidas($app),
            'tarefas' => $db->executar(
                "DELETE FROM tarefas WHERE status IN ('feita', 'falhou') AND atualizado_em < ?",
                [$app->agoraSql('-30 days')],
            ),
            'midia' => Midia::limparOrfas($app, 30),
            'iconify' => Iconify::limparCache($app->dirVar('cache') . '/iconify'),
        ];
        $app->log()->info('Limpeza diária concluída.', $resumo);
        // Próxima execução (a tarefa atual ainda está "executando", então enfileira direto).
        $app->tarefas()->enfileirar('limpeza_diaria', [], self::proximaLimpeza($app));
    }
}
