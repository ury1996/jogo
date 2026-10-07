<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Gerador\Gerador;
use Rankly\Testes\Lib\AmbienteTeste;

final class ValidadorTest extends TestCase
{
    private ?string $dir = null;
    private Aplicacao $app;

    protected function setUp(): void
    {
        $this->app = SiteExemplo::app([], $this->dir);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function validar(array $doc): array
    {
        $site = SiteExemplo::site($this->app, $doc, 'site' . bin2hex(random_bytes(3)));
        return (new Gerador($this->app))->validar($site);
    }

    /** @return list<string> */
    private static function codigos(array $lista): array
    {
        return array_map(static fn (array $e): string => $e['codigo'], $lista);
    }

    private static function com(array $lista, string $codigo): array
    {
        return array_values(array_filter($lista, static fn (array $e): bool => $e['codigo'] === $codigo));
    }

    public function testSiteCompletoNaoTemErros(): void
    {
        $r = $this->validar(SiteExemplo::documento($this->app));
        $this->assertSame([], $r['erros']);
        $this->assertSame([], $r['textosPadraoAlterados']);
        foreach (array_merge($r['erros'], $r['avisos']) as $item) {
            $this->assertIsString($item['codigo']);
            $this->assertNotSame('', $item['mensagem']);
        }
    }

    public function testWhatsappInvalidoOuVazio(): void
    {
        $r = $this->validar(SiteExemplo::documento($this->app, sobre: ['dados' => ['whatsapp' => '(11) 1234']]));
        $e = self::com($r['erros'], 'whatsapp_invalido');
        $this->assertCount(1, $e);
        $this->assertSame('dados.whatsapp', $e[0]['chave']);

        $r = $this->validar(SiteExemplo::documento($this->app, sobre: ['dados' => ['whatsapp' => '(00) 98765-4321']]));
        $this->assertContains('whatsapp_invalido', self::codigos($r['erros']), 'DDD 00 não existe');

        $r = $this->validar(SiteExemplo::documento($this->app, sobre: ['dados' => ['whatsapp' => '']]));
        $e = self::com($r['erros'], 'whatsapp_vazio');
        $this->assertSame('Falta o WhatsApp. Ele é usado em todos os botões de contato.', $e[0]['mensagem']);
    }

    public function testNomeECidadeObrigatorios(): void
    {
        $r = $this->validar(SiteExemplo::documento($this->app, sobre: ['dados' => ['nome' => '  ', 'cidade' => '']]));
        $this->assertContains('nome_vazio', self::codigos($r['erros']));
        $this->assertContains('cidade_vazia', self::codigos($r['erros']));
    }

    public function testRegistroObrigatorioPorEspecialidade(): void
    {
        $r = $this->validar(SiteExemplo::documento($this->app, sobre: ['dados' => ['registro' => ['numero' => '']]]));
        $e = self::com($r['erros'], 'registro_obrigatorio');
        $this->assertCount(1, $e);
        $this->assertSame('dados.registro.numero', $e[0]['chave']);
        $this->assertStringContainsString('CRO', $e[0]['mensagem']);

        // Estética não exige registro.
        $doc = SiteExemplo::documento($this->app, sobre: ['dados' => ['registro' => ['numero' => '']]]);
        $doc['especialidade'] = 'estetica';
        $this->assertNotContains('registro_obrigatorio', self::codigos($this->validar($doc)['erros']));
    }

    public function testDepoimentoDeExemploNuncaPodeSerConfirmado(): void
    {
        $doc = SiteExemplo::documento($this->app);
        unset($doc['textos']['dep.2.t']);
        $doc['confirmados'][] = 'dep';
        $r = $this->validar($doc);
        $e = self::com($r['erros'], 'alegacao_padrao');
        $this->assertCount(1, $e);
        $this->assertSame('dep', $e[0]['grupo']);
        $this->assertFalse($e[0]['confirmavel']);
        $this->assertSame('dep.2.t', $e[0]['chave']);
        $this->assertIsInt($e[0]['secao']);
    }

    public function testAlegacaoConfirmadaLiberaEPadraoBloqueia(): void
    {
        $doc = SiteExemplo::documento($this->app);
        $doc['confirmados'] = ['cli', 'aval'];
        $r = $this->validar($doc);
        $num = self::com($r['erros'], 'alegacao_padrao');
        $this->assertSame(['num'], array_column($num, 'grupo'), 'números de exemplo sem confirmação bloqueiam');
        $this->assertTrue($num[0]['confirmavel']);

        $doc['confirmados'] = ['cli', 'aval', 'num'];
        $this->assertSame([], self::com($this->validar($doc)['erros'], 'alegacao_padrao'));

        // Números editados (não padrão) também liberam, sem confirmação.
        $doc['confirmados'] = ['cli', 'aval'];
        foreach (['1', '2', '3'] as $i) {
            $doc['textos']["num.$i.v"] = "{$i}0";
            $doc['textos']["num.$i.l"] = "Rótulo {$i}";
        }
        $this->assertSame([], self::com($this->validar($doc)['erros'], 'alegacao_padrao'));
    }

    public function testEstruturaDasSecoes(): void
    {
        $doc = SiteExemplo::documento($this->app);
        $doc['secoes'] = array_reverse($doc['secoes']);
        $doc['secoes'][] = $doc['secoes'][1];
        $doc['secoes'][] = ['tipo' => 'servicos', 'opcao' => 'nao-existe'];
        $doc['secoes'][] = ['tipo' => 'inexistente', 'opcao' => 'x'];
        $codigos = self::codigos($this->validar($doc)['erros']);
        foreach (['header_primeiro', 'rodape_ultimo', 'secao_duplicada', 'opcao_desconhecida', 'secao_desconhecida'] as $c) {
            $this->assertContains($c, $codigos);
        }
    }

    public function testRastreamentoSoNoFormatoExato(): void
    {
        $doc = SiteExemplo::documento($this->app, sobre: ['rastreamento' => ['gtm' => 'GTM-12', 'ga4' => 'g-abc12345', 'metaPixel' => '12ab']]);
        $e = self::com($this->validar($doc)['erros'], 'rastreamento_invalido');
        $this->assertSame(['rastreamento.gtm', 'rastreamento.metaPixel'], array_column($e, 'chave'), 'g-… minúsculo é aceito (vira maiúsculo)');
    }

    public function testAvisosDeFotoCorEConformidade(): void
    {
        $doc = SiteExemplo::documento($this->app, sobre: ['estilo' => ['cor' => '#f5f0a0']]);
        $doc['textos']['hero.titulo'] = 'Clareamento sem dor e resultado garantido';
        $r = $this->validar($doc);
        $this->assertSame([], $r['erros']);
        $avisos = self::codigos($r['avisos']);
        $this->assertContains('foto_faltando', $avisos);
        $this->assertContains('cor_clara', $avisos);
        $termo = self::com($r['avisos'], 'termo_conformidade');
        $this->assertCount(1, $termo, 'um aviso por texto');
        $this->assertSame('hero.titulo', $termo[0]['chave']);
        $foto = self::com($r['avisos'], 'foto_faltando')[0];
        $this->assertIsInt($foto['secao']);
        $this->assertNotEmpty($foto['chaves']);
    }

    public function testTextosPadraoAlteradosDesdeAUltimaPublicacao(): void
    {
        $doc = SiteExemplo::documento($this->app);
        $site = SiteExemplo::site($this->app, $doc);
        $u = AmbienteTeste::usuario($this->app);
        $gerador = new Gerador($this->app);
        $gerador->publicar($site, (int) $u['id']);

        // Simula a biblioteca de antes: na versão publicada o título padrão era outro.
        $resolvidos = json_decode((string) $this->app->db()->valor('SELECT resolvidos FROM versoes WHERE site_id = ?', [(int) $site['id']]), true);
        $this->assertArrayHasKey('hero.titulo', $resolvidos);
        $atual = $resolvidos['hero.titulo'];
        $resolvidos['hero.titulo'] = 'Título antigo da biblioteca';
        $this->app->db()->executar('UPDATE versoes SET resolvidos = ? WHERE site_id = ?', [json_encode($resolvidos), (int) $site['id']]);

        $site = \Rankly\Lib\Sites::porId($this->app, (int) $site['id']);
        $r = $gerador->validar($site);
        $this->assertSame([['chave' => 'hero.titulo', 'antes' => 'Título antigo da biblioteca', 'depois' => $atual]], $r['textosPadraoAlterados']);
        $this->assertContains('textos_padrao_alterados', self::codigos($r['avisos']));

        // Mudar só o nome do negócio não conta como texto padrão alterado.
        $resolvidos['hero.titulo'] = $atual;
        $this->app->db()->executar('UPDATE versoes SET resolvidos = ? WHERE site_id = ?', [json_encode($resolvidos), (int) $site['id']]);
        $doc['dados']['nome'] = 'Clínica Sorriso Novo';
        $site = SiteExemplo::salvar($this->app, (int) $site['id'], $doc);
        $novos = \Rankly\Gerador\Montagem::criar($this->app, $site)->textosPadraoExibidos();
        $mudaram = array_keys(array_diff_assoc(array_intersect_key($novos, $resolvidos), $resolvidos));
        $this->assertNotEmpty($mudaram, 'algum texto padrão usa {nome} (o cenário precisa exercitar as variáveis)');
        $r = $gerador->validar($site);
        $this->assertSame([], $r['textosPadraoAlterados']);
    }
}
