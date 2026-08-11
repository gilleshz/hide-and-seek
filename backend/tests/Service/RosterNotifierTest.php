<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RosterNotifier::class)]
#[CoversClass(RosterService::class)]
final class RosterNotifierTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itPublishesTheWholeRosterToTheRosterTopic(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $alice = self::playerWithId($game, 'Alice', 1);
        $bob = self::playerWithId($game, 'Bob', 2);
        $hub = new FakeMercureHub();
        $roster = $this->rosterService($round, [$alice, $bob], [new RoundMembership($round, $alice, Side::Hider)]);

        new RosterNotifier($hub, new MercureJwtService(self::SECRET), $roster)->publishChanged($game);

        $updates = $hub->published();
        self::assertCount(1, $updates);
        self::assertSame(["game/{$game->getUuid()}/roster"], $updates[0]->getTopics());
        $payload = json_decode($updates[0]->getData(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('roster', $payload['type']);
        self::assertSame([
            ['uuid' => $alice->getUuid(), 'displayName' => 'Alice', 'side' => 'hider'],
            ['uuid' => $bob->getUuid(), 'displayName' => 'Bob', 'side' => null],
        ], $payload['players']);
    }

    #[Test]
    public function theRosterEventCarriesNoCoordinates(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hub = new FakeMercureHub();
        $roster = $this->rosterService($round, [self::playerWithId($game, 'Alice', 1)], []);

        new RosterNotifier($hub, new MercureJwtService(self::SECRET), $roster)->publishChanged($game);

        $data = $hub->published()[0]->getData();
        self::assertStringNotContainsString('lat', $data);
        self::assertStringNotContainsString('lng', $data);
    }

    /**
     * @param list<Player>          $players
     * @param list<RoundMembership> $memberships
     */
    private function rosterService(Round $round, array $players, array $memberships): RosterService
    {
        $playerRepository = $this->createStub(PlayerRepository::class);
        $playerRepository->method('findByGameOrdered')->willReturn($players);
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn($round);
        $membershipRepository = $this->createStub(RoundMembershipRepository::class);
        $membershipRepository->method('findByRound')->willReturn($memberships);

        return new RosterService($playerRepository, $rounds, $membershipRepository);
    }

    /** The side lookup is keyed on the database id, which only a persisted player would carry. */
    private static function playerWithId(Game $game, string $displayName, int $id): Player
    {
        $player = new Player($game, AccountFactory::create($displayName, 'test-password'));
        new \ReflectionProperty(Player::class, 'id')->setValue($player, $id);

        return $player;
    }
}
