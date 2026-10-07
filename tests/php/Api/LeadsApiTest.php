<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Lib\AmbienteTeste;

final class LeadsApiTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;
    private array $site;

    private const LEAD = ['nome' => 'Maria', 'telefone' => '11988887777', 'mensagem' => 'Olá', '_t' => '5000', 'empresa_site' => ''];

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso Vivo']])->dados()['site'];
        $this->publicar();
    }

    /** Marca o site como publicado (só sites no ar recebem leads). */
    private function publicar(string $status = 'publicado'): void
    {
        $this->app->db()->executar('UPDATE sites SET status = ? WHERE id = ?', [$status, (int) $this->site['id']]);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testLeadPublicoPorFetchRespondeJson(): void
    {
        $visitante = new ClienteApi($this->app);
        $r = $visitante->post('/api/lead/sorrisovivo', self::LEAD + ['utm_source' => 'google']);
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame(['ok' => true], $r->dados());
        $this->assertSame([], $r->cookies(), 'Rota pública não cria sessão');
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        // Pote de mel: mesma resposta, nada gravado.
        $r = $visitante->post('/api/lead/sorrisovivo', ['empresa_site' => 'x'] + self::LEAD);
        $this->assertSame(['ok' => true], $r->dados());
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        // Erro de validação em JSON.
        $r = $visitante->post('/api/lead/sorrisovivo', ['telefone' => '12'] + self::LEAD);
        $this->assertSame(422, $r->status);
        $this->assertFalse($r->dados()['ok']);
        $this->assertSame('telefone', $r->dados()['campo']);
        $this->assertSame(404, $visitante->post('/api/lead/naoexiste', self::LEAD)->status);
    }

    public function testFormularioSemJavascriptRedirecionaParaObrigado(): void
    {
        $visitante = new ClienteApi($this->app);
        $r = $visitante->req('POST', '/api/lead/sorrisovivo', null, ['Accept' => 'text/html'], ['_t' => ''] + self::LEAD);
        $this->assertSame(303, $r->status);
        $this->assertSame('https://sorrisovivo.sites.teste/obrigado/', $r->obterCabecalho('Location'));
        $r = $visitante->req('POST', '/api/lead/sorrisovivo', null, ['Accept' => 'text/html'], ['nome' => ''] + self::LEAD);
        $this->assertSame(422, $r->status);
        $this->assertStringStartsWith('text/html', (string) $r->obterCabecalho('Content-Type'));
        $this->assertStringContainsString('Informe o seu nome.', $r->corpo);
        $this->assertStringContainsString('href="https://sorrisovivo.sites.teste/"', $r->corpo);
        // X-Requested-With também conta como fetch.
        $r = $visitante->req('POST', '/api/lead/sorrisovivo', null, ['X-Requested-With' => 'XMLHttpRequest'], self::LEAD);
        $this->assertSame(['ok' => true], $r->dados());
    }

    public function testLimitePorIp429(): void
    {
        $visitante = new ClienteApi($this->app);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $visitante->post('/api/lead/sorrisovivo', self::LEAD)->status);
        }
        $r = $visitante->post('/api/lead/sorrisovivo', self::LEAD);
        $this->assertSame(429, $r->status);
        $this->assertSame('limite', $r->dados()['erro']['codigo']);
        $this->assertSame('3600', $r->obterCabecalho('Retry-After'));
    }

    /** Regressão: rascunho e arquivado não recebem leads pela API pública (como no /_lead). */
    public function testLeadSoParaSitePublicado(): void
    {
        $visitante = new ClienteApi($this->app);
        foreach (['rascunho', 'arquivado'] as $status) {
            $this->publicar($status);
            $r = $visitante->post('/api/lead/sorrisovivo', self::LEAD);
            $this->assertSame(404, $r->status, $status);
            $this->assertFalse($r->dados()['ok']);
        }
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        $this->assertSame(0, (int) $this->app->db()->valor("SELECT COUNT(*) FROM tarefas WHERE tipo = 'email_lead'"));
    }

    /**
     * Regressão: página de outra origem (ou iframe isolado, Origin "null") não consegue usar o
     * navegador dos visitantes dela para enviar leads ao site — por fetch nem por formulário.
     */
    public function testLeadDeOutraOrigemRecusado(): void
    {
        $visitante = new ClienteApi($this->app);
        foreach (['https://mal.example', 'null', 'https://sorrisovivo.sites.teste.mal.example', 'http://sorrisovivo.sites.teste'] as $origem) {
            $r = $visitante->post('/api/lead/sorrisovivo', self::LEAD, ['Origin' => $origem]);
            $this->assertSame(403, $r->status, $origem);
            $this->assertSame('origem', $r->dados()['erro']['codigo']);
            $this->assertNull($r->obterCabecalho('Access-Control-Allow-Origin'));
            $r = $visitante->req('POST', '/api/lead/sorrisovivo', null, ['Accept' => 'text/html', 'Origin' => $origem], self::LEAD);
            $this->assertSame(403, $r->status);
            $this->assertStringContainsString('text/html', (string) $r->obterCabecalho('Content-Type'));
        }
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        // A própria origem e envios sem Origin continuam.
        $this->assertSame(200, $visitante->post('/api/lead/sorrisovivo', self::LEAD, ['Origin' => 'https://sorrisovivo.sites.teste'])->status);
        $this->assertSame(200, $visitante->post('/api/lead/sorrisovivo', self::LEAD)->status);
    }

    public function testCorsSoParaAOrigemDoSite(): void
    {
        $visitante = new ClienteApi($this->app);
        $r = $visitante->req('OPTIONS', '/api/lead/sorrisovivo', null, ['Origin' => 'https://sorrisovivo.sites.teste']);
        $this->assertSame(204, $r->status);
        $this->assertSame('https://sorrisovivo.sites.teste', $r->obterCabecalho('Access-Control-Allow-Origin'));
        $this->assertSame('POST', $r->obterCabecalho('Access-Control-Allow-Methods'));
        $r = $visitante->req('OPTIONS', '/api/lead/sorrisovivo', null, ['Origin' => 'https://mal.example']);
        $this->assertNull($r->obterCabecalho('Access-Control-Allow-Origin'));
        $r = $visitante->post('/api/lead/sorrisovivo', self::LEAD, ['Origin' => 'https://sorrisovivo.sites.teste']);
        $this->assertSame('https://sorrisovivo.sites.teste', $r->obterCabecalho('Access-Control-Allow-Origin'));
    }

    public function testPainelListaPaginaMarcaLidoExcluiEExporta(): void
    {
        $visitante = new ClienteApi($this->app);
        $inicio = $this->app->agora();
        for ($i = 0; $i < 53; $i++) {
            $visitante->ip = '10.0.' . intdiv($i, 4) . '.' . $i;
            $this->app->definirAgora($inicio->modify("+{$i} minutes"));
            $nome = $i === 52 ? '=1+1' : 'Pessoa ' . $i;
            $this->assertSame(200, $visitante->post('/api/lead/sorrisovivo', ['nome' => $nome] + self::LEAD + ['gclid' => 'g' . $i])->status);
        }
        $p1 = $this->c->get('/api/sites/' . $this->site['id'] . '/leads')->dados();
        $this->assertSame(53, $p1['total']);
        $this->assertSame(53, $p1['naoLidos']);
        $this->assertSame(1, $p1['pagina']);
        $this->assertSame(2, $p1['paginas']);
        $this->assertCount(50, $p1['leads']);
        $primeiro = $p1['leads'][0];
        $this->assertSame('=1+1', $primeiro['nome'], 'Mais recente primeiro');
        $this->assertSame(['gclid' => 'g52'], $primeiro['origem']);
        $this->assertFalse($primeiro['lido']);
        $this->assertStringStartsWith('https://wa.me/5511988887777?text=', $primeiro['whatsappLink']);
        $p2 = $this->c->get('/api/sites/' . $this->site['id'] . '/leads?pagina=2')->dados();
        $this->assertCount(3, $p2['leads']);
        $this->assertSame('Pessoa 0', $p2['leads'][2]['nome']);

        $r = $this->c->patch('/api/leads/' . $primeiro['id'], ['lido' => true]);
        $this->assertSame(200, $r->status);
        $this->assertTrue($r->dados()['lead']['lido']);
        $this->assertSame(52, $this->c->get('/api/sites')->dados()['sites'][0]['leadsNaoLidos']);
        $this->assertFalse($this->c->patch('/api/leads/' . $primeiro['id'], ['lido' => false])->dados()['lead']['lido']);
        $this->assertSame(422, $this->c->patch('/api/leads/' . $primeiro['id'], ['lido' => 'sim'])->status);

        $csv = $this->c->get('/api/sites/' . $this->site['id'] . '/leads.csv');
        $this->assertSame(200, $csv->status);
        $this->assertSame('text/csv; charset=utf-8', $csv->obterCabecalho('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="leads-sorrisovivo-\d{4}-\d\d-\d\d\.csv"$/', (string) $csv->obterCabecalho('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->corpo);
        $this->assertStringContainsString(";'=1+1;", $csv->corpo);
        $this->assertSame(54, substr_count($csv->corpo, "\r\n"));

        $this->assertSame(['ok' => true], $this->c->delete('/api/leads/' . $primeiro['id'])->dados());
        $this->assertSame(404, $this->c->delete('/api/leads/' . $primeiro['id'])->status);
        $this->assertSame(52, $this->c->get('/api/sites/' . $this->site['id'] . '/leads')->dados()['total']);
        $evento = $this->app->db()->um("SELECT detalhe FROM eventos WHERE tipo = 'lead.excluido'");
        $this->assertStringNotContainsString('1+1', (string) $evento['detalhe'], 'Evento de exclusão sem dados pessoais');
    }

    public function testClienteSemAcessoNaoVeLeads(): void
    {
        (new ClienteApi($this->app))->post('/api/lead/sorrisovivo', self::LEAD);
        $leadId = (int) $this->app->db()->valor('SELECT id FROM leads');
        AmbienteTeste::usuario($this->app, 'cliente', 'cli@rankly.teste');
        $cli = new ClienteApi($this->app);
        $cli->login('cli@rankly.teste', 'senha-forte-123');
        $this->assertSame(404, $cli->get('/api/sites/' . $this->site['id'] . '/leads')->status);
        $this->assertSame(404, $cli->get('/api/sites/' . $this->site['id'] . '/leads.csv')->status);
        $this->assertSame(404, $cli->patch('/api/leads/' . $leadId, ['lido' => true])->status);
        $this->assertSame(404, $cli->delete('/api/leads/' . $leadId)->status);
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
    }

    public function testEmailDoLeadSaiLogoAposAResposta(): void
    {
        $visitante = new ClienteApi($this->app);
        $visitante->post('/api/lead/sorrisovivo', self::LEAD);
        $visitante->api->processarTarefasDaRequisicao();
        $this->assertCount(1, AmbienteTeste::emails($this->app));
        $this->assertSame('feita', $this->app->db()->valor("SELECT status FROM tarefas WHERE tipo = 'email_lead'"));
    }
}
