<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Ia\ErroIa;
use Rankly\Lib\Ia\Gemini;
use Rankly\Lib\Ia\GeradorConteudo;
use Rankly\Lib\Ia\Simulado;
use Rankly\Preparo\Textos;
use Rankly\Testes\Lib\AmbienteTeste;

/** IA que escreve os textos do site: travas de conformidade, limites, listas, rota e provedor Gemini. */
final class IaTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;
    private array $site;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([
            'dir_biblioteca' => dirname(__DIR__, 3) . '/biblioteca',
            'ia' => ['provedor' => 'desligado', 'limite_por_hora' => 3],
        ], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
        $this->site = $this->c->post('/api/sites', [
            'nicho' => 'clinicas', 'modelo' => 'moderno',
            'dados' => ['nome' => 'Sorriso Vivo', 'cidade' => 'Jundiaí', 'uf' => 'SP', 'whatsapp' => '(11) 98765-4321'],
        ])->dados()['site'];
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    /** Resposta "da IA" com problemas de propósito. */
    private static function resposta(): array
    {
        return [
            'textos' => [
                'hero.titulo' => '**Implantes dentários em Jundiaí** na Sorriso Vivo',
                'hero.texto' => "Tratamento garantido e sem dor.\nAgende já.",
                'hero.selo' => '',
                'serv.titulo' => str_repeat('Tratamentos completos para toda a família ', 5),
                'num.1.v' => '30 anos',                 // alegação: não pode entrar
                'aval.nota' => '5,0',                   // alegação: não pode entrar
                'hero.cta' => 'Clique aqui agora',      // rótulo de botão: fica com o padrão
            ],
            'listas' => [
                'serv' => array_map(fn (int $i): array => ['t' => "Serviço {$i}", 'd' => "Descrição do serviço {$i} em Jundiaí."], range(1, 12)),
                'faq' => [['q' => 'Vocês atendem convênio?', 'a' => 'Sim, atendemos os principais convênios.']],
                'dep' => [['t' => 'Ótimo!', 'n' => 'Fulano', 'c' => 'Paciente']],
            ],
            'seo' => ['titulo' => 'Sorriso Vivo · Implantes dentários em Jundiaí', 'descricao' => 'Clínica odontológica em Jundiaí com implantes, ortodontia e limpeza. Agende sua avaliação pelo WhatsApp e conheça a equipe.'],
            'palavrasChave' => ['implante dentário jundiaí', 'dentista em jundiaí'],
        ];
    }

    public function testEstadoMostraSeAIaEstaDisponivel(): void
    {
        $this->assertSame(['disponivel' => false, 'provedor' => null], $this->c->get('/api/ia')->dados());
        $this->app->definirIa(new Simulado());
        $this->assertSame(['disponivel' => true, 'provedor' => 'simulado'], $this->c->get('/api/ia')->dados());
    }

    public function testSemProvedorResponde503ComMensagemClara(): void
    {
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/ia', ['descricao' => 'Clínica de implantes em Jundiaí.']);
        $this->assertSame(503, $r->status);
        $this->assertStringContainsString('Gemini', $r->dados()['erro']['mensagem']);
    }

    public function testTravasDeConformidadeLimitesEAlegacoes(): void
    {
        $pedidos = [];
        $this->app->definirIa(new Simulado(function (string $instr, string $pedido, array $esquema) use (&$pedidos): array {
            $pedidos[] = [$instr, $pedido, $esquema];
            return self::resposta();
        }));
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/ia', ['descricao' => 'Clínica odontológica focada em implantes, atende convênios.']);
        $this->assertSame(200, $r->status, $r->corpo);
        $p = $r->dados();

        // Instruções levam as regras do conselho e os termos proibidos; o pedido leva a descrição.
        [$instr, $pedido, $esquema] = $pedidos[0];
        $this->assertStringContainsString('sem dor', $instr);
        $this->assertStringContainsString('NUNCA invente números', $instr);
        $this->assertStringContainsString('focada em implantes', $pedido);
        $this->assertArrayNotHasKey('aval.nota', $esquema['properties']['textos']['properties']);
        $this->assertArrayNotHasKey('dep', $esquema['properties']['listas']['properties']);

        // Markdown limpo e nome/cidade retokenizados.
        $this->assertSame('Implantes dentários em {cidade} na {nome}', $p['textos']['hero.titulo']);
        // "garantido"/"sem dor" → descartado com aviso; selo vazio não entra; texto longo cortado no limite.
        $this->assertArrayNotHasKey('hero.texto', $p['textos']);
        $this->assertArrayNotHasKey('hero.selo', $p['textos']);
        $this->assertLessThanOrEqual(80, mb_strlen($p['textos']['serv.titulo']));
        $this->assertStringEndsNotWith(' ', $p['textos']['serv.titulo']);
        $this->assertStringContainsString('termo proibido', implode(' ', $p['avisos']));
        // Alegações e rótulos de botão nunca entram.
        foreach (['num.1.v', 'aval.nota', 'hero.cta'] as $k) {
            $this->assertArrayNotHasKey($k, $p['textos']);
        }
        $this->assertArrayNotHasKey('dep', $p['listas']);

        // Serviços: no máximo 8, reaproveitando os ids padrão na ordem e criando ids novos.
        $this->assertCount(8, $p['listas']['serv']);
        $this->assertSame(['1', '2', '3', '4'], array_slice($p['listas']['serv'], 0, 4));
        $this->assertMatchesRegularExpression('/^n[0-9a-z]{4}$/', $p['listas']['serv'][7]);
        $this->assertSame('Serviço 8', $p['textos']['serv.' . $p['listas']['serv'][7] . '.t']);
        $this->assertSame('Descrição do serviço 1 em {cidade}.', $p['textos']['serv.1.d']);
        // FAQ com menos que o mínimo (3): lista mantida, com aviso.
        $this->assertArrayNotHasKey('faq', $p['listas']);
        $this->assertStringContainsString('não escreveu itens suficientes', implode(' ', $p['avisos']));

        $this->assertSame('Sorriso Vivo · Implantes dentários em Jundiaí', $p['seo']['titulo']);
        $this->assertSame(['implante dentário jundiaí', 'dentista em jundiaí'], $p['palavrasChave']);

        // O documento com o patch aplicado passa na validação do PUT.
        $site = $this->c->get('/api/sites/' . $this->site['id'])->dados()['site'];
        $doc = $site['documento'];
        $doc['textos'] = array_merge((array) $doc['textos'], $p['textos']);
        $doc['listas'] = array_merge((array) $doc['listas'], $p['listas']);
        $doc['seo'] = $p['seo'];
        $put = $this->c->put('/api/sites/' . $this->site['id'], ['revisao' => $site['revisao'], 'documento' => $doc]);
        $this->assertSame(200, $put->status, $put->corpo);
    }

    public function testEscopoDeUmaSecaoSoPedeOsCamposDela(): void
    {
        $esquemas = [];
        $this->app->definirIa(new Simulado(function (string $i, string $p, array $esquema) use (&$esquemas): array {
            $esquemas[] = $esquema;
            return ['listas' => ['faq' => [
                ['q' => 'Atendem aos sábados?', 'a' => 'Sim, das 8h às 12h.'],
                ['q' => 'Aceitam convênio?', 'a' => 'Sim, os principais.'],
                ['q' => 'Como agendar?', 'a' => 'Pelo WhatsApp.'],
            ]], 'textos' => ['faq.titulo' => 'Perguntas frequentes']];
        }));
        $r = $this->c->post('/api/sites/' . $this->site['id'] . '/ia', ['descricao' => 'Clínica odontológica em Jundiaí.', 'escopo' => 'faq']);
        $this->assertSame(200, $r->status, $r->corpo);
        $props = $esquemas[0]['properties'];
        $this->assertSame(['faq'], array_keys($props['listas']['properties']));
        $this->assertArrayNotHasKey('seo', $props);
        foreach (array_keys($props['textos']['properties']) as $chave) {
            $this->assertStringStartsWith('faq.', $chave);
        }
        $this->assertCount(3, $r->dados()['listas']['faq']);
        $this->assertNull($r->dados()['seo']);
    }

    public function testValidacaoDaDescricaoEscopoELimitePorHora(): void
    {
        $this->app->definirIa(new Simulado(fn (): array => self::resposta()));
        $url = '/api/sites/' . $this->site['id'] . '/ia';
        $this->assertSame(422, $this->c->post($url, ['descricao' => 'curta'])->status);
        $this->assertSame(422, $this->c->post($url, ['descricao' => 'Descrição válida do negócio.', 'escopo' => 'nada'])->status);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->c->post($url, ['descricao' => 'Descrição válida do negócio.'])->status);
        }
        $r = $this->c->post($url, ['descricao' => 'Descrição válida do negócio.']);
        $this->assertSame(429, $r->status);
        // Outro usuário não pode usar a IA num site sem acesso.
        AmbienteTeste::usuario($this->app, 'cliente', 'cliente@rankly.teste');
        $outro = new ClienteApi($this->app);
        $outro->login('cliente@rankly.teste', 'senha-forte-123');
        $this->assertSame(404, $outro->post($url, ['descricao' => 'Descrição válida do negócio.'])->status);
    }

    public function testFalhaDaIaNaoGastaOLimite(): void
    {
        $falhas = 0;
        $this->app->definirIa(new Simulado(function () use (&$falhas): array {
            $falhas++;
            throw new ErroIa('O limite gratuito da IA foi atingido por agora.', true);
        }));
        $url = '/api/sites/' . $this->site['id'] . '/ia';
        for ($i = 0; $i < 5; $i++) {
            $r = $this->c->post($url, ['descricao' => 'Descrição válida do negócio.']);
            $this->assertSame(503, $r->status);
            $this->assertSame('O limite gratuito da IA foi atingido por agora.', $r->dados()['erro']['mensagem']);
        }
        $this->assertSame(5, $falhas);
    }

    public function testGeminiUsaReservaRepeteSemEsquemaETraduzErros(): void
    {
        $chamadas = [];
        $ok = json_encode(['candidates' => [['content' => ['parts' => [['text' => "```json\n{\"textos\":{\"hero.titulo\":\"Oi\"}}\n```"]]], 'finishReason' => 'STOP']]]);
        $transporte = function (string $url, array $cab, string $corpo) use (&$chamadas, $ok): array {
            $chamadas[] = [$url, $cab, json_decode($corpo, true)];
            return match (count($chamadas)) {
                1 => [429, '{"error":{"message":"quota"}}'],
                2 => [400, '{"error":{"message":"Invalid JSON payload: responseSchema field"}}'],
                default => [200, $ok],
            };
        };
        $g = new Gemini('chave-secreta', 'modelo-a', 'modelo-b', 30, $transporte);
        $this->assertSame(['textos' => ['hero.titulo' => 'Oi']], $g->gerarJson('i', 'p', ['type' => 'OBJECT']));
        $this->assertStringContainsString('/models/modelo-a:generateContent', $chamadas[0][0]);
        $this->assertStringContainsString('/models/modelo-b:generateContent', $chamadas[1][0]);
        $this->assertArrayHasKey('responseSchema', $chamadas[1][2]['generationConfig']);
        $this->assertArrayNotHasKey('responseSchema', $chamadas[2][2]['generationConfig']);
        $this->assertContains('x-goog-api-key: chave-secreta', $chamadas[0][1]);
        $this->assertStringNotContainsString('chave-secreta', $chamadas[0][0], 'A chave não vai na URL');

        $invalida = new Gemini('x', 'm', '', 30, fn (): array => [400, '{"error":{"message":"API key not valid. Please pass a valid API key."}}']);
        try {
            $invalida->gerarJson('i', 'p', []);
            $this->fail('Esperava ErroIa');
        } catch (ErroIa $e) {
            $this->assertFalse($e->temporario);
            $this->assertStringContainsString('chave', $e->getMessage());
        }
        $vistos = [];
        $varias = new Gemini('x', 'velho', ' r1 , r2,velho', 30, function (string $url) use (&$vistos, $ok): array {
            $vistos[] = preg_replace('~.*/models/([^:]+):.*~', '$1', $url);
            return count($vistos) < 3 ? [count($vistos) === 1 ? 404 : 503, '{}'] : [200, $ok];
        });
        $varias->gerarJson('i', 'p', []);
        $this->assertSame(['velho', 'r1', 'r2'], $vistos, 'Vários modelos reserva, separados por vírgula, na ordem');

        $foraDoAr = new Gemini('x', 'm', 'r', 30, fn (): array => [0, '']);
        try {
            $foraDoAr->gerarJson('i', 'p', []);
            $this->fail('Esperava ErroIa');
        } catch (ErroIa $e) {
            $this->assertTrue($e->temporario);
        }
    }

    public function testCortarETermoProibido(): void
    {
        $this->assertSame('Atendimento para toda a', mb_substr('Atendimento para toda a família', 0, 23));
        $this->assertSame('Atendimento para toda', GeradorConteudo::cortar('Atendimento para toda a família', 23));
        $this->assertSame('curto', GeradorConteudo::cortar('curto', 10));
        $this->assertSame('sem dor', GeradorConteudo::termoProibido('Tratamento SEM DOR!', ['sem dor']));
        $this->assertNull(GeradorConteudo::termoProibido('Melhoramos o seu sorriso', ['o melhor']));
        $this->assertSame('100%', GeradorConteudo::termoProibido('Satisfação 100% dos casos', ['100%']));
        $this->assertSame('Texto', GeradorConteudo::limpar('"Texto"', false));
    }

    public function testSimuladoSemRespostaProntaGeraConteudoQuePassaNasTravas(): void
    {
        $lib = $this->app->biblioteca();
        $doc = $this->c->get('/api/sites/' . $this->site['id'])->dados()['site']['documento'];
        $patch = (new GeradorConteudo(new Simulado()))->gerar($doc, $lib, 'Clínica odontológica em Jundiaí.');
        $this->assertNotEmpty($patch['textos']);
        foreach ($patch['listas'] as $lista => $ids) {
            [$min, $max] = Textos::limitesLista($lib, $lista);
            $this->assertGreaterThanOrEqual($min, count($ids));
            $this->assertLessThanOrEqual($max, count($ids));
        }
    }
}
