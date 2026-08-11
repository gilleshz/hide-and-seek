<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\TeamInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\IdentityRequiredException;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Service\TeamService;
use App\State\TeamProcessor;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TeamProcessor::class)]
final class TeamProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theTokenIsMintedForTheCallerNeverForABodyPlayerUuid(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $processor = $this->processor($round, $player, $memberships);
        $input = new TeamInput();
        $input->side = Side::Seeker;

        $resource = $processor->process($input, new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame($player->getUuid(), $resource->playerUuid);
        self::assertSame(
            $player->getUuid(),
            (new MercureJwtService(self::SECRET))->subscriberPlayerUuid($resource->mercureToken),
        );
    }

    #[Test]
    public function theReMintedTokenCarriesTheRoundScopedTopicsOfTheChosenSide(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $processor = $this->processor($round, $player, $memberships);
        $input = new TeamInput();
        $input->side = Side::Hider;

        $resource = $processor->process($input, new Post(), ['roundUuid' => $round->getUuid()]);

        $mercure = new MercureJwtService(self::SECRET);
        self::assertContains($mercure->hiderLocationsTopic($game, $round), $resource->topics);
        self::assertContains($mercure->playerEndgameTopic($player->getUuid()), $resource->topics);
        self::assertNotContains($mercure->seekerCandidatesTopic($game, $round), $resource->topics);
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $this->expectException(IdentityRequiredException::class);

        $this->processor($round, $player, $this->createStub(RoundMembershipRepository::class), withHeader: false)
            ->process(new TeamInput(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($round, $player, $this->createStub(RoundMembershipRepository::class), rounds: $rounds)
            ->process(new TeamInput(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    private function processor(
        Round $round,
        Player $player,
        RoundMembershipRepository $memberships,
        bool $withHeader = true,
        ?RoundRepository $rounds = null,
    ): TeamProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $players->method('findByGameOrdered')->willReturn([]);

        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);

        $defaultRounds = $this->createStub(RoundRepository::class);
        $defaultRounds->method('findOneByUuid')->willReturn($round);
        $defaultRounds->method('findActiveByGame')->willReturn($round);
        $resolvedRounds = $rounds ?? $defaultRounds;

        $roster = new RosterNotifier($hub, $mercure, new RosterService($players, $resolvedRounds, $memberships));

        return new TeamProcessor(
            $this->createStub(ValidatorInterface::class),
            new TeamService($resolvedRounds, $players, $memberships, $roster),
            $mercure,
            new IdentityResolver($mercure, $players, $stack),
        );
    }
}
