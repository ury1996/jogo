<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Ia\ErroIa;
use Rankly\Lib\Ia\Gemini;
use Rankly\Lib\Ia\GeradorConteudo;
use Rankly\Lib\Ia\RevisorCopy;
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

    public function testInstrucoesLevamEstrategiaPapelTomEGuiaDoRamo(): void
    {
        $pedidos = [];
        $this->app->definirIa(new Simulado(function (string $instr, string $pedido, array $esquema) use (&$pedidos): array {
            $pedidos[] = [$instr, $pedido, $esquema];
            return self::resposta();
        }));
        $url = '/api/sites/' . $this->site['id'] . '/ia';
        $this->assertSame(200, $this->c->post($url, ['descricao' => 'Clínica odontológica focada em implantes.'])->status);
        [$instr, $pedido, $esquema] = $pedidos[0];
        // Sem tom escolhido: o recomendado para odontologia (guia de copy da biblioteca).
        $this->assertStringContainsString('TOM DE VOZ: acolhedor', $instr);
        $this->assertStringContainsString('excelência', $instr, 'Clichês a evitar');
        $this->assertStringContainsString('medo de dor e de agulha', $pedido, 'Guia do ramo vai no pedido');
        $this->assertStringContainsString('"papel": "promessa principal do site', $pedido);
        $this->assertMatchesRegularExpression('/"alvo": \d+/', $pedido);
        $this->assertStringContainsString('"cidade": "Jundiaí"', $pedido, 'Cidade sem a UF (a UF vai à parte)');
        // A estratégia vem antes dos textos; textos na ordem de leitura da página.
        $this->assertSame('estrategia', $esquema['propertyOrdering'][0]);
        $ordem = $esquema['properties']['textos']['propertyOrdering'];
        $this->assertLessThan(array_search('cta.titulo', $ordem, true), array_search('hero.titulo', $ordem, true));

        $this->assertSame(200, $this->c->post($url, ['descricao' => 'Clínica odontológica focada em implantes.', 'tom' => 'sofisticado'])->status);
        $this->assertStringContainsString('TOM DE VOZ: sofisticado', end($pedidos)[0]);
        $this->assertSame(422, $this->c->post($url, ['descricao' => 'Clínica odontológica focada em implantes.', 'tom' => 'berrante'])->status);
    }

    public function testRevisorApontaOQueUmRedatorMandariaRefazer(): void
    {
        $escalares = [
            'hero.titulo' => ['max' => 90, 'campo' => 'titulo'],
            'hero.texto' => ['max' => 200, 'campo' => 'texto'],
            'sobre.texto' => ['max' => 420, 'campo' => 'texto'],
            'equipe.texto' => ['max' => 220, 'campo' => 'texto'],
            'faq.texto' => ['max' => 220, 'campo' => 'texto'],
            'cta.titulo' => ['max' => 80, 'campo' => 'titulo'],
            'dep.titulo' => ['max' => 80, 'campo' => 'titulo'],
            'cli.titulo' => ['max' => 60, 'campo' => 'titulo'],
            'faq.titulo' => ['max' => 80, 'campo' => 'titulo'],
            'hero.selo' => ['max' => 40, 'campo' => 'selo'],
            'faq.ajuda' => ['max' => 120, 'campo' => 'ajuda'],
        ];
        $listas = ['passos' => ['campos' => ['t' => ['max' => 40], 'd' => ['max' => 160]], 'min' => 3, 'max' => 3, 'rotulo' => 'passo']];
        $ctx = ['nome' => 'Sorriso Vivo', 'cidade' => 'Jundiaí', 'proibidos' => ['sem dor', 'o melhor'], 'evitar' => ['sorriso perfeito']];
        $resposta = [
            'textos' => [
                'hero.titulo' => 'Dentista em Jundiaí',
                'hero.texto' => 'Atendimento humanizado com tecnologia de ponta para você.',
                'sobre.texto' => 'Atendimento humanizado para toda a família, com cuidado em cada etapa e',
                'equipe.texto' => 'Equipe com atendimento humanizado e escuta.',
                'faq.texto' => str_repeat('Texto longo demais. ', 15),
                'cta.titulo' => 'Agende Sua Avaliação Hoje',
                'dep.titulo' => 'Pacientes de Jundiaí',
                'cli.titulo' => 'Clientes de Jundiaí',
                'faq.titulo' => 'Dúvidas de quem mora em Jundiaí',
                'hero.selo' => '',
                'faq.ajuda' => 'Atendimento humanizado também pelo WhatsApp.',
            ],
            'listas' => ['passos' => [['t' => 'Agende', 'd' => 'Tratamento sem dor.'], ['t' => 'Venha', 'd' => '']]],
        ];
        $p = RevisorCopy::avaliar($resposta, $escalares, $listas, $ctx, 'Clínica com atendimento humanizado em Jundiaí.', false);

        $this->assertStringContainsString('genérico', implode(' ', $p['hero.titulo']));
        $this->assertStringContainsString('"de ponta"', implode(' ', $p['hero.texto']));
        $this->assertArrayNotHasKey('hero.selo', $p, 'Selo vazio é permitido');
        $this->assertStringContainsString('frase completa', implode(' ', $p['sobre.texto']));
        $this->assertStringContainsString('repete "atendimento humanizado"', implode(' ', $p['faq.ajuda']), 'Quarta vez da mesma expressão');
        $this->assertSame(['faq.ajuda'], array_keys(array_filter($p, fn ($l) => str_contains(implode(' ', $l), 'repete'))), 'As três primeiras vezes passam');
        $this->assertStringContainsString('o máximo é 220', implode(' ', $p['faq.texto']));
        $this->assertStringContainsString('primeira letra maiúscula', implode(' ', $p['cta.titulo']));
        // Cidade: até duas vezes fora dos campos de SEO; a terceira sobra.
        $this->assertStringContainsString('tire a cidade', implode(' ', $p['faq.titulo']));
        $this->assertArrayNotHasKey('dep.titulo', $p);
        // Lista: termo proibido e itens de menos.
        $this->assertStringContainsString('termo proibido "sem dor"', implode(' ', $p['lista:passos']));
        $this->assertStringContainsString('precisa de 3 itens', implode(' ', $p['lista:passos']));
        // Clichê que a própria descrição usa não é apontado como vago.
        $this->assertStringNotContainsString('evite "atendimento humanizado"', json_encode($p, JSON_UNESCAPED_UNICODE));
        // Graves (o filtro descartaria ou cortaria): só esses impedem trocar o rascunho pela correção.
        $this->assertEqualsCanonicalizing(['faq.texto', 'lista:passos'], array_keys(RevisorCopy::graves($p)));
    }

    public function testRevisaoReescreveSoOsCamposApontadosENaoPiora(): void
    {
        $lib = $this->app->biblioteca();
        $doc = $this->c->get('/api/sites/' . $this->site['id'])->dados()['site']['documento'];
        $rascunho = self::resposta();
        $rascunho['textos']['hero.titulo'] = 'Dentista em Jundiaí';
        $rascunho['textos']['cta.titulo'] = 'Agende com quem entende de implantes';
        $chamadas = [];
        $ia = new Simulado(function (string $i, string $pedido, array $esquema) use (&$chamadas, $rascunho): array {
            $chamadas[] = [$pedido, $esquema];
            if (count($chamadas) === 1) {
                return $rascunho;
            }
            return ['textos' => [
                'hero.titulo' => 'Volte a sorrir com implantes dentários em Jundiaí',
                'hero.texto' => 'Implantes sem dor e com resultado garantido.', // piora: continua proibido → fica o rascunho
            ]];
        });
        $patch = (new GeradorConteudo($ia))->gerar($doc, $lib, 'Clínica odontológica focada em implantes, atende convênios.');
        $this->assertCount(2, $chamadas);
        [$pedido, $esquema] = $chamadas[1];
        $this->assertStringContainsString('"problemas"', $pedido);
        $this->assertStringContainsString('título genérico', $pedido);
        $this->assertArrayHasKey('hero.titulo', $esquema['properties']['textos']['properties']);
        $this->assertArrayNotHasKey('cta.titulo', $esquema['properties']['textos']['properties'], 'Campo bom não volta para a IA');
        $this->assertArrayNotHasKey('estrategia', $esquema['properties']);
        $this->assertSame('Volte a sorrir com implantes dentários em {cidade}', $patch['textos']['hero.titulo']);
        $this->assertSame('Agende com quem entende de implantes', $patch['textos']['cta.titulo']);
        $this->assertGreaterThanOrEqual(1, $patch['revisados']);

        // Revisão desligada: um pedido só.
        $chamadas = [];
        $patch = (new GeradorConteudo($ia, false))->gerar($doc, $lib, 'Clínica odontológica focada em implantes, atende convênios.');
        $this->assertCount(1, $chamadas);
        $this->assertSame(0, $patch['revisados']);
        $this->assertSame('Dentista em {cidade}', $patch['textos']['hero.titulo']);

        // Revisão que falha: fica o rascunho, sem erro.
        $n = 0;
        $falha = new Simulado(function () use (&$n, $rascunho): array {
            if (++$n === 2) {
                throw new ErroIa('instável', true);
            }
            return $rascunho;
        });
        $patch = (new GeradorConteudo($falha))->gerar($doc, $lib, 'Clínica odontológica focada em implantes, atende convênios.');
        $this->assertSame('Dentista em {cidade}', $patch['textos']['hero.titulo']);
        $this->assertSame(0, $patch['revisados']);
    }

    public function testCortarETermoProibido(): void
    {
        // Texto longo: corta no fim da última frase completa (sem deixar frase pela metade).
        $this->assertSame('Primeira frase completa. Segunda frase também.', GeradorConteudo::cortar('Primeira frase completa. Segunda frase também. Terceira frase que não cabe', 60));
        $this->assertSame('Título sem ponto', GeradorConteudo::semPontoFinal('Título sem ponto.'));
        $this->assertSame('Será?', GeradorConteudo::semPontoFinal('Será?'));
        $this->assertSame('E então...', GeradorConteudo::semPontoFinal('E então...'));
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
