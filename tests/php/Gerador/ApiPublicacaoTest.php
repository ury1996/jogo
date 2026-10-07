<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Api\ClienteApi;
use Rankly\Testes\Lib\AmbienteTeste;

/**
 * Publicação pelas rotas da API (POST /validar, /publicar, /reverter) com o gerador real.
 */
final class ApiPublicacaoTest extends TestCase
{
    private ?string $dir = null;
    private Aplicacao $app;
    private ClienteApi $c;

    protected function setUp(): void
    {
        $this->app = SiteExemplo::app([], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testValidarPublicarEReverterPelaApi(): void
    {
        $r = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'especialidade' => 'odontologia', 'modelo' => 'moderno', 'dados' => SiteExemplo::dados()]);
        $this->assertSame(201, $r->status, $r->corpo);
        $id = (int) $r->dados()['site']['id'];

        $v = $this->c->post("/api/sites/{$id}/validar");
        $this->assertSame(200, $v->status, $v->corpo);
        $grupos = array_column(array_filter($v->dados()['erros'], static fn (array $e): bool => $e['codigo'] === 'alegacao_padrao'), 'grupo');
        $this->assertContains('dep', $grupos, 'depoimentos de exemplo bloqueiam');

        $p = $this->c->post("/api/sites/{$id}/publicar");
        $this->assertSame(422, $p->status);
        $this->assertSame('validacao', $p->dados()['erro']['codigo']);
        $this->assertNotEmpty($p->dados()['erros']);

        // O editor resolve as pendências e salva.
        $site = $this->c->get("/api/sites/{$id}")->dados()['site'];
        $doc = json_decode(json_encode($site['documento']), true);
        $doc['confirmados'] = ['num', 'cli', 'aval'];
        foreach (['1', '2', '3'] as $i) {
            $doc['textos']["dep.$i.t"] = "Fui muito bem atendida ({$i}).";
            $doc['textos']["dep.$i.n"] = "Paciente {$i}";
        }
        $s = $this->c->put("/api/sites/{$id}", ['revisao' => $site['revisao'], 'documento' => $doc]);
        $this->assertSame(200, $s->status, $s->corpo);

        $v = $this->c->post("/api/sites/{$id}/validar");
        $this->assertSame([], $v->dados()['erros']);
        $p = $this->c->post("/api/sites/{$id}/publicar");
        $this->assertSame(200, $p->status, $p->corpo);
        $this->assertSame(1, $p->dados()['versao']);
        $slug = $site['slug'];
        $this->assertSame('https://' . $slug . '.sites.teste', $p->dados()['url']);
        $this->assertFileExists($this->dir . '/sites/' . $slug . '/index.html');

        $this->assertSame(2, $this->c->post("/api/sites/{$id}/publicar")->dados()['versao']);
        $versoes = $this->c->get("/api/sites/{$id}/versoes")->dados()['versoes'];
        $this->assertSame([2, 1], array_column($versoes, 'numero'));

        $volta = $this->c->post("/api/sites/{$id}/reverter");
        $this->assertSame(200, $volta->status, $volta->corpo);
        $this->assertSame(1, $volta->dados()['versao']);
        $this->assertSame(1, $this->c->get("/api/sites/{$id}")->dados()['site']['publicadoVersao']);

        $denovo = $this->c->post("/api/sites/{$id}/reverter");
        $this->assertSame(422, $denovo->status);
        $this->assertSame('Não há uma publicação anterior guardada para voltar.', $denovo->dados()['erro']['mensagem']);
    }
}
