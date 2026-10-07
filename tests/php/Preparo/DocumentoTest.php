<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Biblioteca;
use Rankly\Preparo\Documento;

final class DocumentoTest extends TestCase
{
    private const CHAVES = ['versaoEsquema', 'nicho', 'especialidade', 'modelo', 'estilo', 'dados', 'secoes', 'textos', 'listas', 'imagens',
        'icones', 'confirmados', 'rastreamento', 'seo'];

    private static ?array $lib = null;

    private static function lib(): array
    {
        return self::$lib ??= Biblioteca::carregar(dirname(__DIR__, 2) . '/fixtures/biblioteca-mini');
    }

    private static function v1(): array
    {
        return [
            'versaoEsquema' => 1, 'nicho' => 'clinicas', 'modelo' => 'moderno',
            'estilo' => ['cor' => '#C23B6E', 'fonte' => 'editorial', 'acabamento' => 'moderno', 'whatsappFlutuante' => true],
            'dados' => ['nome' => 'Clínica Sorriso Vivo', 'cidade' => 'Jundiaí', 'whatsapp' => '(11) 98765-4321', 'logo' => 'm_8f3a2c'],
            'secoes' => [['tipo' => 'header', 'opcao' => 0], ['tipo' => 'hero', 'opcao' => 0], ['tipo' => 'servicos', 'opcao' => 3], ['tipo' => 'xyz', 'opcao' => 0]],
            'textos' => ['serv.0.t' => 'Ortodontia', 'sobre.l.0' => 'Item', 'clientes.2' => 'ACME', 'clientes.titulo' => 'Clientes'],
            'imagens' => ['hero.img' => 'm_91c0de', 'equipe.2.f' => 'm_ffffff'],
            'icones' => ['serv.1' => 'h-odontology'],
            'rastreamento' => ['gtm' => 'GTM-XXXXXXX'],
        ];
    }

    public function testCriarDocumento(): void
    {
        $doc = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'classico', 'dados' => ['nome' => 'X']], self::lib());
        self::assertSame(self::CHAVES, array_keys($doc));
        self::assertSame(['cor' => '#2a7f86', 'fonte' => 'amigavel', 'acabamento' => 'classico', 'whatsappFlutuante' => true], $doc['estilo']);
        self::assertSame('odontologia', $doc['especialidade']);
        self::assertSame(['cep' => '', 'logradouro' => '', 'numero' => '', 'complemento' => '', 'bairro' => ''], $doc['dados']['endereco']);
        self::assertNull($doc['dados']['logo']);
        self::assertSame(['header', 'hero', 'servicos', 'faq', 'rodape'], array_column($doc['secoes'], 'tipo'));
        $outro = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno', 'especialidade' => 'estetica', 'estilo' => ['cor' => '#ABC', 'acabamento' => 'direto', 'whatsappFlutuante' => false]], self::lib());
        self::assertSame(['cor' => '#aabbcc', 'fonte' => 'editorial', 'acabamento' => 'direto', 'whatsappFlutuante' => false], $outro['estilo']);
        self::assertSame('estetica', $outro['especialidade']);
    }

    public function testErrosDeNichoEModelo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Documento::criarDocumento(['nicho' => 'x', 'modelo' => 'moderno'], self::lib());
    }

    public function testReceitaFiltraPorSoEExceto(): void
    {
        $modelo = ['secoes' => [['header', 'simples'], ['clientes', 'faixa', ['so' => ['empresas']]], ['faq', 'centralizada', ['exceto' => ['clinicas']]], ['tipo' => 'rodape', 'opcao' => 'simples']]];
        self::assertSame([['tipo' => 'header', 'opcao' => 'simples'], ['tipo' => 'rodape', 'opcao' => 'simples']], Documento::receitaModelo(self::lib(), $modelo, 'clinicas'));
        self::assertSame(['header', 'clientes', 'faq', 'rodape'], array_column(Documento::receitaModelo(self::lib(), $modelo, 'empresas'), 'tipo'));
    }

    public function testAplicarModeloMantemConteudoECor(): void
    {
        $doc = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno', 'estilo' => ['cor' => '#123456']], self::lib());
        $doc['textos'] = ['hero.titulo' => 'T'];
        $doc['listas'] = ['serv' => ['2', '1', '3']];
        $doc['seo']['titulo'] = 'S';
        $novo = Documento::aplicarModelo($doc, self::lib(), 'classico');
        self::assertSame(['cor' => '#123456', 'fonte' => 'amigavel', 'acabamento' => 'classico', 'whatsappFlutuante' => true], $novo['estilo']);
        self::assertSame(['barra', 'formulario', 'cards', 'foto-ajuda', 'completo'], array_column($novo['secoes'], 'opcao'));
        foreach (['textos', 'listas', 'seo', 'dados', 'imagens', 'icones'] as $k) {
            self::assertSame($doc[$k], $novo[$k], $k);
        }
    }

    public function testMigrarV1(): void
    {
        $v2 = Documento::migrar(self::v1(), self::lib());
        self::assertSame(self::CHAVES, array_keys($v2));
        self::assertSame([['tipo' => 'header', 'opcao' => 'simples'], ['tipo' => 'hero', 'opcao' => 'cards-flutuantes'], ['tipo' => 'servicos', 'opcao' => 'cards']], $v2['secoes']);
        self::assertSame('blocos', Documento::migrar(self::v1())['secoes'][2]['opcao'], 'sem biblioteca: catálogo §3.2');
        self::assertSame(['serv.1.t' => 'Ortodontia', 'sobrel.1.t' => 'Item', 'cli.3.t' => 'ACME', 'cli.titulo' => 'Clientes'], $v2['textos']);
        self::assertSame(['hero.img' => 'm_91c0de', 'equipe.3.f' => 'm_ffffff'], $v2['imagens']);
        self::assertSame(['serv.2' => 'h-odontology'], $v2['icones']);
        self::assertSame('#c23b6e', $v2['estilo']['cor']);
        self::assertSame(['gtm' => 'GTM-XXXXXXX', 'ga4' => '', 'metaPixel' => ''], $v2['rastreamento']);
        self::assertSame($v2, Documento::migrar($v2, self::lib()), 'idempotente');
        self::assertSame(2, Documento::migrar(['versaoEsquema' => 2.0, 'textos' => ['serv.0.t' => 'x']])['versaoEsquema']);
        self::assertSame(['serv.0.t' => 'x'], Documento::migrar(['versaoEsquema' => 2.0, 'textos' => ['serv.0.t' => 'x']])['textos'], '2.0 do JSON é v2');
    }

    public function testRegistroCampos(): void
    {
        $r = Documento::registroCampos(self::lib());
        self::assertSame(['tipo' => 'texto', 'max' => 80, 'dono' => 'servicos', 'grupo' => 'serv', 'campo' => 'titulo'], $r['serv.titulo']);
        self::assertSame(['tipo' => 'icone', 'automatico' => 't', 'dono' => 'servicos', 'lista' => 'serv', 'campo' => 'ic'], $r['serv.*.ic']);
        self::assertSame(160, Documento::definicaoCampo($r, 'serv.nk3f.d')['max']);
        self::assertNull(Documento::definicaoCampo($r, 'x.y'));
        self::assertSame([3, 10], Documento::registroListas(self::lib())['faq']['repete']);
    }

    public function testJsonPreservaMapasVazios(): void
    {
        $doc = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno'], self::lib());
        $json = Documento::json($doc);
        self::assertStringContainsString('"textos":{}', $json);
        self::assertStringContainsString('"horarios":{}', $json);
        self::assertStringContainsString('"confirmados":[]', $json);
        self::assertStringContainsString('"nome":""', $json);
    }
}
