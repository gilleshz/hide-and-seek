<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\RealtimeSelection;
use App\Enum\RealtimeProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RealtimeSelectionTest extends TestCase
{
    #[Test]
    public function itInfersSiriLiteWhenAProfileIsSentWithoutAProvider(): void
    {
        $selection = RealtimeSelection::fromParts(null, 'idfm', null);

        self::assertSame(RealtimeProvider::SiriLite->value, $selection->provider);
        self::assertSame('idfm', $selection->profile);
        self::assertNull($selection->url);
    }

    #[Test]
    public function itTreatsAUrlWithoutAProviderAsGtfsRt(): void
    {
        $selection = RealtimeSelection::fromParts(null, null, 'https://8.8.8.8/rt');

        self::assertSame(RealtimeProvider::GtfsRtUrl->value, $selection->provider);
        self::assertSame('https://8.8.8.8/rt', $selection->url);
    }

    #[Test]
    public function itKeepsNoneWhenNothingIsSent(): void
    {
        self::assertSame(
            RealtimeProvider::None->value,
            RealtimeSelection::fromParts(null, null, null)->provider,
        );
    }
}
