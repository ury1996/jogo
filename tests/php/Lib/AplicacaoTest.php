<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Config;

final class AplicacaoTest extends TestCase
{
    public function testConfigComPadroesEChavesComPonto(): void
    {
        $app = Aplicacao::iniciar(['smtp' => ['host' => 'smtp.exemplo.com'], 'dir_var' => '/tmp/rk-var', 'db' => ['dsn' => 'sqlite::memory:']]);
        $this->assertSame('smtp.exemplo.com', $app->config('smtp.host'));
        $this->assertSame(587, $app->config('smtp.porta'), 'Padrão mantido na mescla');
        $this->assertSame(12, $app->config('retencao_leads_meses'));
        $this->assertSame(15, $app->config('limite_upload_mb'));
        $this->assertSame(40, $app->config('max_megapixels'));
        $this->assertFalse($app->config('confiar_cloudflare'));
        $this->assertSame(5, $app->config('releases_mantidas'));
        $this->assertSame('x', $app->config('nao.existe', 'x'));
        $this->assertSame('/tmp/rk-var', $app->dir('var'));
        $this->assertSame($app->raiz() . '/sites', $app->dir('sites'));
        $this->assertSame($app->raiz() . '/biblioteca', $app->dir('biblioteca'));
        $this->assertSame('sqlite', $app->db()->driver());
        $this->assertSame('http://slug.localhost:8081', $app->urlSite('slug'));
        $this->expectException(\InvalidArgumentException::class);
        $app->dir('config');
    }

    public function testRelogioFixavelEmUtc(): void
    {
        $app = Aplicacao::iniciar(['db' => ['dsn' => 'sqlite::memory:']]);
        $this->assertSame('UTC', $app->agora()->getTimezone()->getName());
        $app->definirAgora(new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('America/Sao_Paulo')));
        $this->assertSame('2026-01-02 06:04:05', $app->agoraSql());
        $this->assertSame('2026-01-02 07:04:05', $app->agoraSql('+1 hour'));
        $app->definirAgora(null);
        $this->assertLessThan(5, abs(time() - $app->agora()->getTimestamp()));
    }

    public function testSegredoIpObrigatorioEmProducao(): void
    {
        $dev = Aplicacao::iniciar(['segredo_app' => 'a', 'segredo_ip' => '']);
        $this->assertSame('a', $dev->segredoIp());
        $prod = Aplicacao::iniciar(['ambiente' => 'prod', 'segredo_app' => 'a', 'segredo_ip' => '']);
        $this->expectException(\RuntimeException::class);
        $prod->segredoIp();
    }

    /** Regressão: produção não aceita o segredo_ip do config.exemplo.php (público) nem um curto. */
    public function testSegredoIpDeExemploOuCurtoRecusadoEmProducao(): void
    {
        $exemplo = (string) Config::carregarArquivo(dirname(__DIR__, 3) . '/config/config.exemplo.php')['segredo_ip'];
        foreach ([$exemplo, 'curto123'] as $segredo) {
            $prod = Aplicacao::iniciar(['ambiente' => 'prod', 'segredo_ip' => $segredo]);
            try {
                $prod->segredoIp();
                $this->fail('Aceitou segredo_ip fraco em produção: ' . $segredo);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('segredo_ip', $e->getMessage());
            }
        }
        $forte = bin2hex(random_bytes(32));
        $this->assertSame($forte, Aplicacao::iniciar(['ambiente' => 'prod', 'segredo_ip' => $forte])->segredoIp());
        // Em desenvolvimento o exemplo continua servindo.
        $this->assertSame($exemplo, Aplicacao::iniciar(['segredo_ip' => $exemplo])->segredoIp());
    }

    public function testExemploDeConfiguracaoTemTodasAsChavesDoContrato(): void
    {
        $exemplo = Config::carregarArquivo(dirname(__DIR__, 3) . '/config/config.exemplo.php');
        foreach (['ambiente', 'url_editor', 'dominio_sites', 'protocolo_sites', 'db', 'dir_sites', 'dir_media', 'dir_var', 'segredo_app',
            'segredo_ip', 'smtp', 'email_modo', 'retencao_leads_meses', 'confiar_cloudflare', 'releases_mantidas', 'limite_upload_mb', 'max_megapixels'] as $chave) {
            $this->assertArrayHasKey($chave, $exemplo, $chave);
        }
        foreach (['driver', 'dsn', 'usuario', 'senha'] as $chave) {
            $this->assertArrayHasKey($chave, $exemplo['db']);
        }
        foreach (['host', 'porta', 'seguranca', 'usuario', 'senha', 'remetente', 'nome_remetente'] as $chave) {
            $this->assertArrayHasKey($chave, $exemplo['smtp']);
        }
        $this->assertSame('dev', $exemplo['ambiente']);
        $this->assertSame('arquivo', $exemplo['email_modo']);
    }

    public function testArquivoDeConfiguracaoAusenteDaErroClaro(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.exemplo.php');
        Config::carregarArquivo('/nao/existe/config.php');
    }
}
