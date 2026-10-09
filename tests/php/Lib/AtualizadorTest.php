<?php

declare(strict_types=1);

namespace Rankly\Testes\Lib;

use PHPUnit\Framework\TestCase;
use Rankly\Aplicacao;
use Rankly\Lib\Atualizador;

/**
 * Atualização automática da hospedagem: GitHub falso, instalação de mentira numa pasta temporária
 * (projeto fora da web + pasta pública separada, como o instalar.php deixa).
 */
final class AtualizadorTest extends TestCase
{
    private string $dir;
    private string $proj;
    private string $web;
    /** @var list<array{0: string, 1: list<string>}> */
    private array $pedidos = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rankly-atualizador-' . bin2hex(random_bytes(5));
        $this->proj = $this->dir . '/domains/meudominio/rankly-sistema';
        $this->web = $this->dir . '/domains/meudominio/public_html/editor';
        $arquivos = [
            $this->proj . '/app/x.txt' => 'antigo',
            $this->proj . '/VERSAO.txt' => "Pacote: x\nCódigo: aaaaaaa\n",
            $this->proj . '/config/config.php' => '<?php return []; // segredos da instalação',
            $this->proj . '/media/foto.webp' => 'foto do cliente',
            $this->proj . '/sites/.releases/loja/1/index.html' => 'site publicado',
            $this->proj . '/.htaccess' => 'Require all denied',
            $this->web . '/editor/index.html' => 'editor antigo',
            $this->web . '/editor/js/velho.mjs' => 'apagar',
            $this->web . '/rankly-raiz.php' => '<?php return "CAMINHO-DO-INSTALADOR";',
        ];
        foreach ($arquivos as $arq => $conteudo) {
            @mkdir(dirname($arq), 0775, true);
            file_put_contents($arq, $conteudo);
        }
        symlink('.releases/loja/1', $this->proj . '/sites/loja');
        @mkdir($this->proj . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        Atualizador::apagar($this->dir);
    }

    private function app(array $atualizacao = []): Aplicacao
    {
        return Aplicacao::iniciar([
            'ambiente' => 'dev',
            'db' => ['driver' => 'sqlite', 'dsn' => 'sqlite:' . $this->dir . '/banco.sqlite', 'usuario' => null, 'senha' => null],
            'dir_biblioteca' => AmbienteTeste::fixtureBiblioteca(),
            'segredo_app' => 'segredo-app-de-teste',
            'segredo_ip' => 'segredo-ip-de-teste',
            'atualizacao' => $atualizacao + ['github_repo' => 'ury1996/jogo', 'github_token' => 'github_pat_SEGREDO', 'dir_web' => $this->web],
        ], $this->proj);
    }

    /** Pacote como o bin/empacotar-hospedagem.php gera (mínimo). */
    private function pacote(string $codigo = 'bbbbbbb'): string
    {
        $zip = $this->dir . '/pacote-' . $codigo . '.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('rankly/app/bootstrap.php', '<?php // novo');
        $z->addFromString('rankly/app/x.txt', 'novo');
        $z->addFromString('rankly/vendor/autoload.php', '<?php');
        $z->addFromString('rankly/VERSAO.txt', "Pacote: y\nCódigo: {$codigo}\n");
        $z->addFromString('rankly/config/config.exemplo.php', '<?php return [];');
        $z->addFromString('rankly/public_html/editor/index.html', 'editor novo');
        $z->addFromString('rankly/public_html/editor/js/novo.mjs', 'novo');
        $z->addFromString('rankly/public_html/api/index.php', '<?php // api nova');
        $z->addFromString('rankly/public_html/rankly-raiz.php', '<?php return dirname(__DIR__);');
        $z->addFromString('rankly/public_html/router-dev.php', '<?php // só desenvolvimento');
        $z->addFromString('rankly/public_html/s.php', '<?php // s');
        $z->close();
        return $zip;
    }

    /** GitHub falso: release "hospedagem" → asset → redirecionamento para o arquivo. */
    private function github(string $codigo = 'bbbbbbb', int $statusRelease = 200, ?string $zip = null): callable
    {
        $zip ??= $this->pacote($codigo);
        return function (string $url, array $cab, ?string $salvarEm) use ($codigo, $statusRelease, $zip): array {
            $this->pedidos[] = [$url, $cab];
            if (str_ends_with($url, '/repos/ury1996/jogo/releases/tags/hospedagem')) {
                return [$statusRelease, (string) json_encode(['body' => "Pacote testado.\ncodigo={$codigo}", 'assets' => [
                    ['name' => 'instalar.php', 'url' => 'https://api.github.com/repos/ury1996/jogo/releases/assets/1'],
                    ['name' => Atualizador::ASSET, 'url' => 'https://api.github.com/repos/ury1996/jogo/releases/assets/2'],
                ]]), []];
            }
            if ($url === 'https://api.github.com/repos/ury1996/jogo/releases/assets/2') {
                return [302, '', ['location' => 'https://objetos.github.example/pacote.zip?assinatura=1']];
            }
            if (str_starts_with($url, 'https://objetos.github.example/') && $salvarEm !== null) {
                copy($zip, $salvarEm);
                return [200, '', []];
            }
            return [404, '', []];
        };
    }

    public function testTrocaOCodigoMantendoAInstalacaoEDepoisMigraERepublica(): void
    {
        $at = new Atualizador($this->app(), $this->github());
        $this->assertTrue($at->configurado());
        $this->assertSame('aaaaaaa', $at->versaoInstalada());

        $msg = $at->talvezAtualizar(true);
        $this->assertStringContainsString('Atualizado para bbbbbbb', (string) $msg);

        // Código novo; o que é da instalação continua.
        $this->assertSame('novo', file_get_contents($this->proj . '/app/x.txt'));
        $this->assertStringContainsString('bbbbbbb', file_get_contents($this->proj . '/VERSAO.txt'));
        $this->assertStringContainsString('segredos da instalação', file_get_contents($this->proj . '/config/config.php'));
        $this->assertSame('foto do cliente', file_get_contents($this->proj . '/media/foto.webp'));
        $this->assertTrue(is_link($this->proj . '/sites/loja'));
        $this->assertSame('site publicado', file_get_contents($this->proj . '/sites/loja/index.html'));
        $this->assertSame('Require all denied', file_get_contents($this->proj . '/.htaccess'));
        $this->assertSame([], glob(dirname($this->proj) . '/rankly-sistema.*') ?: [], 'Sem sobras da versão antiga');
        $this->assertSame([], glob(dirname($this->proj) . '/.rankly-novo-*') ?: [], 'Sem temporários');

        // Pasta pública sincronizada; o caminho gravado pelo instalador fica; nada de router-dev.
        $this->assertSame('editor novo', file_get_contents($this->web . '/editor/index.html'));
        $this->assertFileExists($this->web . '/editor/js/novo.mjs');
        $this->assertFileDoesNotExist($this->web . '/editor/js/velho.mjs');
        $this->assertStringContainsString('CAMINHO-DO-INSTALADOR', file_get_contents($this->web . '/rankly-raiz.php'));
        $this->assertFileDoesNotExist($this->web . '/router-dev.php');

        // O token só vai para a API do GitHub, nunca para o endereço temporário do arquivo.
        foreach ($this->pedidos as [$url, $cab]) {
            $temToken = (bool) preg_grep('/^Authorization: Bearer github_pat_SEGREDO$/', $cab);
            $this->assertSame(str_starts_with($url, 'https://api.github.com/'), $temToken, $url);
        }

        // Próximo cron (código novo): migrações + republicação; depois disso, nada a fazer.
        $app = $this->app();
        $at2 = new Atualizador($app, $this->github());
        $this->assertTrue($at2->posPendente());
        $this->assertStringContainsString('Pós-atualização', (string) $at2->talvezAtualizar());
        $this->assertFalse($at2->posPendente());
        $this->assertSame('bbbbbbb', $at2->estado()['versao']);
        $this->assertNull($at2->verificar(), 'Mesmo commit: sem atualização');
        $this->assertNull($at2->talvezAtualizar(), 'Dentro do intervalo: nem consulta');
    }

    public function testFalhasNaoMexemNaInstalacao(): void
    {
        // Token recusado.
        $at = new Atualizador($this->app(), $this->github('bbbbbbb', 401));
        $this->assertStringContainsString('token do GitHub foi recusado', (string) $at->talvezAtualizar(true));
        $this->assertStringContainsString('token', (string) $at->estado()['ultimoErro']);

        // Pacote corrompido.
        $ruim = $this->dir . '/ruim.zip';
        file_put_contents($ruim, 'PK' . str_repeat('x', 4000));
        $at = new Atualizador($this->app(), $this->github('ccccccc', 200, $ruim));
        $this->assertStringContainsString('corrompido', (string) $at->talvezAtualizar(true));

        // Pasta pública sumiu no meio da troca: a versão antiga volta inteira.
        $at = new Atualizador($this->app(['dir_web' => $this->dir . '/nao-existe']), $this->github('ddddddd'));
        $this->assertStringContainsString('Pasta pública não encontrada', (string) $at->talvezAtualizar(true));

        $this->assertSame('antigo', file_get_contents($this->proj . '/app/x.txt'));
        $this->assertSame('aaaaaaa', (new Atualizador($this->app()))->versaoInstalada());
        $this->assertStringContainsString('segredos da instalação', file_get_contents($this->proj . '/config/config.php'));
        $this->assertSame('foto do cliente', file_get_contents($this->proj . '/media/foto.webp'));
        $this->assertTrue(is_link($this->proj . '/sites/loja'));
        $this->assertSame('editor antigo', file_get_contents($this->web . '/editor/index.html'));
        $this->assertSame([], glob(dirname($this->proj) . '/.rankly-novo-*') ?: []);
        $this->assertFalse((new Atualizador($this->app()))->posPendente());
    }

    public function testSemConfiguracaoNaoFazNada(): void
    {
        $at = new Atualizador($this->app(['github_token' => '']), function (): array {
            $this->fail('Não deveria consultar o GitHub');
        });
        $this->assertFalse($at->configurado());
        $this->assertNull($at->talvezAtualizar());
    }
}
