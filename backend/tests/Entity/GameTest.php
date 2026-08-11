<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Enum\Edition;
use App\Enum\GameSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Game::class)]
final class GameTest extends TestCase
{
    #[Test]
    public function itExposesConstructorValuesAndGeneratesIdentity(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);

        self::assertNull($game->getId());
        self::assertSame('Berlin', $game->getName());
        self::assertSame(GameSize::Large, $game->getSize());
        self::assertSame(Edition::Metric, $game->getEdition());
        self::assertSame(36, \strlen($game->getUuid()));
        self::assertSame($game->getCreatedAt(), $game->getUpdatedAt());
    }
}
