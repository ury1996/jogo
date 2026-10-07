<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Biblioteca;

final class BibliotecaTest extends TestCase
{
    private static function mini(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/biblioteca-mini';
    }

    public function testEstruturaDoBundle(): void
    {
        $lib = Biblioteca::carregar(self::mini());
        self::assertSame(['versao', 'secoes', 'parciais', 'baseCss', 'modelos', 'nichos', 'comum', 'icones', 'fontes'], array_keys($lib));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $lib['versao']);
        self::assertSame(['faq', 'header', 'hero', 'rodape', 'servicos'], array_keys($lib['secoes']));
        self::assertSame(['manifest', 'templates', 'css'], array_keys($lib['secoes']['servicos']));
        self::assertSame(['cards', 'lista'], array_keys($lib['secoes']['servicos']['templates']));
        self::assertSame(['formulario'], array_keys($lib['parciais']));
        self::assertSame(['clinicas'], array_keys($lib['nichos']));
        self::assertSame(['classico', 'moderno'], array_keys($lib['modelos']));
        self::assertArrayHasKey('novoItem', $lib['comum']);
    }

    public function testVersaoDeterministica(): void
    {
        $tmp = sys_get_temp_dir() . '/rankly-bib-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/b', 0777, true);
        file_put_contents($tmp . '/b/x.json', '{}');
        file_put_contents($tmp . '/a.css', 'a');
        file_put_contents($tmp . '/B.txt', 'maiúscula vem antes');
        file_put_contents($tmp . '/.oculto', 'não entra');
        try {
            self::assertSame(['B.txt', 'a.css', 'b/x.json'], Biblioteca::listarArquivos($tmp));
            $esperado = sha1("B.txt\0maiúscula vem antes\0a.css\0a\0b/x.json\0{}\0");
            self::assertSame($esperado, Biblioteca::versao($tmp));
            file_put_contents($tmp . '/.oculto', 'mudou');
            self::assertSame($esperado, Biblioteca::versao($tmp . '/'));
            file_put_contents($tmp . '/a.css', 'b');
            self::assertNotSame($esperado, Biblioteca::versao($tmp));
        } finally {
            foreach (['/b/x.json', '/a.css', '/B.txt', '/.oculto'] as $f) {
                @unlink($tmp . $f);
            }
            @rmdir($tmp . '/b');
            @rmdir($tmp);
        }
    }

    public function testErros(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Biblioteca não encontrada');
        Biblioteca::carregar('/nao/existe');
    }

    public function testJsonInvalido(): void
    {
        $tmp = sys_get_temp_dir() . '/rankly-bib-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/modelos', 0777, true);
        file_put_contents($tmp . '/modelos/x.json', '{ruim');
        try {
            Biblioteca::carregar($tmp);
            self::fail('deveria lançar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('JSON inválido em', $e->getMessage());
        } finally {
            unlink($tmp . '/modelos/x.json');
            rmdir($tmp . '/modelos');
            rmdir($tmp);
        }
    }
}
