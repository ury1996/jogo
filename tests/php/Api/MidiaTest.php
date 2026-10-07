<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Midia;
use Rankly\Testes\Lib\AmbienteTeste;
use Rankly\Testes\Lib\Imagens;

final class MidiaTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;
    private array $site;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['limite_upload_mb' => 1], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Sorriso']])->dados()['site'];
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function enviar(string $arquivo, string $tipo = 'foto', string $nome = 'foto.jpg'): \Rankly\Http\Resposta
    {
        return $this->c->enviarArquivo('/api/media', ['site_id' => (string) $this->site['id'], 'tipo' => $tipo], $arquivo, 'arquivo', $nome);
    }

    public function testEnvioDeFotoGeraVariantesEServeComSessao(): void
    {
        $r = $this->enviar(Imagens::jpeg($this->dir . '/f.jpg', 1200, 900, 6));
        $this->assertSame(201, $r->status, $r->corpo);
        $m = $r->dados()['midia'];
        $this->assertMatchesRegularExpression('/^m_[0-9a-f]{8}$/', $m['id']);
        $this->assertSame(['id', 'largura', 'altura', 'variantes', 'alt', 'tipo', 'formato'], array_keys($m));
        $this->assertSame([900, 1200], [$m['largura'], $m['altura']], 'Orientação EXIF aplicada');
        $this->assertSame([480, 900], $m['variantes']);
        $this->assertSame('foto', $m['tipo']);
        $this->assertSame('webp', $m['formato']);
        $pasta = $this->app->dir('media') . '/' . $this->site['id'] . '/' . $m['id'];
        $this->assertFileExists($pasta . '/orig.jpg');
        $this->assertFileExists($pasta . '/480.webp');
        $this->assertFileExists($pasta . '/900.webp');

        $v = $this->c->get('/api/media/' . $m['id'] . '/480');
        $this->assertSame(200, $v->status);
        $this->assertSame('image/webp', $v->obterCabecalho('Content-Type'));
        $this->assertSame('private, max-age=31536000, immutable', $v->obterCabecalho('Cache-Control'));
        $this->assertSame('nosniff', $v->obterCabecalho('X-Content-Type-Options'));
        $this->assertSame('image/jpeg', $this->c->get('/api/media/' . $m['id'] . '/orig')->obterCabecalho('Content-Type'));
        $this->assertSame(404, $this->c->get('/api/media/' . $m['id'] . '/1600')->status, 'Variante inexistente');
        $this->assertSame(404, $this->c->get('/api/media/m_00000000/480')->status);

        // Mapa do site no GET.
        $mapa = $this->c->get('/api/sites/' . $this->site['id'])->dados()['midia'];
        $this->assertSame(['largura' => 900, 'altura' => 1200, 'variantes' => [480, 900], 'alt' => '', 'tipo' => 'foto', 'formato' => 'webp'], $mapa[$m['id']]);

        // Sem sessão: 401; outro usuário sem acesso: 404.
        $anonimo = new ClienteApi($this->app);
        $this->assertSame(401, $anonimo->get('/api/media/' . $m['id'] . '/480')->status);
        AmbienteTeste::usuario($this->app, 'cliente', 'cli@rankly.teste');
        $cli = new ClienteApi($this->app);
        $cli->login('cli@rankly.teste', 'senha-forte-123');
        $this->assertSame(404, $cli->get('/api/media/' . $m['id'] . '/480')->status);
    }

    public function testMesmoArquivoNoMesmoSiteEDeduplicado(): void
    {
        $arquivo = Imagens::jpeg($this->dir . '/d.jpg', 640, 480);
        $a = $this->enviar($arquivo)->dados()['midia'];
        $b = $this->enviar($arquivo)->dados()['midia'];
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int) $this->app->db()->valor('SELECT COUNT(*) FROM midia'));
        // Como logo é outra mídia.
        $this->assertNotSame($a['id'], $this->enviar($arquivo, 'logo')->dados()['midia']['id']);
    }

    public function testLogoSvgLimpoServidoComCsp(): void
    {
        $svg = $this->dir . '/logo.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#123456"/></svg>');
        $r = $this->enviar($svg, 'logo', 'logo.svg');
        $this->assertSame(201, $r->status, $r->corpo);
        $m = $r->dados()['midia'];
        $this->assertSame('svg', $m['formato']);
        $this->assertSame([], $m['variantes']);
        $this->assertSame([300, 100], [$m['largura'], $m['altura']]);
        $v = $this->c->get('/api/media/' . $m['id'] . '/orig');
        $this->assertSame(200, $v->status);
        $this->assertSame('image/svg+xml', $v->obterCabecalho('Content-Type'));
        $this->assertStringContainsString("script-src 'none'", (string) $v->obterCabecalho('Content-Security-Policy'));
        $this->assertSame(404, $this->c->get('/api/media/' . $m['id'] . '/320')->status);
    }

    public function testSvgMaliciosoESvgComoFotoSaoRecusados(): void
    {
        $svg = $this->dir . '/mal.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');
        $r = $this->enviar($svg, 'logo', 'mal.svg');
        $this->assertSame(422, $r->status);
        $this->assertSame('midia_invalida', $r->dados()['erro']['codigo']);
        $this->assertStringContainsString('scripts', $r->dados()['erro']['mensagem']);

        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        $r = $this->enviar($svg, 'foto', 'ok.svg');
        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM midia'));
        $this->assertSame([], glob($this->app->dir('media') . '/' . $this->site['id'] . '/*') ?: []);
        $this->assertSame([], glob($this->app->dir('media') . '/' . $this->site['id'] . '/.tmp-*') ?: [], 'Pasta temporária removida');
    }

    public function testHeicGifETamanho(): void
    {
        $r = $this->enviar(Imagens::heic($this->dir . '/IMG_0001.HEIC'), 'foto', 'IMG_0001.HEIC');
        $this->assertSame(422, $r->status);
        $this->assertSame('Formato HEIC não suportado. No iPhone, ajuste Câmera > Formatos > Mais compatível, ou envie JPG.', $r->dados()['erro']['mensagem']);
        $this->assertSame(422, $this->enviar(Imagens::gif($this->dir . '/a.gif'), 'foto', 'a.gif')->status);
        $grande = $this->dir . '/grande.jpg';
        file_put_contents($grande, str_repeat('x', 1024 * 1024 + 10));
        $r = $this->enviar($grande);
        $this->assertSame(413, $r->status);
        $this->assertStringContainsString('1 MB', $r->dados()['erro']['mensagem']);
    }

    public function testErrosDeFormulario(): void
    {
        $arquivo = Imagens::jpeg($this->dir . '/f.jpg', 100, 100);
        $this->assertSame(422, $this->c->enviarArquivo('/api/media', ['tipo' => 'foto'], $arquivo)->status);
        $this->assertSame(404, $this->c->enviarArquivo('/api/media', ['site_id' => '999', 'tipo' => 'foto'], $arquivo)->status);
        $this->assertSame(422, $this->c->enviarArquivo('/api/media', ['site_id' => (string) $this->site['id'], 'tipo' => 'banner'], $arquivo)->status);
        $r = $this->c->req('POST', '/api/media', null, [], ['site_id' => (string) $this->site['id']], ['arquivo' => ['name' => 'x', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]);
        $this->assertSame(413, $r->status);
        // Corpo acima do post_max_size: o PHP descarta tudo, mas o Content-Length denuncia.
        $r = $this->c->req('POST', '/api/media', null, ['Content-Length' => '30000000', 'Content-Type' => 'multipart/form-data; boundary=x']);
        $this->assertSame(413, $r->status);
        // Sem CSRF o upload é recusado.
        $this->c->enviarCsrf = false;
        $this->assertSame(403, $this->c->enviarArquivo('/api/media', ['site_id' => (string) $this->site['id']], $arquivo)->status);
    }

    public function testTextoAlternativo(): void
    {
        $m = $this->enviar(Imagens::jpeg($this->dir . '/f.jpg', 500, 500))->dados()['midia'];
        $r = $this->c->patch('/api/media/' . $m['id'], ['alt' => '  Recepção   da clínica ']);
        $this->assertSame(200, $r->status);
        $this->assertSame('Recepção da clínica', $r->dados()['midia']['alt']);
        $this->assertSame('Recepção da clínica', Midia::mapaDoSite($this->app, (int) $this->site['id'])[$m['id']]['alt']);
        $this->assertSame(422, $this->c->patch('/api/media/' . $m['id'], ['alt' => str_repeat('a', 161)])->status);
        $this->assertSame('', $this->c->patch('/api/media/' . $m['id'], ['alt' => ''])->dados()['midia']['alt']);
    }

    public function testCaminhoDeMidiaValidaNomes(): void
    {
        $this->assertSame($this->app->dir('media') . '/3/m_0a1b2c3d/480.webp', Midia::caminho($this->app, 3, 'm_0a1b2c3d', '480.webp'));
        $this->expectException(\InvalidArgumentException::class);
        Midia::caminho($this->app, 3, 'm_0a1b2c3d', '../../config.php');
    }
}
