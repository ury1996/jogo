<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Dados;

final class DadosTest extends TestCase
{
    public function testTelefone(): void
    {
        self::assertSame('(11) 98765-4321', Dados::formatarTelefone('+55 11 98765-4321'));
        self::assertSame('(11) 3456-7890', Dados::formatarTelefone('011 3456-7890'));
        self::assertSame('0800 123 4567', Dados::formatarTelefone('08001234567'));
        self::assertSame('(11) 9876', Dados::formatarTelefone(' (11) 9876 '));
        self::assertSame('tel:+551134567890', Dados::linkTelefone('(11) 3456-7890'));
        self::assertSame('tel:08001234567', Dados::linkTelefone('0800 123 4567'));
    }

    public function testValidarWhatsapp(): void
    {
        foreach (['(11) 98765-4321', '+55 (11) 98765-4321', '(11) 3456-7890', '(55) 99999-9999'] as $ok) {
            self::assertTrue(Dados::validarWhatsapp($ok), $ok);
        }
        foreach (['(20) 98765-4321', '(11) 8765-4321', '98765-4321', '', '0800 123 4567'] as $ruim) {
            self::assertFalse(Dados::validarWhatsapp($ruim), $ruim);
        }
    }

    public function testLinkWhatsapp(): void
    {
        self::assertSame(
            "https://wa.me/5511987654321?text=Ol%C3%A1!%20Quero%20(agendar)%20'j%C3%A1'%20%26%20ver",
            Dados::linkWhatsapp('(11) 98765-4321', "Olá! Quero (agendar) 'já' & ver"),
        );
        self::assertSame('https://wa.me/5511987654321', Dados::linkWhatsapp('+55 11 98765-4321', ''));
        self::assertSame('', Dados::linkWhatsapp('', 'oi'));
    }

    public function testEndereco(): void
    {
        $dados = ['cidade' => 'Jundiaí', 'uf' => 'sp', 'endereco' => ['cep' => '13201000', 'logradouro' => 'Rua X', 'numero' => '123', 'complemento' => 'Sala 4', 'bairro' => 'Centro']];
        self::assertSame('Rua X, 123 - Sala 4 - Centro, Jundiaí - SP, CEP 13201-000', Dados::formatarEndereco($dados));
        self::assertSame(['Rua X, 123 - Sala 4', 'Centro, Jundiaí - SP, CEP 13201-000'], Dados::linhasEndereco($dados));
        self::assertSame('', Dados::formatarEndereco(['cidade' => 'Campinas', 'endereco' => ['bairro' => 'Cambuí']]));
    }

    public function testHorarios(): void
    {
        $semana = ['seg' => ['08:00', '18:00'], 'ter' => ['08:00', '18:00'], 'qua' => ['08:00', '18:00'], 'qui' => ['08:00', '18:00'], 'sex' => ['08:00', '18:00'], 'sab' => ['08:30', '12:00'], 'dom' => null];
        $h = Dados::formatarHorarios($semana);
        self::assertSame([['dias' => 'Seg a Sex', 'horas' => '8h às 18h'], ['dias' => 'Sáb', 'horas' => '8h30 às 12h'], ['dias' => 'Dom', 'horas' => 'Fechado']], $h);
        self::assertSame('Seg a Sex: 8h às 18h · Sáb: 8h30 às 12h · Dom: Fechado', Dados::horariosTexto($h));
        self::assertSame([['dias' => 'Sáb e Dom', 'horas' => '8h às 12h']], Dados::formatarHorarios(['sab' => ['08:00', '12:00'], 'dom' => ['08:00', '12:00']]));
        self::assertSame([['dias' => 'Seg', 'horas' => '8h às 12h e 14h às 18h']], Dados::formatarHorarios(['seg' => ['08:00', '12:00', '14:00', '18:00']]));
        self::assertSame([], Dados::formatarHorarios(['sab' => null, 'dom' => null]));
        self::assertSame('9h05', Dados::formatarHora('09:05'));
    }

    public function testRegistro(): void
    {
        $dados = ['uf' => 'SP', 'registro' => ['numero' => '12345', 'uf' => '', 'responsavel' => 'Dra. X']];
        self::assertSame('Responsável técnico: Dra. X · CRO-SP 12345', Dados::formatarRegistro($dados, ['rotuloRegistro' => 'CRO']));
        self::assertSame('OAB/SP 123.456', Dados::formatarRegistro(['uf' => 'sp', 'registro' => ['numero' => '123.456']], ['rotuloRegistro' => 'OAB']));
        self::assertSame('Registro 1', Dados::formatarRegistro(['registro' => ['numero' => '1']], null));
        self::assertSame('', Dados::formatarRegistro(['registro' => ['numero' => '']], ['rotuloRegistro' => 'CRO']));
    }

    public function testInicialEmailRedes(): void
    {
        self::assertSame('C', Dados::inicial('clínica'));
        self::assertSame('É', Dados::inicial('¿Ética?'));
        self::assertTrue(Dados::validarEmail('contato@clinica.com.br'));
        self::assertFalse(Dados::validarEmail('a b@c.com'));
        self::assertSame('https://www.instagram.com/clinica.teste', Dados::urlRede('instagram', '@clinica.teste'));
        self::assertSame('https://facebook.com/clinica', Dados::urlRede('facebook', 'facebook.com/clinica'));
        self::assertSame('', Dados::urlRede('instagram', 'javascript:alert(1)'));
        self::assertSame('', Dados::urlRede('instagram', 'https://x.com/"><script>'));
    }
}
