<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Executores;
use Rankly\Lib\IpHash;
use Rankly\Lib\Leads;

final class LeadsTest extends TestCase
{
    private Aplicacao $app;
    private ?string $dir = null;
    private array $site;

    private const VALIDO = [
        'nome' => 'Maria Souza', 'telefone' => '(11) 98888-7777', 'email' => 'maria@exemplo.com.br',
        'mensagem' => "Quero agendar.\nDe manhã.", '_t' => '8000', 'empresa_site' => '',
    ];

    protected function setUp(): void
    {
        $this->app = AmbienteTeste::criar([], $this->dir);
        $dono = AmbienteTeste::usuario($this->app, 'equipe', 'dono@rankly.teste');
        $this->site = AmbienteTeste::site($this->app, 'sorrisovivo', [], (int) $dono['id']);
    }

    protected function tearDown(): void
    {
        AmbienteTeste::remover($this->dir);
    }

    public function testLeadValidoEGravadoComOrigemEIpHash(): void
    {
        $r = Leads::receber($this->app, $this->site, self::VALIDO + [
            'utm_source' => 'google', 'utm_campaign' => 'implante', 'gclid' => 'abc123', 'pagina' => 'https://sorrisovivo.sites.teste/',
            'utm_inventado' => 'x',
        ], '203.0.113.5', 'Mozilla');
        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('ignorado', $r);
        $lead = $this->app->db()->um('SELECT * FROM leads');
        $this->assertSame('Maria Souza', $lead['nome']);
        $this->assertSame('(11) 98888-7777', $lead['telefone']);
        $this->assertSame("Quero agendar.\nDe manhã.", $lead['mensagem']);
        $this->assertSame(['utm_source' => 'google', 'utm_campaign' => 'implante', 'gclid' => 'abc123', 'pagina' => 'https://sorrisovivo.sites.teste/'], json_decode($lead['origem'], true));
        // IP nunca é guardado: só o HMAC-SHA256 com segredo_ip.
        $this->assertSame(hash_hmac('sha256', '203.0.113.5', 'segredo-ip-de-teste'), $lead['ip_hash']);
        $this->assertStringNotContainsString('203.0.113.5', json_encode($this->app->db()->todos('SELECT * FROM leads')));
        $this->assertSame(1, (int) $this->app->db()->valor("SELECT COUNT(*) FROM tarefas WHERE tipo = 'email_lead' AND status = 'pendente'"));
    }

    public function testHmacDoIpDependeDoSegredoENormalizaIpv6(): void
    {
        $this->assertSame(hash_hmac('sha256', '2001:db8::/64', 's'), IpHash::hash('2001:0DB8:0000::0001', 's'));
        $this->assertSame(IpHash::hash('1.2.3.4', 's'), IpHash::hash('::ffff:1.2.3.4', 's'));
        $this->assertNotSame(IpHash::hash('1.2.3.4', 'a'), IpHash::hash('1.2.3.4', 'b'));
        $this->assertSame(64, strlen(IpHash::hash('1.2.3.4', 'a')));
    }

    /** Regressão: com IPv6, trocar de endereço dentro do mesmo /64 não zera o limite por IP. */
    public function testLimitePorIpNaoEContornadoTrocandoEnderecoIpv6NoMesmoBloco(): void
    {
        $this->assertSame(IpHash::hash('2001:db8:1:2::1', 's'), IpHash::hash('2001:db8:1:2:ffff:abcd:1234:9', 's'));
        $this->assertNotSame(IpHash::hash('2001:db8:1:2::1', 's'), IpHash::hash('2001:db8:1:3::1', 's'));
        for ($i = 1; $i <= Leads::LIMITE_POR_HORA; $i++) {
            $this->assertTrue(Leads::receber($this->app, $this->site, self::VALIDO, '2001:db8:1:2::' . dechex($i))['ok']);
        }
        $r = Leads::receber($this->app, $this->site, self::VALIDO, '2001:db8:1:2:aaaa:bbbb:cccc:dddd');
        $this->assertFalse($r['ok']);
        $this->assertSame(429, $r['status']);
    }

    public function testCloudflareSoQuandoConfiado(): void
    {
        $req = new \Rankly\Http\Requisicao('POST', '/x', [], ['CF-Connecting-IP' => '198.51.100.9'], '', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', IpHash::ipDe($this->app, $req));
        $dir = null;
        $app = AmbienteTeste::criar(['confiar_cloudflare' => true], $dir);
        try {
            $this->assertSame('198.51.100.9', IpHash::ipDe($app, $req));
        } finally {
            AmbienteTeste::remover($dir);
        }
    }

    public function testPoteDeMelDescartaComSucessoFalso(): void
    {
        $r = Leads::receber($this->app, $this->site, ['empresa_site' => 'http://spam.example'] + self::VALIDO, '1.1.1.1');
        $this->assertSame(['ok' => true, 'ignorado' => true], $r);
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM tarefas'));
    }

    public function testTempoDePreenchimentoMenorQue3SegundosDescarta(): void
    {
        $this->assertSame(['ok' => true, 'ignorado' => true], Leads::receber($this->app, $this->site, ['_t' => '2999'] + self::VALIDO, '1.1.1.1'));
        $this->assertSame(['ok' => true, 'ignorado' => true], Leads::receber($this->app, $this->site, ['_t' => 'abc'] + self::VALIDO, '1.1.1.1'));
        $this->assertSame(0, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
        // Sem JavaScript o campo vem vazio: não descarta.
        $this->assertTrue(Leads::receber($this->app, $this->site, ['_t' => ''] + self::VALIDO, '1.1.1.1')['ok']);
        $this->assertTrue(Leads::receber($this->app, $this->site, ['_t' => '3000'] + self::VALIDO, '1.1.1.2')['ok']);
        $this->assertSame(2, (int) $this->app->db()->valor('SELECT COUNT(*) FROM leads'));
    }

    public function testLimiteDeCincoPorIpPorHora(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.77')['ok']);
        }
        $r = Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.77');
        $this->assertFalse($r['ok']);
        $this->assertSame(429, $r['status']);
        $this->assertSame('limite', $r['erro']);
        $this->assertTrue(Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.78')['ok'], 'Outro IP segue livre');
        // A chave do limite também não guarda o IP.
        $this->assertSame(0, (int) $this->app->db()->valor("SELECT COUNT(*) FROM limites WHERE chave LIKE '%203.0.113%'"));
        $this->app->definirAgora($this->app->agora()->modify('+61 minutes'));
        $this->assertTrue(Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.77')['ok']);
    }

    /** A vaga reservada antes de gravar é devolvida quando os dados são recusados. */
    public function testEnvioInvalidoNaoGastaOLimite(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(422, Leads::receber($this->app, $this->site, ['telefone' => '12'] + self::VALIDO, '203.0.113.90')['status']);
        }
        for ($i = 0; $i < Leads::LIMITE_POR_HORA; $i++) {
            $this->assertTrue(Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.90')['ok']);
        }
        $this->assertSame(429, Leads::receber($this->app, $this->site, self::VALIDO, '203.0.113.90')['status']);
    }

    public function testValidacaoDosCampos(): void
    {
        $lista = [
            [['nome' => ''], 'nome'], [['nome' => str_repeat('a', 81)], 'nome'], [['telefone' => ''], 'telefone'],
            [['telefone' => '1234'], 'telefone'], [['telefone' => '119999999999'], 'telefone'], [['email' => 'nao-e-email'], 'email'],
            [['mensagem' => str_repeat('m', 1001)], 'mensagem'], [['nome' => ['array']], 'nome'],
        ];
        foreach ($lista as [$alteracao, $campo]) {
            $r = Leads::receber($this->app, $this->site, $alteracao + self::VALIDO, '1.1.1.1');
            $this->assertFalse($r['ok'], $campo);
            $this->assertSame(422, $r['status']);
            $this->assertSame($campo, $r['campo']);
            $this->assertNotEmpty($r['mensagem']);
        }
        $this->assertTrue(Leads::receber($this->app, $this->site, ['telefone' => '+55 11 98888-7777', 'email' => ''] + self::VALIDO, '1.1.1.1')['ok']);
        $this->assertSame(0, (int) $this->app->db()->valor("SELECT COUNT(*) FROM limites WHERE contagem > 1"), 'Erros de validação não contam no limite');
    }

    public function testCsvComBomPontoEVirgulaEFormulaEscapada(): void
    {
        Leads::receber($this->app, $this->site, ['nome' => '=HYPERLINK("http://mal.example","clique")', 'mensagem' => "+1; \"oi\"\n-2", 'utm_source' => '@fonte'] + self::VALIDO, '1.1.1.1');
        $csv = Leads::csv($this->app->db()->todos('SELECT * FROM leads'));
        $this->assertStringStartsWith("\xEF\xBB\xBFData (UTC);Nome;Telefone;E-mail;Mensagem;Lido;utm_source", $csv);
        $linhas = explode("\r\n", substr($csv, 3));
        $this->assertStringContainsString(';"\'=HYPERLINK(""http://mal.example"",""clique"")";', $linhas[1]);
        $this->assertStringContainsString(";\"'+1; \"\"oi\"\"\n-2\";", $csv);
        $this->assertStringContainsString(";'@fonte;", $csv);
        $this->assertSame("'-5", Leads::celulaCsv('-5'));
        $this->assertSame("'\tx", Leads::celulaCsv("\tx"));
        $this->assertSame('normal', Leads::celulaCsv('normal'));
        $this->assertSame('"a;b"', Leads::celulaCsv('a;b'));
    }

    public function testEmailDoLeadParaODono(): void
    {
        Leads::receber($this->app, $this->site, self::VALIDO + ['utm_source' => 'google'], '1.1.1.1');
        Executores::registrarTodos($this->app);
        $this->assertSame(1, $this->app->tarefas()->processar());
        $emails = AmbienteTeste::emails($this->app);
        $this->assertCount(1, $emails);
        $eml = $emails[0];
        $this->assertStringContainsString("X-Envelope-To: dono@rankly.teste\r\n", $eml, 'Sem dados.email: vai para o dono da conta');
        $this->assertStringContainsString("Reply-To: maria@exemplo.com.br\r\n", $eml);
        $this->assertStringContainsString("Subject: Novo contato pelo site: Maria Souza\r\n", $eml);
        $this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{24}\.\d+@rankly\.teste>\r$/m', $eml);
        $this->assertMatchesRegularExpression('/^Date: \w{3}, \d{2} \w{3} \d{4} /m', $eml);
        $corpo = AmbienteTeste::corpoEmail($eml);
        $this->assertStringContainsString('Telefone: (11) 98888-7777', $corpo);
        $this->assertStringContainsString('utm_source: google', $corpo);
        $this->assertStringContainsString('https://wa.me/5511988887777?text=', $corpo);
        $this->assertStringContainsString("Quero agendar.\nDe manhã.", str_replace("\r\n", "\n", $corpo));
    }

    public function testAssuntoComAcentoECodificado(): void
    {
        Leads::receber($this->app, $this->site, ['nome' => 'João Conceição'] + self::VALIDO, '1.1.1.1');
        Leads::enviarEmail($this->app, (int) $this->app->db()->valor('SELECT MAX(id) FROM leads'));
        $eml = AmbienteTeste::emails($this->app)[0];
        $this->assertMatchesRegularExpression('/^Subject: .*=\?UTF-8\?B\?/m', $eml);
        preg_match('/^Subject: (.*?)\r\n(?![ \t])/ms', $eml, $m);
        $this->assertSame('Novo contato pelo site: João Conceição', iconv_mime_decode($m[1], 0, 'UTF-8'));
    }

    public function testEmailVaiParaOEmailDoNegocioQuandoHouver(): void
    {
        $site = AmbienteTeste::site($this->app, 'outro', ['dados' => ['nome' => 'Outro', 'email' => 'contato@outro.com.br']]);
        Leads::receber($this->app, $site, ['email' => ''] + self::VALIDO, '1.1.1.1');
        Leads::enviarEmail($this->app, (int) $this->app->db()->valor('SELECT MAX(id) FROM leads'));
        $eml = AmbienteTeste::emails($this->app)[0];
        $this->assertStringContainsString("X-Envelope-To: contato@outro.com.br\r\n", $eml);
        $this->assertStringNotContainsString('Reply-To:', $eml, 'Sem e-mail do visitante, sem Reply-To');
    }
}
