<?php

declare(strict_types=1);

namespace Rankly\Testes\Preparo;

use PHPUnit\Framework\TestCase;
use Rankly\Preparo\Tons;

final class TonsTest extends TestCase
{
    public function testAlternanciaDoParagrafo52(): void
    {
        self::assertSame(
            ['branco', 'tom', 'branco', 'tom', 'escuro', 'branco', 'cor', 'branco', 'tom', 'branco', 'escuro'],
            Tons::calcularFundos(['branco', 'claro', 'claro', 'claro', 'escuro', 'claro', 'cor', 'claro', 'tom-claro', 'claro', 'escuro']),
        );
        self::assertSame(['tom', 'branco', 'tom'], Tons::calcularFundos(['claro', 'xyz', null]));
        self::assertSame([], Tons::calcularFundos(null));
    }
}
