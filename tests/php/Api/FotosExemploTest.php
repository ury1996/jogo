<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Lib\AmbienteTeste;

/** Fotos de exemplo da biblioteca: o site nasce com fotos (mídias do próprio site) e dá para completar depois. */
final class FotosExemploTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar(['dir_biblioteca' => dirname(__DIR__, 3) . '/biblioteca'], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testSiteNasceComFotosDeExemploComoMidiasDoSite(): void
    {
        $r = $this->c->post('/api/sites', ['nicho' => 'advocacia', 'modelo' => 'retrato', 'dados' => ['nome' => 'Moraes Advocacia']]);
        $this->assertSame(201, $r->status, $r->corpo);
        $site = $r->dados()['site'];
        $midia = $r->dados()['midia'];
        $imagens = $site['documento']['imagens'];
        $this->assertArrayHasKey('hero.img', $imagens);
        $this->assertArrayHasKey('equipe.1.f', $imagens);
        // retrato: a pessoa do destaque é a mesma do 1º da equipe (nome no cartão)
        $this->assertSame($imagens['equipe.1.f'], $imagens['hero.img']);
        foreach ($imagens as $id) {
            $this->assertMatchesRegularExpression('/^m_[0-9a-f]{8}$/', $id);
            $this->assertStringStartsWith('exemplo:', $midia[$id]['origem']);
            $this->assertSame([480, 960, 1600], $midia[$id]['variantes']);
        }
        // a mesma foto em dois espaços vira uma mídia só
        $this->assertSame(count(array_unique($imagens)), count($midia));
        $servida = $this->c->get('/api/media/' . $imagens['hero.img'] . '/960');
        $this->assertSame(200, $servida->status);
        $this->assertSame('RIFF', (string) file_get_contents((string) $servida->arquivo, false, null, 0, 4));

        $sem = $this->c->post('/api/sites', ['nicho' => 'advocacia', 'modelo' => 'retrato', 'fotosExemplo' => false, 'dados' => ['nome' => 'Sem Fotos']]);
        $this->assertSame([], (array) $sem->dados()['site']['documento']['imagens']);
    }

    public function testCompletaEspacosVaziosEPublicaComAviso(): void
    {
        $site = $this->c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'vital', 'dados' => ['nome' => 'Clínica Teste', 'whatsapp' => '(11) 98765-4321']])->dados()['site'];
        $doc = $site['documento'];
        unset($doc['imagens']['hero.img'], $doc['imagens']['sobre.img']);
        $salvo = $this->c->put('/api/sites/' . $site['id'], ['revisao' => 1, 'documento' => $doc]);
        $this->assertSame(200, $salvo->status, $salvo->corpo);

        $this->assertSame(409, $this->c->post('/api/sites/' . $site['id'] . '/fotos-exemplo', ['revisao' => 1])->status);
        $r = $this->c->post('/api/sites/' . $site['id'] . '/fotos-exemplo', ['revisao' => 2]);
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame(2, $r->dados()['preenchidos']);
        $this->assertSame(3, $r->dados()['revisao']);
        $this->assertArrayHasKey('hero.img', $r->dados()['documento']['imagens']);
        $de = $this->c->post('/api/sites/' . $site['id'] . '/fotos-exemplo', ['revisao' => 3]);
        $this->assertSame(0, $de->dados()['preenchidos'], 'nada a completar: mesma revisão');
        $this->assertSame(3, $de->dados()['revisao']);

        $v = $this->c->post('/api/sites/' . $site['id'] . '/validar', [])->dados();
        $this->assertContains('fotos_exemplo', array_column($v['avisos'], 'codigo'));
    }

    public function testFotoDeExemploPublicaParaAsPrevias(): void
    {
        $r = $this->c->get('/api/fotos-exemplo/adv-estatua-480.webp');
        $this->assertSame(200, $r->status);
        $this->assertSame(404, $this->c->get('/api/fotos-exemplo/../config-480.webp')->status);
        $this->assertSame(404, $this->c->get('/api/fotos-exemplo/nao-existe-480.webp')->status);
    }
}
