<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Rotas de publicação e versões com um gerador substituto (o contrato §11.2 é o que importa
 * aqui). Se o gerador real existir, um teste de integração também roda.
 */
final class PublicacaoTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;
    private array $site;

    protected function setUp(): void
    {
        require_once __DIR__ . '/gerador-substituto.php';
        $this->app = AmbienteTeste::criar([], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso Vivo']])->dados()['site'];
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testValidarPublicarEReverterComGeradorSubstituto(): void
    {
        $g = new GeradorSubstituto($this->app);
        $this->app->definirGerador($g);
        $id = $this->site['id'];

        $r = $this->c->post("/api/sites/{$id}/validar");
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame(['erros' => [], 'avisos' => [['codigo' => 'foto', 'mensagem' => 'Faltam fotos.']], 'textosPadraoAlterados' => []], $r->dados());
        $this->assertSame((int) $id, $g->chamadas[0][1]['id'], 'Recebe a linha do site (Sites::porId)');
        $this->assertIsArray($g->chamadas[0][1]['documento']);

        $r = $this->c->post("/api/sites/{$id}/publicar");
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame(['url' => 'https://sorrisovivo.sites.teste', 'versao' => 1], $r->dados());
        $this->assertSame('publicado', $this->c->get("/api/sites/{$id}")->dados()['site']['status']);
        $this->c->post("/api/sites/{$id}/publicar");

        $versoes = $this->c->get("/api/sites/{$id}/versoes")->dados()['versoes'];
        $this->assertSame([2, 1], array_column($versoes, 'numero'));
        $this->assertSame('Admin Teste', $versoes[0]['publicadoPor']);
        $this->assertTrue($versoes[0]['atual']);
        $this->assertMatchesRegularExpression('/Z$/', $versoes[0]['publicadoEm']);
        $v1 = $this->c->get("/api/sites/{$id}/versoes/1")->dados();
        $this->assertSame(1, $v1['numero']);
        $this->assertSame(2, $v1['documento']['versaoEsquema']);
        $this->assertStringContainsString('"textos":{}', $this->c->get("/api/sites/{$id}/versoes/1")->corpo);
        $this->assertSame(404, $this->c->get("/api/sites/{$id}/versoes/9")->status);

        $r = $this->c->post("/api/sites/{$id}/reverter");
        $this->assertSame(['url' => 'https://sorrisovivo.sites.teste', 'versao' => 1], $r->dados());
        $tipos = array_column($this->app->db()->todos('SELECT tipo FROM eventos WHERE site_id = ? ORDER BY id', [$id]), 'tipo');
        $this->assertSame(['site.criado', 'site.publicado', 'site.publicado', 'site.revertido'], $tipos);
    }

    public function testErroDeValidacaoNaPublicacao422(): void
    {
        $g = new GeradorSubstituto($this->app);
        $g->errosPublicar = [['codigo' => 'whatsapp', 'mensagem' => 'Falta o WhatsApp. Ele é usado em todos os botões de contato.']];
        $this->app->definirGerador($g);
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/publicar');
        $this->assertSame(422, $r->status);
        $d = $r->dados();
        $this->assertSame('validacao', $d['erro']['codigo']);
        $this->assertSame('whatsapp', $d['erros'][0]['codigo']);
    }

    public function testErrosDoGeradorSemVazarDetalhes(): void
    {
        $g = new GeradorSubstituto($this->app);
        $g->falhaReverter = new \DomainException('Não há publicação anterior para voltar.');
        $g->falhaPublicar = new \RuntimeException('disco cheio em /var/www/segredo');
        $this->app->definirGerador($g);
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/reverter');
        $this->assertSame(422, $r->status);
        $this->assertSame('Não há publicação anterior para voltar.', $r->dados()['erro']['mensagem']);
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/publicar');
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('/var/www', $r->corpo);
        $this->assertStringContainsString('disco cheio', (string) file_get_contents($this->app->log()->arquivo()), 'Detalhe só no log');
    }

    public function testSemGeradorResponde503(): void
    {
        if (class_exists('Rankly\\Gerador\\Gerador')) {
            $this->markTestSkipped('O gerador real existe neste repositório.');
        }
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/validar');
        $this->assertSame(503, $r->status);
        $this->assertSame('indisponivel', $r->dados()['erro']['codigo']);
    }

    public function testPublicacaoExigeCsrfEAcesso(): void
    {
        $this->app->definirGerador(new GeradorSubstituto($this->app));
        AmbienteTeste::usuario($this->app, 'cliente', 'cli@rankly.teste');
        $cli = new ClienteApi($this->app);
        $cli->login('cli@rankly.teste', 'senha-forte-123');
        $this->assertSame(404, $cli->post('/api/sites/' . $this->site['id'] . '/publicar')->status);
        $this->assertSame(404, $cli->get('/api/sites/' . $this->site['id'] . '/versoes')->status);
        $this->c->enviarCsrf = false;
        $this->assertSame(403, $this->c->post('/api/sites/' . $this->site['id'] . '/publicar')->status);
    }

    /** Integração com o gerador real (agente gerador), quando ele existir. */
    public function testIntegracaoComGeradorReal(): void
    {
        if (!class_exists('Rankly\\Gerador\\Gerador')) {
            $this->markTestSkipped('Rankly\\Gerador\\Gerador ainda não existe.');
        }
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/validar');
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertArrayHasKey('erros', $r->dados());
        $this->assertArrayHasKey('avisos', $r->dados());
        $this->assertArrayHasKey('textosPadraoAlterados', $r->dados());
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/publicar');
        $this->assertContains($r->status, [200, 422], $r->corpo);
        if ($r->status === 422) {
            $this->assertNotEmpty($r->dados()['erros']);
        } else {
            $this->assertSame(1, $r->dados()['versao']);
        }
    }
}
