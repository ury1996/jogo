<?php

/**
 * Gerador substituto para testar as rotas de publicação sem depender do agente gerador.
 * Se Rankly\Gerador\ErroValidacao ainda não existir, define-o exatamente como o contrato §11.2.
 */

declare(strict_types=1);

namespace Rankly\Gerador {
    // Carrega o gerador real antes (ele pode declarar ErroValidacao no mesmo arquivo).
    class_exists(Gerador::class);
    if (!class_exists(ErroValidacao::class)) {
        final class ErroValidacao extends \RuntimeException
        {
            public function __construct(public array $erros)
            {
                parent::__construct('Erros de validação.');
            }
        }
    }
}

namespace Rankly\Testes\Api {

    use Rankly\Aplicacao;
    use Rankly\Gerador\ErroValidacao;

    final class GeradorSubstituto
    {
        /** @var list<array{0: string, 1: array}> */
        public array $chamadas = [];
        public array $errosPublicar = [];
        public ?\Throwable $falhaPublicar = null;
        public ?\Throwable $falhaReverter = null;

        public function __construct(private readonly Aplicacao $app)
        {
        }

        public function validar(array $site): array
        {
            $this->chamadas[] = ['validar', $site];
            return ['erros' => [], 'avisos' => [['codigo' => 'foto', 'mensagem' => 'Faltam fotos.']], 'textosPadraoAlterados' => []];
        }

        public function publicar(array $site, int $usuarioId): array
        {
            $this->chamadas[] = ['publicar', $site];
            if ($this->falhaPublicar !== null) {
                throw $this->falhaPublicar;
            }
            if ($this->errosPublicar !== []) {
                throw new ErroValidacao($this->errosPublicar);
            }
            $db = $this->app->db();
            $numero = (int) $db->valor('SELECT COALESCE(MAX(numero), 0) FROM versoes WHERE site_id = ?', [(int) $site['id']]) + 1;
            $agora = $this->app->agoraSql();
            $db->inserir('versoes', [
                'site_id' => (int) $site['id'], 'numero' => $numero, 'documento' => json_encode($site['documento']),
                'resolvidos' => '{}', 'biblioteca_versao' => 'x', 'release' => $numero . '-teste', 'publicado_por' => $usuarioId, 'publicado_em' => $agora,
            ]);
            $db->atualizar('sites', ['status' => 'publicado', 'publicado_versao' => $numero, 'publicado_em' => $agora], ['id' => (int) $site['id']]);
            return ['url' => $this->app->urlSite((string) $site['slug']), 'versao' => $numero];
        }

        public function reverter(array $site, int $usuarioId): array
        {
            $this->chamadas[] = ['reverter', $site];
            if ($this->falhaReverter !== null) {
                throw $this->falhaReverter;
            }
            $anterior = (int) $site['publicado_versao'] - 1;
            $this->app->db()->atualizar('sites', ['publicado_versao' => $anterior], ['id' => (int) $site['id']]);
            return ['url' => $this->app->urlSite((string) $site['slug']), 'versao' => $anterior];
        }
    }
}
