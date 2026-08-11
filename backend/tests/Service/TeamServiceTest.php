<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Service\TeamService;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(TeamService::class)]
final class TeamServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private function silentRoster(): RosterNotifier
    {
        return new RosterNotifier(
            $this->createStub(HubInterface::class),
            new MercureJwtService(self::SECRET),
            $this->emptyRosterService(),
        );
    }

    private function emptyRosterService(): RosterService
    {
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([]);

        return new RosterService(
            $players,
            $this->createStub(RoundRepository::class),
            $this->createStub(RoundMembershipRepository::class),
        );
    }

    #[Test]
    public function itCreatesAMembershipWhenNoneExists(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);
        $memberships->expects(self::once())->method('save');

        $membership = new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);

        self::assertSame(Side::Hider, $membership->getSide());
        self::assertSame($round, $membership->getRound());
        self::assertSame($player, $membership->getPlayer());
    }

    #[Test]
    public function itSwitchesTheSideOfAnExistingMembership(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $existing = new RoundMembership($round, $player, Side::Seeker);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($existing);
        $memberships->expects(self::once())->method('save')->with($existing);

        $membership = new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);

        self::assertSame($existing, $membership);
        self::assertSame(Side::Hider, $membership->getSide());
    }

    #[Test]
    public function itAllowsAFirstSeekerChoiceAfterTheRoundHasLeftTheLobby(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);
        $memberships->expects(self::once())->method('save');

        $membership = new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Seeker);

        self::assertSame(Side::Seeker, $membership->getSide());
    }

    #[Test]
    public function itRejectsAFirstHiderChoiceAfterTheRoundHasLeftTheLobby(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);
        $memberships->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Only the seeker side can be joined once the round has left the lobby.');

        new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);
    }

    #[Test]
    public function itAllowsReconfirmingTheSameSideAfterTheRoundHasLeftTheLobby(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $existing = new RoundMembership($round, $player, Side::Hider);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($existing);
        $memberships->expects(self::never())->method('save');

        $membership = new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);

        self::assertSame($existing, $membership);
        self::assertSame(Side::Hider, $membership->getSide());
    }

    #[Test]
    public function itRejectsChangingAnExistingSideAfterTheRoundHasLeftTheLobby(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $existing = new RoundMembership($round, $player, Side::Seeker);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($existing);
        $memberships->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);
    }

    #[Test]
    public function itRejectsAFirstChoiceForAnEndedRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Ended);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);
        $memberships->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Cannot choose a side for an ended round.');

        new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Hider);
    }

    #[Test]
    public function itRejectsAPlayerFromAnotherGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $player = new Player($otherGame, AccountFactory::create('Bob', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $player->getUuid(), Side::Seeker);
    }

    #[Test]
    public function itRejectsAPlayerWhoHasLeft(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $left = new Player($game, AccountFactory::create('Gone', 'test-password'))->markLeft(new \DateTimeImmutable());

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($left);
        $memberships = $this->createMock(RoundMembershipRepository::class);
        $memberships->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('A player who left cannot choose a side.');

        new TeamService($rounds, $players, $memberships, $this->silentRoster())
            ->choose($round->getUuid(), $left->getUuid(), Side::Hider);
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        new TeamService(
            $rounds,
            $this->createStub(PlayerRepository::class),
            $this->createStub(RoundMembershipRepository::class),
            $this->silentRoster(),
        )->choose('00000000-0000-0000-0000-000000000000', 'irrelevant', Side::Seeker);
    }
}
