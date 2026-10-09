<?php

declare(strict_types=1);

namespace Rankly\Lib;

/**
 * Configuração da aplicação (contrato §11.3): valores padrão, carga do arquivo
 * config/config.php e leitura com chaves pontuadas ("db.dsn", "smtp.host").
 */
final class Config
{
    /** Valores padrão: tudo o que o config.php não informar vem daqui. */
    public static function padrao(): array
    {
        return [
            'ambiente' => 'dev',
            'url_editor' => 'http://localhost:8080',
            'dominio_sites' => 'localhost:8081',
            'protocolo_sites' => 'http',
            'sites_no_caminho' => false,
            'db' => [
                'driver' => 'mysql',
                'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=rankly;charset=utf8mb4',
                'usuario' => 'rankly',
                'senha' => 'rankly',
            ],
            'dir_sites' => 'sites',
            'dir_media' => 'media',
            'dir_var' => 'var',
            'dir_biblioteca' => 'biblioteca',
            'segredo_app' => '',
            'segredo_ip' => '',
            'smtp' => [
                'host' => '',
                'porta' => 587,
                'seguranca' => 'tls',
                'usuario' => '',
                'senha' => '',
                'remetente' => 'nao-responda@localhost',
                'nome_remetente' => 'Sites Rankly',
                'tempo_limite' => 15,
            ],
            'email_modo' => 'arquivo',
            'email_leads_copia' => '',
            'retencao_leads_meses' => 12,
            'confiar_cloudflare' => false,
            'releases_mantidas' => 5,
            'limite_upload_mb' => 15,
            'max_megapixels' => 40,
            'sessao_dias' => 7,
            'tamanho_max_documento_kb' => 512,
            'ia' => [
                'provedor' => 'gemini',
                'chave' => '',
                'modelo' => 'gemini-2.5-flash',
                'modelo_reserva' => 'gemini-2.5-flash-lite',
                'tempo_limite' => 90,
                'limite_por_hora' => 30,
            ],
            'pexels' => [
                'chave' => '',
                'limite_por_hora' => 120,
            ],
        ];
    }

    /**
     * Mescla recursiva: mapas são combinados chave a chave; listas e escalares
     * do segundo array substituem os do primeiro.
     */
    public static function mesclar(array $base, array $sobre): array
    {
        foreach ($sobre as $chave => $valor) {
            if (is_array($valor) && isset($base[$chave]) && is_array($base[$chave])
                && !array_is_list($valor) && !array_is_list($base[$chave])) {
                $base[$chave] = self::mesclar($base[$chave], $valor);
            } else {
                $base[$chave] = $valor;
            }
        }
        return $base;
    }

    /**
     * Lê um arquivo de configuração que devolve um array.
     *
     * @throws \RuntimeException arquivo ausente ou que não devolve array
     */
    public static function carregarArquivo(string $arquivo): array
    {
        if (!is_file($arquivo)) {
            throw new \RuntimeException(
                "Configuração não encontrada: {$arquivo}. Copie config/config.exemplo.php para config/config.php."
            );
        }
        $cfg = (static fn (string $f): mixed => require $f)($arquivo);
        if (!is_array($cfg)) {
            throw new \RuntimeException("O arquivo de configuração {$arquivo} precisa devolver um array.");
        }
        return $cfg;
    }

    /** Valor por chave pontuada ("smtp.host"); ausente → $padrao. */
    public static function pegar(array $config, string $chave, mixed $padrao = null): mixed
    {
        if (array_key_exists($chave, $config)) {
            return $config[$chave];
        }
        $atual = $config;
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }
            $atual = $atual[$parte];
        }
        return $atual;
    }
}
