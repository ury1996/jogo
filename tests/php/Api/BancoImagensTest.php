<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Pixabay;
use Rankly\Testes\Lib\AmbienteTeste;
use Rankly\Testes\Lib\Imagens;
use Rankly\Testes\Lib\PixabayTest as FotosPixabay;

/** Rotas do banco de imagens: disponibilidade, busca, importação para a mídia do site e limite por hora. */
final class BancoImagensTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;
    private array $site;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['pixabay' => ['chave' => '', 'limite_por_hora' => 3]], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso']])->dados()['site'];
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    /** Pixabay com transporte falso: API (busca e foto por id) e imagem (um JPEG de verdade). */
    private function ligarBanco(int $statusApi = 200): void
    {
        $jpeg = (string) file_get_contents(Imagens::jpeg($this->dir . '/banco.jpg', 1200, 800));
        $this->app->definirBancoImagens(new Pixabay('chave-teste', null, 10, function (string $url) use ($jpeg, $statusApi): array {
            $this->urls[] = $url;
            if (str_starts_with($url, 'https://pixabay.com/get/')) {
                return [200, $jpeg, 'image/jpeg'];
            }
            if ($statusApi !== 200) {
                return [$statusApi, ''];
            }
            if (preg_match('#[?&]id=(\d+)#', $url, $m)) {
                return [200, json_encode(['totalHits' => 1, 'hits' => [FotosPixabay::fotoBruta((int) $m[1], 1200, 800)]])];
            }
            return [200, json_encode(['totalHits' => 1, 'hits' => [FotosPixabay::fotoBruta(10)]])];
        }));
    }

    public function testSemLoginResponde401(): void
    {
        $this->ligarBanco();
        $anonimo = new ClienteApi($this->app);
        $this->assertSame(401, $anonimo->get('/api/banco-imagens')->status);
        $this->assertSame(401, $anonimo->get('/api/banco-imagens/buscar?q=dentista')->status);
        $this->assertSame(401, $anonimo->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 10])->status);
        $this->assertSame([], $this->urls, 'Nada sai para o Pixabay sem login');
    }

    public function testSemChaveFicaIndisponivel(): void
    {
        $this->app->definirBancoImagens(null);
        $this->assertSame(['disponivel' => false], $this->c->get('/api/banco-imagens')->dados());
        $r = $this->c->get('/api/banco-imagens/buscar?q=dentista');
        $this->assertSame(503, $r->status);
        $this->assertSame('banco_indisponivel', $r->dados()['erro']['codigo']);
        $this->assertStringContainsString('PIXABAY_API_KEY', $r->dados()['erro']['mensagem']);
        $this->assertSame(503, $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 10])->status);

        $this->ligarBanco();
        $this->assertSame(['disponivel' => true], $this->c->get('/api/banco-imagens')->dados());
    }

    public function testBuscaDevolveFotosNormalizadas(): void
    {
        $this->ligarBanco();
        $r = $this->c->get('/api/banco-imagens/buscar?q=' . rawurlencode('consultório odontológico') . '&pagina=2&orientacao=paisagem');
        $this->assertSame(200, $r->status, $r->corpo);
        $d = $r->dados();
        $this->assertSame('consultório odontológico', $d['termo']);
        $this->assertSame(2, $d['pagina']);
        $this->assertFalse($d['temMais']);
        $this->assertSame(10, $d['fotos'][0]['id']);
        $this->assertSame('AnaSouza', $d['fotos'][0]['autor']);
        $this->assertStringContainsString('orientation=horizontal', $this->urls[0]);

        $this->assertSame(422, $this->c->get('/api/banco-imagens/buscar?q=%20')->status);
        $this->assertSame(422, $this->c->get('/api/banco-imagens/buscar?q=x&orientacao=diagonal')->status);
    }

    public function testImportarCriaMidiaComOrigemECredito(): void
    {
        $this->ligarBanco();
        $r = $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => '3845810']);
        $this->assertSame(201, $r->status, $r->corpo);
        $m = $r->dados()['midia'];
        $this->assertMatchesRegularExpression('/^m_[0-9a-f]{8}$/', $m['id']);
        $this->assertSame(['id', 'largura', 'altura', 'variantes', 'alt', 'tipo', 'formato', 'origem', 'credito'], array_keys($m));
        $this->assertSame('foto', $m['tipo']);
        $this->assertSame([1200, 800], [$m['largura'], $m['altura']]);
        $this->assertSame('pixabay:3845810', $m['origem']);
        $this->assertSame('Imagem de AnaSouza por Pixabay', $m['credito']);
        $this->assertSame('', $m['alt'], 'O alt fica o do espaço da foto');
        // Baixou a versão grande do host permitido; o arquivo temporário foi apagado.
        $this->assertSame('https://pixabay.com/get/g3845810_1280.jpg', end($this->urls));
        $this->assertSame([], glob($this->app->dir('var') . '/tmp/banco-*') ?: []);
        $this->assertFileExists($this->app->dir('media') . '/' . $this->site['id'] . '/' . $m['id'] . '/480.webp');

        // A mídia aparece no mapa do site (com origem e crédito) e é servida como qualquer upload.
        $mapa = $this->c->get('/api/sites/' . $this->site['id'])->dados()['midia'];
        $this->assertSame('Imagem de AnaSouza por Pixabay', $mapa[$m['id']]['credito']);
        $this->assertSame(200, $this->c->get('/api/media/' . $m['id'] . '/480')->status);
        $evento = $this->app->db()->um("SELECT * FROM eventos WHERE tipo = 'midia.banco'");
        $this->assertNotNull($evento);

        // Mesma foto de novo: devolve a mesma mídia.
        $de_novo = $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 3845810])->dados()['midia'];
        $this->assertSame($m['id'], $de_novo['id']);
    }

    public function testImportarExigeAcessoAoSiteECsrf(): void
    {
        $this->ligarBanco();
        $this->assertSame(422, $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 'abc'])->status);
        $this->assertSame(422, $this->c->post('/api/banco-imagens/importar', ['foto_id' => 1])->status);

        AmbienteTeste::usuario($this->app, 'cliente', 'cli@rankly.teste');
        $cli = new ClienteApi($this->app);
        $cli->login('cli@rankly.teste', 'senha-forte-123');
        $this->assertSame(404, $cli->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 10])->status);

        $this->c->enviarCsrf = false;
        $this->assertSame(403, $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 10])->status);
        $this->assertSame([], $this->urls);
    }

    public function testLimitePorHoraContaBuscasEImportacoes(): void
    {
        $this->ligarBanco();
        $this->assertSame(200, $this->c->get('/api/banco-imagens/buscar?q=dentista')->status);
        $this->assertSame(200, $this->c->get('/api/banco-imagens/buscar?q=dentista&pagina=2')->status);
        $this->assertSame(201, $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 10])->status);
        $r = $this->c->get('/api/banco-imagens/buscar?q=dentista&pagina=3');
        $this->assertSame(429, $r->status);
        $this->assertSame('limite', $r->dados()['erro']['codigo']);
        $this->assertSame(429, $this->c->post('/api/banco-imagens/importar', ['site_id' => $this->site['id'], 'foto_id' => 11])->status);

        // Uma hora depois, libera de novo.
        $this->app->definirAgora(new \DateTimeImmutable('+61 minutes'));
        $this->assertSame(200, $this->c->get('/api/banco-imagens/buscar?q=dentista')->status);
    }

    public function testCotaDoPixabayEstouradaNaoGastaOLimiteDoUsuario(): void
    {
        $this->ligarBanco(429);
        for ($i = 0; $i < 4; $i++) {
            $r = $this->c->get('/api/banco-imagens/buscar?q=dentista');
            $this->assertSame(429, $r->status);
            $this->assertStringContainsString('limite de buscas no banco de imagens', $r->dados()['erro']['mensagem']);
        }
        $this->ligarBanco();
        $this->assertSame(200, $this->c->get('/api/banco-imagens/buscar?q=dentista')->status);
    }
}
