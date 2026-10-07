<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Executores;
use Rankly\Lib\Tarefas;

final class TarefasTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
        $this->app->definirAgora(new \DateTimeImmutable('2026-10-06 12:00:00', new \DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function tarefa(int $id): array
    {
        return $this->app->db()->um('SELECT * FROM tarefas WHERE id = ?', [$id]);
    }

    public function testEnfileiraEProcessaNaOrdemSoTiposRegistrados(): void
    {
        $t = $this->app->tarefas();
        $ordem = [];
        $t->registrar('teste', function (array $p, Aplicacao $app) use (&$ordem): void {
            $ordem[] = $p['n'];
        });
        $a = $t->enfileirar('teste', ['n' => 1]);
        $b = $t->enfileirar('teste', ['n' => 2]);
        $futura = $t->enfileirar('teste', ['n' => 3], $this->app->agora()->modify('+1 hour'));
        $outro = $t->enfileirar('sem_executor', []);
        $this->assertSame(2, $t->processar());
        $this->assertSame([1, 2], $ordem);
        $this->assertSame('feita', $this->tarefa($a)['status']);
        $this->assertSame('feita', $this->tarefa($b)['status']);
        $this->assertSame('pendente', $this->tarefa($futura)['status']);
        $this->assertSame('pendente', $this->tarefa($outro)['status'], 'Tipo sem executor fica na fila');
        $this->app->definirAgora($this->app->agora()->modify('+2 hours'));
        $this->assertSame(1, $t->processar());
        $this->assertSame([1, 2, 3], $ordem);
        $this->assertSame(0, $t->processar());
        $this->assertSame([$a, $b, $futura, $outro], $t->enfileiradasAgora());
    }

    public function testFalhaRepeteComRecuoExponencialAteCincoTentativas(): void
    {
        $t = $this->app->tarefas();
        $t->registrar('instavel', static function (): void {
            throw new \RuntimeException('SMTP fora do ar');
        });
        $id = $t->enfileirar('instavel', ['x' => 1]);
        $esperados = [60, 240, 960, 3840];
        foreach ($esperados as $i => $recuo) {
            $this->assertSame(1, $t->processar());
            $linha = $this->tarefa($id);
            $this->assertSame('pendente', $linha['status']);
            $this->assertSame($i + 1, (int) $linha['tentativas']);
            $this->assertStringContainsString('SMTP fora do ar', $linha['erro']);
            $this->assertSame($this->app->agora()->modify("+{$recuo} seconds")->format('Y-m-d H:i:s'), $linha['executar_em']);
            $this->assertSame(0, $t->processar(), 'Antes do recuo não roda');
            $this->app->definirAgora($this->app->agora()->modify("+{$recuo} seconds"));
        }
        $this->assertSame(1, $t->processar());
        $linha = $this->tarefa($id);
        $this->assertSame('falhou', $linha['status']);
        $this->assertSame(Tarefas::MAX_TENTATIVAS, (int) $linha['tentativas']);
        $this->app->definirAgora($this->app->agora()->modify('+1 day'));
        $this->assertSame(0, $t->processar());
        $this->assertSame(21600, Tarefas::recuoSegundos(10));
    }

    public function testRecuperaFalhaDepoisDeSucesso(): void
    {
        $t = $this->app->tarefas();
        $vezes = 0;
        $t->registrar('volta', function () use (&$vezes): void {
            if (++$vezes === 1) {
                throw new \RuntimeException('temporário');
            }
        });
        $id = $t->enfileirar('volta');
        $t->processar();
        $this->app->definirAgora($this->app->agora()->modify('+61 seconds'));
        $t->processar();
        $this->assertSame('feita', $this->tarefa($id)['status']);
        $this->assertNull($this->tarefa($id)['erro']);
    }

    public function testPayloadSensivelEApagado(): void
    {
        $t = $this->app->tarefas();
        $t->registrar('segredo', static function (array $p): void {
        }, true);
        $id = $t->enfileirar('segredo', ['token' => 'abc']);
        $this->assertStringContainsString('abc', $this->tarefa($id)['payload']);
        $t->processar();
        $this->assertSame('{}', $this->tarefa($id)['payload']);
    }

    public function testTarefaAbandonadaVoltaParaAFila(): void
    {
        $t = $this->app->tarefas();
        $rodou = false;
        $t->registrar('longa', function () use (&$rodou): void {
            $rodou = true;
        });
        $id = $t->enfileirar('longa');
        $this->app->db()->atualizar('tarefas', ['status' => 'executando', 'atualizado_em' => $this->app->agoraSql('-31 minutes')], ['id' => $id]);
        $this->assertSame(1, $t->processar());
        $this->assertTrue($rodou);
        $this->assertSame(1, (int) $this->tarefa($id)['tentativas']);
    }

    public function testDoisProcessosNaoExecutamAMesmaTarefa(): void
    {
        $t1 = new Tarefas($this->app);
        $t2 = new Tarefas($this->app);
        $n = 0;
        $exec = function () use (&$n, $t2): void {
            $n++;
            // Enquanto o primeiro executa, o segundo processo tenta pegar a mesma tarefa.
            $t2->processar();
        };
        $t1->registrar('unica', $exec);
        $t2->registrar('unica', $exec);
        $t1->enfileirar('unica');
        $t1->processar();
        $this->assertSame(1, $n);
    }

    public function testLimpezaDiaria(): void
    {
        $db = $this->app->db();
        $dono = AmbienteTeste::usuario($this->app, 'equipe');
        $site = AmbienteTeste::site($this->app, 'limpo', [], (int) $dono['id']);
        $sid = (int) $site['id'];
        $velho = $this->app->agoraSql('-13 months');
        $recente = $this->app->agoraSql('-11 months');
        foreach ([$velho, $recente] as $quando) {
            $db->inserir('leads', ['site_id' => $sid, 'nome' => 'A', 'telefone' => '1', 'ip_hash' => 'h', 'criado_em' => $quando]);
        }
        $db->inserir('redefinicoes_senha', ['usuario_id' => (int) $dono['id'], 'token_hash' => str_repeat('a', 64), 'expira_em' => $this->app->agoraSql('-2 hours')]);
        $db->inserir('redefinicoes_senha', ['usuario_id' => (int) $dono['id'], 'token_hash' => str_repeat('b', 64), 'expira_em' => $this->app->agoraSql('+20 hours')]);
        $db->inserir('limites', ['chave' => 'velho', 'contagem' => 3, 'janela_inicio' => $this->app->agoraSql('-2 days')]);
        $db->inserir('limites', ['chave' => 'novo', 'contagem' => 3, 'janela_inicio' => $this->app->agoraSql('-1 hour')]);

        // Mídias: usada no documento, usada só numa versão, órfã antiga, órfã recente.
        $midias = ['m_00000001' => '-40 days', 'm_00000002' => '-40 days', 'm_00000003' => '-40 days', 'm_00000004' => '-5 days'];
        foreach ($midias as $id => $idade) {
            $db->inserir('midia', ['id' => $id, 'site_id' => $sid, 'tipo' => 'foto', 'mime' => 'image/jpeg', 'largura' => 10, 'altura' => 10,
                'bytes' => 1, 'variantes' => '[10]', 'hash' => str_repeat('0', 64), 'formato' => 'webp', 'criado_em' => $this->app->agoraSql($idade)]);
            mkdir($this->app->dir('media') . "/{$sid}/{$id}", 0775, true);
            file_put_contents($this->app->dir('media') . "/{$sid}/{$id}/10.webp", 'x');
        }
        $doc = $site['documento'];
        $doc['imagens'] = ['hero.img' => 'm_00000001'];
        $db->atualizar('sites', ['documento' => json_encode($doc)], ['id' => $sid]);
        $docVersao = $doc;
        $docVersao['imagens'] = [];
        $docVersao['dados']['logo'] = 'm_00000002';
        $db->inserir('versoes', ['site_id' => $sid, 'numero' => 1, 'documento' => json_encode($docVersao), 'publicado_em' => $this->app->agoraSql()]);
        $feita = $this->app->tarefas()->enfileirar('qualquer');
        $db->atualizar('tarefas', ['status' => 'feita', 'atualizado_em' => $this->app->agoraSql('-31 days')], ['id' => $feita]);

        Executores::registrarTodos($this->app);
        Executores::agendarLimpeza($this->app);
        Executores::agendarLimpeza($this->app);
        $this->assertSame(1, (int) $db->valor("SELECT COUNT(*) FROM tarefas WHERE tipo = 'limpeza_diaria'"), 'Agendada uma vez só');
        $this->assertSame('2026-10-07 06:00:00', $db->valor("SELECT executar_em FROM tarefas WHERE tipo = 'limpeza_diaria'"));
        $this->app->definirAgora(new \DateTimeImmutable('2026-10-07 06:00:30', new \DateTimeZone('UTC')));
        $this->assertSame(1, $this->app->tarefas()->processar());

        $this->assertSame(1, (int) $db->valor('SELECT COUNT(*) FROM leads'), 'Lead com mais de 12 meses removido');
        $this->assertSame([str_repeat('b', 64)], array_column($db->todos('SELECT token_hash FROM redefinicoes_senha'), 'token_hash'));
        $this->assertSame(['novo'], array_column($db->todos('SELECT chave FROM limites'), 'chave'));
        $restantes = array_column($db->todos('SELECT id FROM midia ORDER BY id'), 'id');
        $this->assertSame(['m_00000001', 'm_00000002', 'm_00000004'], $restantes);
        $this->assertDirectoryDoesNotExist($this->app->dir('media') . "/{$sid}/m_00000003");
        $this->assertDirectoryExists($this->app->dir('media') . "/{$sid}/m_00000004");
        $this->assertNull($db->um('SELECT id FROM tarefas WHERE id = ?', [$feita]));
        // Reagendada para o dia seguinte.
        $this->assertSame('2026-10-08 06:00:00', $db->valor("SELECT executar_em FROM tarefas WHERE tipo = 'limpeza_diaria' AND status = 'pendente'"));
    }
}
