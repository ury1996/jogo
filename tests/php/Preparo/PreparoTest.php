<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Biblioteca;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Preparo;

final class PreparoTest extends TestCase
{
    private const PUBLICAR = ['modo' => 'publicar', 'urlMidia' => 'img/{id}-{w}.{ext}', 'ano' => 2026, 'midia' => [
        'm_00000001' => ['largura' => 2400, 'altura' => 1600, 'variantes' => [480, 960, 1600], 'alt' => ''],
        'm_00000002' => ['largura' => 1200, 'altura' => 900, 'variantes' => [480, 960, 1200], 'alt' => ''],
    ]];

    private static ?array $lib = null;

    private static function lib(): array
    {
        return self::$lib ??= Biblioteca::carregar(dirname(__DIR__, 2) . '/fixtures/biblioteca-mini');
    }

    private static function doc(array $extra = []): array
    {
        return Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'Odonto Mais', 'cidade' => 'Campinas', 'uf' => 'SP', 'whatsapp' => '(19) 99876-5432']] + $extra, self::lib());
    }

    private static function libCom(string $tipo, string $opcao, string $template): array
    {
        $lib = self::lib();
        $lib['secoes'][$tipo]['templates'][$opcao] = $template;
        return $lib;
    }

    private static function interno(string $html): string
    {
        return (string) preg_replace('/^<div[^>]*>|<\/div>$/', '', $html);
    }

    public function testInstantaneoSimplesIgualAoDoJs(): void
    {
        $dir = dirname(__DIR__, 2) . '/paridade';
        $libMini = json_decode((string) file_get_contents($dir . '/lib-instantaneo.json'), true, 512, JSON_THROW_ON_ERROR);
        $doc = Documento::criarDocumento(['nicho' => 'n', 'modelo' => 'm', 'dados' => []], $libMini);
        $doc['imagens'] = ['hero.img' => 'm_00000001'];
        $r = Preparo::prepararSite($doc, $libMini, ['modo' => 'publicar', 'ano' => 2026, 'midia' => ['m_00000001' => ['largura' => 2000, 'altura' => 1000, 'variantes' => [480, 960, 1600]]]]);
        self::assertSame(file_get_contents($dir . '/instantaneo.html'), $r['html']);
        self::assertSame('rk k-direto f-moderna', $r['classesRaiz']);
        self::assertStringStartsWith('.rk{--p:#2e6fd1;--on-p:#ffffff;', $r['cssPaleta']);
        self::assertSame(['branco', 'tom', 'branco'], array_column($r['secoes'], 'fundo'));
        self::assertSame('inicio', $r['alvoPular']);
        self::assertSame([], $r['avisos']);
    }

    public function testInvolucroFundosEBotaoFlutuante(): void
    {
        $r = Preparo::prepararSite(self::doc(), self::lib(), self::PUBLICAR);
        self::assertStringStartsWith('<div class="rk-sec rk-sec--header rk-op--header-simples rk-bg--branco" id="topo" data-sec="0">', $r['html']);
        self::assertSame(['branco', 'tom', 'branco', 'tom', 'escuro'], array_column($r['secoes'], 'fundo'));
        self::assertStringEndsWith('aria-label="Conversar no WhatsApp">' . self::lib()['icones']['utilitarios']['whatsapp']['svg']['duotone'] . '</a>', $r['html']);
        $sem = self::doc();
        $sem['estilo']['whatsappFlutuante'] = false;
        self::assertStringNotContainsString('rk-wa', Preparo::prepararSite($sem, self::lib(), self::PUBLICAR)['html']);
    }

    public function testLcpSoNaPrimeiraSecaoDepoisDoHeaderESizesDaOpcao(): void
    {
        $doc = self::doc();
        $doc['imagens'] = ['hero.img' => 'm_00000001', 'serv.1.img' => 'm_00000001', 'faq.img' => 'm_00000002'];
        $doc['secoes'] = [['tipo' => 'header', 'opcao' => 'simples'], ['tipo' => 'hero', 'opcao' => 'cards-flutuantes'], ['tipo' => 'servicos', 'opcao' => 'cards'], ['tipo' => 'faq', 'opcao' => 'foto-ajuda']];
        $r = Preparo::prepararSite($doc, self::lib(), self::PUBLICAR);
        self::assertMatchesRegularExpression('/sizes="\(max-width: 760px\) 100vw, 50vw"[^>]*loading="eager" decoding="async" fetchpriority="high"/', $r['secoes'][1]['html']);
        self::assertMatchesRegularExpression('/sizes="\(max-width: 760px\) 100vw, 33vw"[^>]*alt="Ortodontia — Odonto Mais" loading="lazy" decoding="async">/', $r['secoes'][2]['html']);
        self::assertMatchesRegularExpression('/sizes="\(max-width: 760px\) 100vw, 40vw"[^>]*loading="lazy"/', $r['secoes'][3]['html']);
    }

    public function testViewCompletaEApelidos(): void
    {
        $t = '{{c.serv.titulo}}|{{c.faq.qtd}}|{{#c.faq.tem}}T{{/c.faq.tem}}|{{c.faq.p2.q}}|{{c.faq.p9.q}}|{{#c.serv.p1}}{{k}}:{{i}}:{{primeiro}}:{{ultimo}}:{{par}}:{{img.vazio}}:{{ic.id}}{{/c.serv.p1}}|{{c.hero.img.vazio}}|{{c.rodape.texto}}|{{conteudo.serv.p2.t}}|{{s.tipo}}/{{s.opcao}}/{{s.fundo}}/{{s.indice}}|{{e.acabamento}}{{#e.moderno}}!{{/e.moderno}}|{{#modo.publicar}}P{{/modo.publicar}}';
        $r = Preparo::prepararSite(self::doc(), self::libCom('hero', 'cards-flutuantes', $t), self::PUBLICAR);
        self::assertSame(
            'Tudo o que o seu sorriso precisa na Odonto Mais|4|T|A primeira consulta é paga?||serv.1:1:true:false:false:true:aparelho|true|Todos os direitos reservados.|Implantes|hero/cards-flutuantes/tom/1|moderno!|P',
            self::interno($r['secoes'][1]['html']),
        );
    }

    public function testCampoDoItemEscondeARaizComoNoJs(): void
    {
        $t = '{{#c.serv.p1}}[{{d.whatsappLink}}][{{dados.whatsappLink}}][{{c.hero.cta}}]{{/c.serv.p1}}';
        $r = Preparo::prepararSite(self::doc(), self::libCom('hero', 'cards-flutuantes', $t), self::PUBLICAR);
        self::assertSame(
            '[][https://wa.me/5519998765432?text=Ol%C3%A1!%20Vi%20o%20site%20e%20gostaria%20de%20agendar%20uma%20avalia%C3%A7%C3%A3o.][Agendar avaliação]',
            self::interno($r['secoes'][1]['html']),
        );
    }

    public function testRobustezPulaComAviso(): void
    {
        $doc = self::doc();
        $doc['secoes'] = [['tipo' => 'nao-existe', 'opcao' => 'x'], ['tipo' => 'hero', 'opcao' => 'nao-existe'], ['tipo' => 'header', 'opcao' => 'simples'], ['tipo' => 'header', 'opcao' => 'barra'], ['tipo' => 'faq', 'opcao' => 'centralizada']];
        $doc['estilo']['cor'] = 'azul';
        $lib = self::lib();
        unset($lib['secoes']['faq']['templates']['centralizada']);
        $r = Preparo::prepararSite($doc, $lib, self::PUBLICAR);
        self::assertSame(['cor_invalida', 'secao_desconhecida', 'opcao_desconhecida', 'secao_duplicada', 'template_ausente'], array_column($r['avisos'], 'codigo'));
        self::assertSame([2], array_column($r['secoes'], 'indice'));
        $quebrado = Preparo::prepararSite(self::doc(), self::libCom('hero', 'cards-flutuantes', '{{#aberta}}sem fechar'), self::PUBLICAR);
        self::assertSame('erro_template', $quebrado['avisos'][0]['codigo']);
        foreach ([[null, null], [[], self::lib()], [self::doc(), []], ['x', 5], [['secoes' => 'x', 'textos' => 3, 'dados' => []], self::lib()]] as [$d, $l]) {
            $x = Preparo::prepararSite($d, $l, null);
            self::assertIsString($x['html']);
        }
    }

    public function testDadosDerivados(): void
    {
        $doc = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'classico', 'dados' => [
            'nome' => 'Clínica X', 'cidade' => 'São José dos Campos', 'uf' => 'sp', 'whatsapp' => '+55 (12) 99876-5432',
            'telefone' => '(12) 3456-7890', 'email' => 'contato@clinica.com.br',
            'endereco' => ['cep' => '12245000', 'logradouro' => 'Av. São João', 'numero' => '1.234', 'complemento' => 'Sala 5', 'bairro' => 'Jardim'],
            'horarios' => ['seg' => ['08:00', '18:00'], 'ter' => ['08:00', '18:00'], 'dom' => null],
            'registro' => ['numero' => '12345', 'uf' => 'SP', 'responsavel' => 'Dra. Ana Lima'],
            'redes' => ['instagram' => '@clinica'],
        ]], self::lib());
        $d = Preparo::montarDados($doc, self::lib(), self::PUBLICAR);
        self::assertSame('São José dos Campos - SP', $d['cidadeUf']);
        self::assertSame('(12) 99876-5432', $d['whatsapp']);
        self::assertSame('tel:+551234567890', $d['telefoneLink']);
        self::assertSame('Av. São João, 1.234 - Sala 5 - Jardim, São José dos Campos - SP, CEP 12245-000', $d['endereco']);
        self::assertSame('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($d['endereco']), $d['mapaLink']);
        self::assertSame('https://www.google.com/maps?q=' . rawurlencode($d['endereco']) . '&output=embed', $d['mapaEmbed']);
        self::assertSame([['dias' => 'Seg e Ter', 'horas' => '8h às 18h'], ['dias' => 'Dom', 'horas' => 'Fechado']], $d['horarios']);
        self::assertSame('Responsável técnico: Dra. Ana Lima · CRO-SP 12345', $d['registro']);
        self::assertSame('https://www.instagram.com/clinica', $d['redes'][0]['url']);
        self::assertSame(2026, $d['ano']);
        $vazio = Preparo::montarDados(Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'classico'], self::lib()), self::lib(), []);
        self::assertSame('Clínica Sorriso Vivo', $vazio['nome']);
        self::assertSame('(11) 98765-4321', $vazio['whatsapp']);
        self::assertNull($vazio['ano']);
    }

    public function testUtilitarioNaoConfundeComIconeDeMesmoId(): void
    {
        $lib = self::libCom('hero', 'cards-flutuantes', '{{{u.relogio}}}|{{#c.serv.p1}}{{ic.id}}={{{ic.svg}}}{{/c.serv.p1}}');
        foreach ($lib['icones']['icones'] as $i => $icone) {
            if ($icone['id'] === 'relogio') {
                $lib['icones']['icones'][$i]['svg'] = ['fino' => '<svg>lista</svg>', 'duotone' => '<svg>lista</svg>', 'preenchido' => '<svg>lista</svg>'];
            }
        }
        $doc = self::doc();
        $doc['icones'] = ['serv.1' => 'relogio'];
        $html = self::interno(Preparo::prepararSite($doc, $lib, self::PUBLICAR)['secoes'][1]['html']);
        self::assertSame(self::lib()['icones']['utilitarios']['relogio']['svg']['duotone'] . '|relogio=<svg>lista</svg>', $html);
    }
}
