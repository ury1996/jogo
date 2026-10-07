<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;
use Rankly\Preparo\Texto;

/**
 * Recebimento de leads dos formulários dos sites (§8) [M19–M22]: anti-spam (pote de mel,
 * tempo mínimo, limite por IP com HMAC), validação, origem da campanha e e-mail ao dono.
 */
final class Leads
{
    public const LIMITE_POR_HORA = 5;
    public const TEMPO_MINIMO_MS = 3000;
    public const CAMPOS_ORIGEM = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'gbraid', 'wbraid', 'pagina', 'referencia',
    ];

    /**
     * @return array{ok: bool, ignorado?: bool, erro?: string, mensagem?: string, status?: int, id?: int}
     */
    public static function receber(Aplicacao $app, array $site, array $campos, string $ip, string $userAgent = ''): array
    {
        // Pote de mel: robô preencheu o campo invisível → finge sucesso.
        $pote = $campos['empresa_site'] ?? '';
        if (!is_string($pote) || trim($pote) !== '') {
            return ['ok' => true, 'ignorado' => true];
        }
        // Tempo de preenchimento (ms desde o carregamento), quando o script informou.
        $t = $campos['_t'] ?? '';
        if (is_int($t) || is_float($t)) {
            $t = (string) (int) $t;
        }
        if (!is_string($t)) {
            return ['ok' => true, 'ignorado' => true];
        }
        $t = trim($t);
        if ($t !== '' && (!ctype_digit($t) || (int) $t < self::TEMPO_MINIMO_MS)) {
            return ['ok' => true, 'ignorado' => true];
        }

        $ipHash = IpHash::hash($ip, $app->segredoIp());
        $limites = new LimiteTaxa($app);
        $chaveLimite = LimiteTaxa::chave('lead', $ipHash);
        // A vaga é reservada antes de gravar (envios simultâneos não furam o limite) e
        // devolvida se os dados forem recusados ou a gravação falhar.
        if (!$limites->consumir($chaveLimite, self::LIMITE_POR_HORA, 3600)) {
            return [
                'ok' => false, 'status' => 429, 'erro' => 'limite',
                'mensagem' => 'Recebemos várias mensagens deste aparelho. Tente de novo mais tarde ou chame no WhatsApp.',
            ];
        }
        try {
            $r = self::validarEGravar($app, $site, $campos, $ipHash);
        } catch (\Throwable $e) {
            $limites->descontar($chaveLimite);
            throw $e;
        }
        if (!$r['ok']) {
            $limites->descontar($chaveLimite);
        }
        return $r;
    }

    /** Valida os campos e grava o lead (+ tarefa do e-mail). */
    private static function validarEGravar(Aplicacao $app, array $site, array $campos, string $ipHash): array
    {
        $nome = self::linha($campos['nome'] ?? '');
        $telefone = self::linha($campos['telefone'] ?? '');
        $email = self::linha($campos['email'] ?? '');
        $mensagem = self::multilinha($campos['mensagem'] ?? '');
        if ($nome === '') {
            return self::invalido('Informe o seu nome.', 'nome');
        }
        if (mb_strlen($nome, 'UTF-8') > 80) {
            return self::invalido('O nome pode ter no máximo 80 caracteres.', 'nome');
        }
        if ($telefone === '' || !Telefone::validoParaLead($telefone) || mb_strlen($telefone, 'UTF-8') > 30) {
            return self::invalido('Informe um telefone com DDD (10 ou 11 dígitos).', 'telefone');
        }
        if ($email !== '' && (mb_strlen($email, 'UTF-8') > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return self::invalido('Confira o e-mail informado.', 'email');
        }
        if (mb_strlen($mensagem, 'UTF-8') > 1000) {
            return self::invalido('A mensagem pode ter no máximo 1000 caracteres.', 'mensagem');
        }

        $origem = self::origem($campos);
        $db = $app->db();
        $id = $db->transacao(static function () use ($app, $db, $site, $nome, $telefone, $email, $mensagem, $origem, $ipHash): int {
            $id = $db->inserir('leads', [
                'site_id' => (int) $site['id'],
                'nome' => $nome,
                'telefone' => Telefone::formatar($telefone),
                'email' => $email !== '' ? $email : null,
                'mensagem' => $mensagem !== '' ? $mensagem : null,
                'origem' => $origem === [] ? null : json_encode($origem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_hash' => $ipHash,
                'criado_em' => $app->agoraSql(),
                'lido_em' => null,
            ]);
            $app->tarefas()->enfileirar('email_lead', ['lead_id' => $id]);
            return $id;
        });
        return ['ok' => true, 'id' => $id];
    }

    /** Campos de origem (utm_*, gclid, gbraid, wbraid, pagina, referencia) não vazios. */
    public static function origem(array $campos): array
    {
        $r = [];
        foreach (self::CAMPOS_ORIGEM as $campo) {
            $v = self::linha($campos[$campo] ?? '');
            if ($v !== '') {
                $r[$campo] = mb_substr($v, 0, in_array($campo, ['pagina', 'referencia'], true) ? 500 : 300, 'UTF-8');
            }
        }
        return $r;
    }

    /** Formato da API para o painel de leads. */
    public static function publico(array $l): array
    {
        $origem = json_decode((string) ($l['origem'] ?? ''), true);
        return [
            'id' => (int) $l['id'],
            'siteId' => (int) $l['site_id'],
            'nome' => (string) $l['nome'],
            'telefone' => (string) $l['telefone'],
            'email' => (string) ($l['email'] ?? ''),
            'mensagem' => (string) ($l['mensagem'] ?? ''),
            'origem' => is_array($origem) && $origem !== [] ? $origem : new \stdClass(),
            'criadoEm' => Sites::iso($l['criado_em']),
            'lido' => $l['lido_em'] !== null,
            'lidoEm' => Sites::iso($l['lido_em']),
            'whatsappLink' => Telefone::linkWhatsapp($l['telefone'], 'Olá, ' . self::primeiroNome((string) $l['nome']) . '! Recebemos sua mensagem pelo site.'),
        ];
    }

    /**
     * CSV dos leads (UTF-8 com BOM, separador ";", CRLF) com proteção contra injeção de
     * fórmulas em planilhas: valores iniciados por = + - @ (ou tab/CR) ganham um apóstrofo.
     *
     * @param iterable<array> $leads linhas da tabela
     */
    public static function csv(iterable $leads): string
    {
        $cab = ['Data (UTC)', 'Nome', 'Telefone', 'E-mail', 'Mensagem', 'Lido',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'Página', 'Referência'];
        $linhas = [self::linhaCsv($cab)];
        foreach ($leads as $l) {
            $origem = json_decode((string) ($l['origem'] ?? ''), true);
            $origem = is_array($origem) ? $origem : [];
            $valores = [
                (string) $l['criado_em'],
                (string) $l['nome'],
                (string) $l['telefone'],
                (string) ($l['email'] ?? ''),
                (string) ($l['mensagem'] ?? ''),
                $l['lido_em'] !== null ? 'sim' : 'não',
            ];
            foreach (self::CAMPOS_ORIGEM as $campo) {
                $valores[] = (string) ($origem[$campo] ?? '');
            }
            $linhas[] = self::linhaCsv($valores);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $linhas) . "\r\n";
    }

    /** Célula segura: neutraliza fórmula e escapa aspas. */
    public static function celulaCsv(string $v): string
    {
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        if (preg_match('/[;"\r\n]/', $v) || $v !== trim($v)) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    /** E-mail ao dono do site (executor da tarefa email_lead). */
    public static function enviarEmail(Aplicacao $app, int $leadId): void
    {
        $lead = $app->db()->um('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if ($lead === null) {
            return; // excluído antes do envio
        }
        $site = Sites::porId($app, (int) $lead['site_id']);
        if ($site === null) {
            return;
        }
        $destinatarios = self::destinatarios($app, $site);
        if ($destinatarios === []) {
            $app->log()->aviso('Lead sem destinatário de e-mail.', ['site' => (int) $site['id'], 'lead' => $leadId]);
            return;
        }
        $nomeSite = Texto::textoDe($site['documento']['dados']['nome'] ?? '') ?: (string) $site['nome'];
        $origem = json_decode((string) ($lead['origem'] ?? ''), true);
        $linhas = [
            "Você recebeu um novo contato pelo site {$nomeSite}.",
            '',
            'Nome: ' . $lead['nome'],
            'Telefone: ' . $lead['telefone'],
        ];
        if (($lead['email'] ?? '') !== '' && $lead['email'] !== null) {
            $linhas[] = 'E-mail: ' . $lead['email'];
        }
        $linhas[] = 'Recebido em: ' . (new \DateTimeImmutable((string) $lead['criado_em'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y \à\s H:i') . ' (horário de Brasília)';
        if (($lead['mensagem'] ?? '') !== '' && $lead['mensagem'] !== null) {
            $linhas[] = '';
            $linhas[] = 'Mensagem:';
            $linhas[] = (string) $lead['mensagem'];
        }
        if (is_array($origem) && $origem !== []) {
            $linhas[] = '';
            $linhas[] = 'Origem da visita:';
            foreach ($origem as $campo => $valor) {
                $linhas[] = '  ' . $campo . ': ' . $valor;
            }
        }
        $wa = Telefone::linkWhatsapp($lead['telefone'], 'Olá, ' . self::primeiroNome((string) $lead['nome']) . '! Recebemos sua mensagem pelo site ' . $nomeSite . '.');
        if ($wa !== '') {
            $linhas[] = '';
            $linhas[] = 'Chamar no WhatsApp para responder:';
            $linhas[] = $wa;
        }
        $linhas[] = '';
        $linhas[] = 'Os contatos também ficam no painel: ' . rtrim((string) $app->config('url_editor'), '/') . '/editor/#/site/' . (int) $site['id'] . '/leads';

        $app->mailer()->enviar([
            'para' => $destinatarios,
            'assunto' => 'Novo contato pelo site: ' . $lead['nome'],
            'texto' => implode("\n", $linhas),
            'responderPara' => $lead['email'] ?? null,
            'copiaOculta' => array_filter([(string) $app->config('email_leads_copia', '')]),
        ]);
    }

    /** E-mail público do negócio (dados.email) ou, sem ele, o do dono da conta. */
    public static function destinatarios(Aplicacao $app, array $site): array
    {
        $email = trim(Texto::textoDe($site['documento']['dados']['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return [$email];
        }
        if (!empty($site['dono_id'])) {
            $dono = $app->db()->valor('SELECT email FROM usuarios WHERE id = ? AND ativo = 1', [(int) $site['dono_id']]);
            if (is_string($dono) && $dono !== '') {
                return [$dono];
            }
        }
        return [];
    }

    private static function invalido(string $mensagem, string $campo): array
    {
        return ['ok' => false, 'status' => 422, 'erro' => 'invalido', 'mensagem' => $mensagem, 'campo' => $campo];
    }

    /** Texto de uma linha: sem controles, espaços colapsados. */
    private static function linha(mixed $v): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        $v = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }

    private static function multilinha(mixed $v): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', ' ', $v) ?? '';
        return trim($v);
    }

    private static function linhaCsv(array $valores): string
    {
        return implode(';', array_map(static fn ($v): string => self::celulaCsv((string) $v), $valores));
    }

    private static function primeiroNome(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        return $partes[0] ?? '';
    }
}
