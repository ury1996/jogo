<?php

declare(strict_types=1);

namespace Rankly\Lib;

use Rankly\Aplicacao;

/**
 * Registro de quem mudou o quê [M27] (tabela eventos). Salvamentos automáticos do mesmo
 * usuário no mesmo site em até 10 minutos são agrupados num único evento.
 */
final class Eventos
{
    private const AGRUPAR_SEGUNDOS = 600;
    private const MAX_CHAVES = 60;

    public static function registrar(Aplicacao $app, ?int $siteId, ?int $usuarioId, string $tipo, array $detalhe = []): void
    {
        try {
            $app->db()->inserir('eventos', [
                'site_id' => $siteId,
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'detalhe' => $detalhe === [] ? null : self::json($detalhe),
                'criado_em' => $app->agoraSql(),
            ]);
        } catch (\Throwable $e) {
            // auditoria nunca impede a operação principal
            $app->log()->excecao($e, ['evento' => $tipo]);
        }
    }

    /**
     * Salvamento do documento: junta as partes alteradas no evento recente do mesmo
     * usuário (se houver) ou cria um novo.
     *
     * @param array<string, list<string>|true> $alteracoes parte do documento → chaves alteradas
     */
    public static function registrarSalvamento(Aplicacao $app, int $siteId, int $usuarioId, int $revisao, array $alteracoes): void
    {
        try {
            $db = $app->db();
            $recente = $db->um(
                "SELECT id, detalhe, criado_em FROM eventos WHERE site_id = ? AND tipo = 'site.salvo' ORDER BY id DESC LIMIT 1",
                [$siteId],
            );
            if ($recente !== null) {
                $detalhe = json_decode((string) $recente['detalhe'], true);
                $inicio = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $recente['criado_em'], new \DateTimeZone('UTC'));
                $mesmoUsuario = is_array($detalhe) && (int) ($detalhe['usuario'] ?? 0) === $usuarioId;
                if ($mesmoUsuario && $inicio !== false && $app->agora()->getTimestamp() - $inicio->getTimestamp() <= self::AGRUPAR_SEGUNDOS) {
                    $detalhe['revisaoFinal'] = $revisao;
                    $detalhe['alteracoes'] = self::juntar((array) ($detalhe['alteracoes'] ?? []), $alteracoes);
                    $db->atualizar('eventos', ['detalhe' => self::json($detalhe)], ['id' => (int) $recente['id']]);
                    return;
                }
            }
            self::registrar($app, $siteId, $usuarioId, 'site.salvo', [
                'usuario' => $usuarioId,
                'revisaoInicial' => $revisao,
                'revisaoFinal' => $revisao,
                'alteracoes' => self::juntar([], $alteracoes),
            ]);
        } catch (\Throwable $e) {
            $app->log()->excecao($e, ['evento' => 'site.salvo']);
        }
    }

    /**
     * Partes alteradas entre dois documentos: textos/imagens/icones/listas por chave;
     * demais partes (estilo, dados, secoes…) por subcampo ou como um todo.
     *
     * @return array<string, list<string>|true>
     */
    public static function diferencas(array $antes, array $depois): array
    {
        $r = [];
        $partes = array_unique(array_merge(array_keys($antes), array_keys($depois)));
        foreach ($partes as $parte) {
            $a = $antes[$parte] ?? null;
            $d = $depois[$parte] ?? null;
            if ($a === $d) {
                continue;
            }
            if (in_array($parte, ['textos', 'imagens', 'icones', 'listas', 'estilo', 'dados', 'rastreamento', 'seo'], true)
                && (is_array($a) || $a === null) && (is_array($d) || $d === null)) {
                $a = (array) $a;
                $d = (array) $d;
                $chaves = [];
                foreach (array_unique(array_merge(array_keys($a), array_keys($d))) as $k) {
                    if (($a[$k] ?? null) !== ($d[$k] ?? null)) {
                        $chaves[] = (string) $k;
                    }
                }
                if ($chaves !== []) {
                    $r[(string) $parte] = $chaves;
                }
            } else {
                $r[(string) $parte] = true;
            }
        }
        return $r;
    }

    private static function juntar(array $base, array $novas): array
    {
        foreach ($novas as $parte => $chaves) {
            if ($chaves === true || ($base[$parte] ?? null) === true) {
                $base[$parte] = true;
                continue;
            }
            $lista = array_values(array_unique(array_merge((array) ($base[$parte] ?? []), (array) $chaves)));
            $base[$parte] = count($lista) > self::MAX_CHAVES ? array_slice($lista, 0, self::MAX_CHAVES) : $lista;
        }
        return $base;
    }

    private static function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
