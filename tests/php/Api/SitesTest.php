<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Testes\Lib\AmbienteTeste;
use Rankly\Testes\Lib\Imagens;

final class SitesTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private ClienteApi $c;

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
        AmbienteTeste::usuario($this->app, 'admin', 'admin@rankly.teste');
        $this->c = new ClienteApi($this->app);
        $this->c->login('admin@rankly.teste', 'senha-forte-123');
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    private function criar(string $nome = 'Clínica Sorriso Vivo', array $extra = []): array
    {
        $r = $this->c->post('/api/sites', $extra + [
            'nicho' => 'clinicas', 'especialidade' => 'odontologia', 'modelo' => 'moderno',
            'dados' => ['nome' => $nome, 'cidade' => 'Jundiaí', 'uf' => 'SP', 'whatsapp' => '(11) 98765-4321'],
        ]);
        $this->assertSame(201, $r->status, $r->corpo);
        return $r->dados()['site'];
    }

    public function testCriaSiteComDocumentoInicialESlug(): void
    {
        $s = $this->criar();
        $this->assertSame('clinicasorrisovivo', $s['slug']);
        $this->assertSame('https://clinicasorrisovivo.sites.teste', $s['url']);
        $this->assertSame('rascunho', $s['status']);
        $this->assertSame(1, $s['revisao']);
        $doc = $s['documento'];
        $this->assertSame(2, $doc['versaoEsquema']);
        $this->assertSame('odontologia', $doc['especialidade']);
        $this->assertSame(['header', 'hero', 'servicos', 'faq', 'rodape'], array_column($doc['secoes'], 'tipo'));
        $this->assertSame('#c23b6e', $doc['estilo']['cor']);
        $this->assertSame('Jundiaí', $doc['dados']['cidade']);
        $this->assertNull($doc['dados']['logo']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $s['atualizadoEm']);
        // Mapas vazios saem como {} (o editor depende do tipo objeto).
        $this->assertStringContainsString('"textos":{}', $this->c->get('/api/sites/' . $s['id'])->corpo);
        $this->assertStringContainsString('"midia":{}', $this->c->get('/api/sites/' . $s['id'])->corpo);
        $this->assertSame(1, (int) $this->app->db()->valor("SELECT COUNT(*) FROM eventos WHERE tipo = 'site.criado'"));
    }

    public function testSlugUnicoComNumero(): void
    {
        $this->assertSame('sorrisovivo', $this->criar('Sorriso Vivo')['slug']);
        $this->assertSame('sorrisovivo2', $this->criar('Sorriso  VIVO!')['slug']);
        $this->assertSame('sorrisovivo3', $this->criar('sorriso-vivo')['slug']);
        $longo = str_repeat('a', 60);
        $this->assertSame(str_repeat('a', 40), $this->criar(substr($longo, 0, 60))['slug']);
        $this->assertSame(str_repeat('a', 39) . '2', $this->criar(substr($longo, 0, 60))['slug']);
        // Nome vazio: usa o exemplo do nicho.
        $s = $this->criar('');
        $this->assertSame('clinicasorrisovivo', $s['slug']);
        $this->assertSame('Clínica Sorriso Vivo', $s['nome']);
        // Reservado (subdomínio de infraestrutura) ganha número.
        $this->assertSame('www2', $this->criar('WWW')['slug']);
    }

    public function testCriacaoValidaEscolhas(): void
    {
        $base = ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'A']];
        $this->assertSame(422, $this->c->post('/api/sites', ['nicho' => 'padaria'] + $base)->status);
        $this->assertSame(422, $this->c->post('/api/sites', ['modelo' => 'xyz'] + $base)->status);
        $this->assertSame(422, $this->c->post('/api/sites', ['especialidade' => 'cardiologia'] + $base)->status);
        $r = $this->c->post('/api/sites', ['estilo' => ['cor' => 'vermelho']] + $base);
        $this->assertSame(422, $r->status);
        $this->assertSame('estilo.cor', $r->dados()['campo']);
        $r = $this->c->post('/api/sites', ['dados' => ['nome' => str_repeat('x', 61)]] + $base);
        $this->assertSame(422, $r->status);
        $this->assertSame('dados.nome', $r->dados()['chave']);
        $s = $this->c->post('/api/sites', ['estilo' => ['cor' => '#AABBCC', 'fonte' => 'classica']] + $base)->dados()['site'];
        $this->assertSame('#aabbcc', $s['documento']['estilo']['cor']);
        $this->assertSame('classica', $s['documento']['estilo']['fonte']);
        $this->assertSame('odontologia', $s['documento']['especialidade'], 'Sem especialidade: a primeira do nicho');
    }

    public function testListaComLeadsNaoLidosEArquivamento(): void
    {
        $a = $this->criar('Alfa');
        $b = $this->criar('Beta');
        $agora = $this->app->agoraSql();
        foreach ([null, null, $agora] as $lido) {
            $this->app->db()->inserir('leads', ['site_id' => $a['id'], 'nome' => 'X', 'telefone' => '11999999999', 'ip_hash' => 'h', 'criado_em' => $agora, 'lido_em' => $lido]);
        }
        $lista = $this->c->get('/api/sites')->dados()['sites'];
        $this->assertCount(2, $lista);
        $porId = array_column($lista, null, 'id');
        $this->assertSame(2, $porId[$a['id']]['leadsNaoLidos']);
        $this->assertSame(0, $porId[$b['id']]['leadsNaoLidos']);
        $this->assertSame(['id', 'slug', 'nome', 'nicho', 'modelo', 'status', 'atualizadoEm', 'publicadoEm', 'url', 'leadsNaoLidos'], array_keys($lista[0]));

        $this->assertSame(['ok' => true], $this->c->delete('/api/sites/' . $b['id'])->dados());
        $this->assertCount(1, $this->c->get('/api/sites')->dados()['sites']);
        $this->assertCount(2, $this->c->get('/api/sites?arquivados=1')->dados()['sites']);
        $this->assertSame('arquivado', $this->c->get('/api/sites/' . $b['id'])->dados()['site']['status']);
        $r = $this->c->put('/api/sites/' . $b['id'], ['revisao' => 1, 'documento' => $b['documento']]);
        $this->assertSame(403, $r->status);
    }

    public function testSalvarIncrementaRevisaoERegistraEvento(): void
    {
        $s = $this->criar();
        $doc = $s['documento'];
        $doc['textos'] = ['hero.titulo' => 'Seu sorriso em boas mãos em {cidade}'];
        $doc['estilo']['cor'] = '#2A7F86';
        $r = $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc]);
        $this->assertSame(200, $r->status, $r->corpo);
        $this->assertSame(['revisao' => 2], $r->dados());
        $salvo = $this->c->get('/api/sites/' . $s['id'])->dados()['site'];
        $this->assertSame(2, $salvo['revisao']);
        $this->assertSame('#2a7f86', $salvo['documento']['estilo']['cor']);
        $this->assertSame('Seu sorriso em boas mãos em {cidade}', $salvo['documento']['textos']['hero.titulo']);

        // Segundo salvamento logo depois: o evento é agrupado.
        $doc['textos']['serv.1.t'] = 'Implantes';
        $this->assertSame(['revisao' => 3], $this->c->put('/api/sites/' . $s['id'], ['revisao' => 2, 'documento' => $doc])->dados());
        $eventos = $this->app->db()->todos("SELECT * FROM eventos WHERE tipo = 'site.salvo'");
        $this->assertCount(1, $eventos);
        $det = json_decode($eventos[0]['detalhe'], true);
        $this->assertSame(3, $det['revisaoFinal']);
        $this->assertSame(['hero.titulo', 'serv.1.t'], $det['alteracoes']['textos']);
        $this->assertSame(['cor'], $det['alteracoes']['estilo']);

        // Mesmo documento de novo: nada muda.
        $this->assertSame(['revisao' => 3], $this->c->put('/api/sites/' . $s['id'], ['revisao' => 3, 'documento' => $doc])->dados());

        // Nome do negócio alterado atualiza o nome do site.
        $doc['dados']['nome'] = 'Sorriso Pleno';
        $this->c->put('/api/sites/' . $s['id'], ['revisao' => 3, 'documento' => $doc]);
        $this->assertSame('Sorriso Pleno', $this->c->get('/api/sites/' . $s['id'])->dados()['site']['nome']);
    }

    public function testConflitoDeRevisao409(): void
    {
        $s = $this->criar();
        $doc = $s['documento'];
        $doc['textos'] = ['hero.titulo' => 'Aba A'];
        $this->assertSame(200, $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc])->status);
        $doc['textos'] = ['hero.titulo' => 'Aba B'];
        $r = $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc]);
        $this->assertSame(409, $r->status);
        $d = $r->dados();
        $this->assertSame('conflito', $d['erro']['codigo']);
        $this->assertSame('Este site foi alterado em outra janela.', $d['erro']['mensagem']);
        $this->assertSame(2, $d['revisaoAtual']);
        $this->assertSame('Aba A', $d['documento']['textos']['hero.titulo']);
        $this->assertSame(422, $this->c->put('/api/sites/' . $s['id'], ['documento' => $doc])->status);
    }

    /** @return iterable<string, array{0: callable, 1: string}> */
    public static function documentosInvalidos(): iterable
    {
        yield 'texto acima do max do manifesto' => [fn (array $d): array => ['textos' => ['hero.titulo' => str_repeat('a', 91)]] + $d, 'hero.titulo'];
        yield 'item de lista acima do max' => [fn (array $d): array => ['textos' => ['serv.nk3f.t' => str_repeat('a', 41)]] + $d, 'serv.nk3f.t'];
        yield 'chave de texto mal formada' => [fn (array $d): array => ['textos' => ['Hero.Titulo' => 'x']] + $d, 'Hero.Titulo'];
        yield 'escalar proibido' => [fn (array $d): array => ['textos' => ['serv.itens' => 'x']] + $d, 'serv.itens'];
        yield 'chave de imagem como texto' => [fn (array $d): array => ['textos' => ['hero.img' => 'x']] + $d, 'hero.img'];
        yield 'cor inválida' => [function (array $d): array { $d['estilo']['cor'] = 'red'; return $d; }, 'estilo.cor'];
        yield 'cor curta' => [function (array $d): array { $d['estilo']['cor'] = '#abc'; return $d; }, 'estilo.cor'];
        yield 'acabamento inválido' => [function (array $d): array { $d['estilo']['acabamento'] = 'barroco'; return $d; }, 'estilo.acabamento'];
        yield 'seção inexistente' => [function (array $d): array { $d['secoes'][] = ['tipo' => 'hero', 'opcao' => 'nao-existe']; return $d; }, 'secoes'];
        yield 'mídia de outro site' => [fn (array $d): array => ['imagens' => ['hero.img' => 'm_deadbeef']] + $d, 'hero.img'];
        yield 'logo de outro site' => [function (array $d): array { $d['dados']['logo'] = 'm_deadbeef'; return $d; }, 'dados.logo'];
        yield 'lista com itens demais' => [fn (array $d): array => ['listas' => ['serv' => ['1', '2', '3', '4', '5', '6', '7', '8', '9']]] + $d, 'listas.serv'];
        yield 'lista com id repetido' => [fn (array $d): array => ['listas' => ['serv' => ['1', '1', '2']]] + $d, 'listas.serv'];
        yield 'rastreamento com script' => [function (array $d): array { $d['rastreamento']['gtm'] = '"><script>'; return $d; }, 'rastreamento.gtm'];
        yield 'versão do esquema' => [function (array $d): array { $d['versaoEsquema'] = 1; return $d; }, 'versaoEsquema'];
        yield 'nicho desconhecido' => [function (array $d): array { $d['nicho'] = 'padaria'; return $d; }, 'nicho'];
        yield 'controle no texto' => [fn (array $d): array => ['textos' => ['hero.titulo' => "a\x07b"]] + $d, 'hero.titulo'];
        yield 'quebra de linha em texto curto' => [fn (array $d): array => ['textos' => ['hero.titulo' => "a\nb"]] + $d, 'hero.titulo'];
        yield 'horário inválido' => [function (array $d): array { $d['dados']['horarios'] = ['seg' => ['8h', '18h']]; return $d; }, 'dados.horarios.seg'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentosInvalidos')]
    public function testValidacaoDoDocumento(callable $alterar, string $chave): void
    {
        $s = $this->criar();
        $r = $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $alterar($s['documento'])]);
        $this->assertSame(422, $r->status, $r->corpo);
        $this->assertSame('documento_invalido', $r->dados()['erro']['codigo']);
        $this->assertSame($chave, $r->dados()['chave'] ?? null);
        $this->assertNotSame('', $r->dados()['erro']['mensagem']);
    }

    public function testLimiteDeTextoContaOTextoExibido(): void
    {
        $s = $this->criar('Clínica Odontológica Sorriso Vivo Jundiaí');
        // {nome} vira 41 caracteres: armazenados 6 + 1 + 49 = 56; exibidos 41 + 1 + 49 = 91 (> 90).
        $doc = $s['documento'];
        $doc['textos'] = ['hero.titulo' => '{nome} ' . str_repeat('b', 49)];
        $r = $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc]);
        $this->assertSame(422, $r->status);
        $doc['textos'] = ['hero.titulo' => '{nome} ' . str_repeat('b', 48)];
        $this->assertSame(200, $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc])->status);
    }

    public function testDocumentoGrandeDemais413(): void
    {
        $dir = null;
        $app = AmbienteTeste::criar(['tamanho_max_documento_kb' => 16], $dir);
        try {
            AmbienteTeste::usuario($app, 'admin', 'a@rankly.teste');
            $c = new ClienteApi($app);
            $c->login('a@rankly.teste', 'senha-forte-123');
            $s = $c->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => ['nome' => 'X']])->dados()['site'];
            $doc = $s['documento'];
            for ($i = 0; $i < 200; $i++) {
                $doc['textos']['faq.n' . $i . '.a'] = str_repeat('resposta ', 30);
            }
            $r = $c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc]);
            $this->assertSame(413, $r->status);
            $this->assertSame('documento_grande', $r->dados()['erro']['codigo']);
        } finally {
            AmbienteTeste::remover($dir);
        }
    }

    public function testPermissoesDeClienteEEquipe(): void
    {
        $s = $this->criar();
        $cliente = AmbienteTeste::usuario($this->app, 'cliente', 'cliente@rankly.teste');
        $cc = new ClienteApi($this->app);
        $cc->login('cliente@rankly.teste', 'senha-forte-123');

        $this->assertSame([], $cc->get('/api/sites')->dados()['sites']);
        $this->assertSame(404, $cc->get('/api/sites/' . $s['id'])->status, 'Sem acesso: 404 (não revela existência)');
        $this->assertSame(404, $cc->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $s['documento']])->status);
        $this->assertSame(403, $cc->post('/api/sites', ['nicho' => 'clinicas', 'modelo' => 'moderno'])->status);

        $this->app->db()->inserir('site_acessos', ['site_id' => $s['id'], 'usuario_id' => (int) $cliente['id'], 'papel' => 'editor']);
        $this->assertCount(1, $cc->get('/api/sites')->dados()['sites']);
        $this->assertSame(200, $cc->get('/api/sites/' . $s['id'])->status);
        $doc = $s['documento'];
        $doc['textos'] = ['hero.titulo' => 'Editado pelo cliente'];
        $this->assertSame(200, $cc->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc])->status);
        $this->assertSame(403, $cc->delete('/api/sites/' . $s['id'])->status);
        $this->assertSame(403, $cc->post('/api/sites/' . $s['id'] . '/duplicar')->status);

        // Equipe acessa todos.
        AmbienteTeste::usuario($this->app, 'equipe', 'equipe@rankly.teste');
        $ce = new ClienteApi($this->app);
        $ce->login('equipe@rankly.teste', 'senha-forte-123');
        $this->assertSame(200, $ce->get('/api/sites/' . $s['id'])->status);
        $this->assertSame(404, $ce->get('/api/sites/99999')->status);
    }

    public function testDuplicarCopiaDocumentoEMidiaComIdsNovos(): void
    {
        $s = $this->criar();
        $foto = Imagens::jpeg($this->dir . '/foto.jpg', 1200, 800);
        $m = $this->c->enviarArquivo('/api/media', ['site_id' => (string) $s['id'], 'tipo' => 'foto'], $foto)->dados()['midia'];
        $logo = Imagens::png($this->dir . '/logo.png', 400, 200, true);
        $l = $this->c->enviarArquivo('/api/media', ['site_id' => (string) $s['id'], 'tipo' => 'logo'], $logo, 'arquivo', 'logo.png')->dados()['midia'];
        $doc = $s['documento'];
        $doc['imagens'] = ['hero.img' => $m['id'], 'serv.1.img' => $m['id']];
        $doc['dados']['logo'] = $l['id'];
        $doc['textos'] = ['hero.titulo' => 'Original'];
        $this->assertSame(200, $this->c->put('/api/sites/' . $s['id'], ['revisao' => 1, 'documento' => $doc])->status);

        $r = $this->c->post('/api/sites/' . $s['id'] . '/duplicar');
        $this->assertSame(201, $r->status, $r->corpo);
        $copia = $r->dados()['site'];
        $this->assertSame('copiadeclinicasorrisovivo', $copia['slug']);
        $this->assertSame('Cópia de Clínica Sorriso Vivo', $copia['nome']);
        $this->assertSame('rascunho', $copia['status']);
        $this->assertSame('Original', $copia['documento']['textos']['hero.titulo']);
        $novaFoto = $copia['documento']['imagens']['hero.img'];
        $this->assertNotSame($m['id'], $novaFoto);
        $this->assertSame($novaFoto, $copia['documento']['imagens']['serv.1.img']);
        $this->assertNotSame($l['id'], $copia['documento']['dados']['logo']);

        $midiaCopia = $this->c->get('/api/sites/' . $copia['id'])->dados()['midia'];
        $this->assertArrayHasKey($novaFoto, $midiaCopia);
        $this->assertSame($m['variantes'], $midiaCopia[$novaFoto]['variantes']);
        $img = $this->c->get('/api/media/' . $novaFoto . '/480');
        $this->assertSame(200, $img->status);
        $this->assertFileEquals(
            $this->app->dir('media') . '/' . $s['id'] . '/' . $m['id'] . '/480.webp',
            (string) $img->arquivo,
        );
        // O original segue intacto.
        $this->assertSame($m['id'], $this->c->get('/api/sites/' . $s['id'])->dados()['site']['documento']['imagens']['hero.img']);
    }
}
