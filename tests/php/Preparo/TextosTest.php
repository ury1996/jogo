<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Biblioteca;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Textos;

final class TextosTest extends TestCase
{
    private static ?array $lib = null;

    private static function lib(): array
    {
        return self::$lib ??= Biblioteca::carregar(dirname(__DIR__, 2) . '/fixtures/biblioteca-mini');
    }

    private static function doc(array $dados = ['nome' => 'Odonto Mais', 'cidade' => 'Campinas']): array
    {
        return Documento::criarDocumento(['nicho' => 'clinicas', 'modelo' => 'moderno', 'dados' => $dados], self::lib());
    }

    public function testCadeiaDoTextoEfetivo(): void
    {
        $lib = self::lib();
        $doc = self::doc();
        self::assertSame('Ortodontia', Textos::textoEfetivo($doc, $lib, 'serv.1.t'));
        self::assertSame('Enviar mensagem', Textos::textoEfetivo($doc, $lib, 'form.botao'));
        self::assertSame('Novo serviço', Textos::textoEfetivo($doc, $lib, 'serv.nk3f.t'));
        self::assertSame('', Textos::textoEfetivo($doc, $lib, 'hero.nao'));
        $doc['textos'] = ['serv.1.t' => 'Aparelhos'];
        self::assertSame('Aparelhos', Textos::textoEfetivo($doc, $lib, 'serv.1.t'));
        self::assertFalse(Textos::ehPadrao($doc, 'serv.1.t'));
        self::assertTrue(Textos::ehPadrao($doc, 'serv.2.t'));
    }

    public function testVariaveisSempreTrocadasComExemplo(): void
    {
        $lib = self::lib();
        $doc = self::doc();
        self::assertSame('Dentista em Campinas', Textos::textoEfetivo($doc, $lib, 'hero.eyebrow'));
        $doc['textos'] = ['hero.titulo' => 'A {nome} de {cidade} ({segmento}) {outra}'];
        self::assertSame('A Odonto Mais de Campinas (Dentista) {outra}', Textos::textoEfetivo($doc, $lib, 'hero.titulo'));
        self::assertSame(['nome' => 'Clínica Sorriso Vivo', 'cidade' => 'Jundiaí', 'segmento' => 'Dentista'], Textos::contextoVariaveis(self::doc(['nome' => '  ']), $lib));
        self::assertSame('{cidade}X', Textos::substituirVariaveis('{nome}{cidade}', ['nome' => '{cidade}', 'cidade' => 'X']));
        self::assertSame('$1 \\0', Textos::substituirVariaveis('{nome}', ['nome' => '$1 \\0']));
    }

    public function testRemoverItemNaoRessuscitaOutro(): void
    {
        $lib = self::lib();
        $doc = self::doc();
        self::assertSame(['1', '2', '3', '4'], Textos::itensLista($doc, $lib, 'serv'));
        $removido = Textos::removerItem($doc, $lib, 'serv', '2');
        self::assertSame(['1', '3', '4'], Textos::itensLista($removido, $lib, 'serv'));
        self::assertSame('Harmonização facial', Textos::textoEfetivo($removido, $lib, 'serv.3.t'));
        ['doc' => $novo, 'id' => $id] = Textos::adicionarItem($removido, $lib, 'serv');
        self::assertMatchesRegularExpression('/^n[0-9a-z]{4}$/', $id);
        self::assertSame('Novo serviço', Textos::textoEfetivo($novo, $lib, "serv.{$id}.t"));
        self::assertSame(['1', '3', '4'], Textos::itensLista(Textos::removerItem($removido, $lib, 'serv', '1'), $lib, 'serv'), 'mínimo 3');
        self::assertSame(['4', '1', '3'], Textos::itensLista(Textos::moverItem($removido, $lib, 'serv', '4', -2), $lib, 'serv'));
    }

    public function testRetokenizar(): void
    {
        $dados = ['nome' => 'Clínica Sorriso', 'cidade' => 'Jundiaí'];
        self::assertSame('A {nome} fica em {cidade}.', Textos::retokenizar('A Clínica Sorriso fica em Jundiaí.', $dados));
        self::assertSame('clínica sorriso', Textos::retokenizar('clínica sorriso', $dados));
        self::assertSame('{nome}', Textos::retokenizar('Clínica Sorriso Jundiaí', ['nome' => 'Clínica Sorriso Jundiaí', 'cidade' => 'Jundiaí']));
        self::assertSame('Ab em Ab', Textos::retokenizar('Ab em Ab', ['nome' => 'Ab']));
    }

    public function testAplicarEdicaoTexto(): void
    {
        $lib = self::lib();
        $r1 = Textos::aplicarEdicaoTexto(self::doc(), $lib, 'hero.titulo', 'Sorrisos na Odonto Mais em Campinas  ');
        self::assertSame('Sorrisos na {nome} em {cidade}', $r1['doc']['textos']['hero.titulo']);
        $r2 = Textos::aplicarEdicaoTexto($r1['doc'], $lib, 'hero.titulo', " \n ");
        self::assertTrue($r2['restaurado']);
        self::assertArrayNotHasKey('hero.titulo', $r2['doc']['textos']);
        $r3 = Textos::aplicarEdicaoTexto(self::doc(), $lib, 'hero.eyebrow', 'Dentista em Campinas');
        self::assertArrayNotHasKey('hero.eyebrow', $r3['doc']['textos']);
    }

    public function testTextosPadraoEfetivos(): void
    {
        $doc = self::doc();
        $doc['textos'] = ['serv.1.t' => 'Editado'];
        $r = Textos::textosPadraoEfetivos($doc, self::lib());
        self::assertSame('Implantes', $r['serv.2.t']);
        self::assertArrayNotHasKey('serv.1.t', $r);
        self::assertSame('Onde vocês ficam em Campinas?', $r['faq.4.q']);
    }
}
