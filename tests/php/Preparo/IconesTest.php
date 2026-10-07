<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Biblioteca;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Icones;

final class IconesTest extends TestCase
{
    private static ?array $lib = null;

    private static function lib(): array
    {
        return self::$lib ??= Biblioteca::carregar(dirname(__DIR__, 2) . '/fixtures/biblioteca-mini');
    }

    public function testExemplosDoPdf(): void
    {
        $esperado = [
            'Direito Trabalhista' => 'Crachá',
            'Direito Previdenciário' => 'Documento pessoal',
            'Planejamento tributário' => 'Gráfico em alta',
            'Contador dedicado' => 'Pessoa',
            'Energia solar' => 'Painel solar',
            'Ortodontia' => 'Aparelho dental',
            'Implantes' => 'Implante',
            'Harmonização facial' => 'Brilho',
            'Projetos elétricos' => 'Raio',
            'Plantão 24 horas' => 'Relógio',
        ];
        foreach ($esperado as $titulo => $nome) {
            $id = Icones::escolherIcone($titulo, self::lib()['icones']);
            self::assertSame($nome, Icones::acharIcone(self::lib(), $id)['nome'] ?? null, $titulo);
        }
    }

    public function testMaisLongaVenceEPalavraCurtaSoInteira(): void
    {
        $icones = self::lib()['icones'];
        self::assertSame('prancheta', Icones::escolherIcone('Consultoria técnica', $icones));
        self::assertSame('maleta', Icones::escolherIcone('Consultoria', $icones));
        self::assertSame('documento', Icones::escolherIcone('Emissão de ART', $icones));
        self::assertSame('documento', Icones::escolherIcone('(ART)', $icones));
        self::assertSame('raio', Icones::escolherIcone('Parte elétrica', $icones));
        self::assertNull(Icones::escolherIcone('Arte', $icones));
        self::assertNull(Icones::escolherIcone('art2', $icones));
        self::assertNull(Icones::escolherIcone('', $icones));
    }

    public function testEmpateFicaComAOrdemDoArquivo(): void
    {
        $lista = [['id' => 'a', 'palavras' => ['abcd']], ['id' => 'b', 'palavras' => ['wxyz']]];
        self::assertSame('a', Icones::escolherIcone('abcd wxyz', $lista));
        self::assertSame('b', Icones::escolherIcone('abcd wxyz', array_reverse($lista)));
    }

    public function testCadeiaDoIconeDoItem(): void
    {
        $lib = self::lib();
        $doc = Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno'], $lib);
        self::assertSame('aparelho', Icones::iconeDoItem($doc, $lib, 'serv', '1', 0));
        $doc['icones'] = ['serv.1' => 'dente', 'serv.2' => 'nao-existe'];
        self::assertSame('dente', Icones::iconeDoItem($doc, $lib, 'serv', '1', 0));
        self::assertSame('implante', Icones::iconeDoItem($doc, $lib, 'serv', '2', 1));
        $doc['textos'] = ['serv.5.t' => 'Sem palavra conhecida', 'serv.6.t' => 'Outra coisa'];
        self::assertSame('dente', Icones::iconeDoItem($doc, $lib, 'serv', '5', 4));
        self::assertSame('aparelho', Icones::iconeDoItem($doc, $lib, 'serv', '6', 5));
        self::assertSame('circulo', Icones::iconeDoItem($doc, $lib, 'faq', '1', 0));
    }

    public function testSvgPorAcabamento(): void
    {
        $lib = self::lib();
        $dente = Icones::acharIcone($lib, 'dente')['svg'];
        self::assertSame($dente['fino'], Icones::svg($lib, 'dente', 'classico'));
        self::assertSame($dente['duotone'], Icones::svg($lib, 'dente', 'moderno'));
        self::assertSame($dente['preenchido'], Icones::svg($lib, 'dente', 'direto'));
        self::assertSame($lib['icones']['utilitarios']['seta']['svg']['preenchido'], Icones::svg($lib, 'seta', 'direto'));
        self::assertSame('', Icones::svg($lib, 'nao-existe', 'moderno'));
    }
}
