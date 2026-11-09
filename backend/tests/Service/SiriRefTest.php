<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SiriRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiriRefTest extends TestCase
{
    #[Test]
    public function itExtractsTheCodeFromWrappedAndBareRefs(): void
    {
        self::assertSame('12345', SiriRef::code('STIF:StopPoint:Q:12345:'));
        self::assertSame('C1', SiriRef::code('SYTRAL:Line::C1:'));
        self::assertSame('C1', SiriRef::code('ActIV:Line::C1:SYTRAL'));
        self::assertSame('21000', SiriRef::code('ActIV:StopPoint:Q:21000:SYTRAL'));
        self::assertSame('C1', SiriRef::code('C1'));
        self::assertSame('21000', SiriRef::code('21000'));
        self::assertSame('C01624', SiriRef::code('IDFM:C01624'));
        self::assertSame('T1', SiriRef::code('TCL:Line::T1:'));
        self::assertSame('', SiriRef::code('::'));
    }

    #[Test]
    public function itNormalizesCaseAndAccents(): void
    {
        self::assertSame('c1', SiriRef::normalized('ActIV:Line::C1:SYTRAL'));
        self::assertSame('tetoile', SiriRef::normalizedFolded('TÉtoile'));
    }
}
