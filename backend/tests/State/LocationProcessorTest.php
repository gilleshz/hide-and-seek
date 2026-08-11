<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\LocationInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\IdentityRequiredException;
use App\Exception\RateLimitExceededException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;
use App\Service\ChatService;
use App\Service\EndgameService;
use App\Service\IdentityResolver;
use App\Service\LocationService;
use App\Service\MercureJwtService;
use App\Service\RateLimits;
use App\Service\RoundClock;
use App\Service\TimeTrapPublisher;
use App\Service\TimeTrapService;
use App\State\LocationProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(LocationProcessor::class)]
final class LocationProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itRecordsThePingForTheTokenIdentity(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);

        $locations = $this->createMock(PlayerLocationRepository::class);
        $locations->expects(self::once())->method('save');

        $processor = $this->processor(
            $round,
            $hider,
            $membership,
            $locations,
            $this->endgameServiceNeverTriggering(),
            $this->limiter(100),
        );

        $resource = $processor->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame($hider->getUuid(), $resource->playerUuid);
        self::assertFalse($resource->endgame);
    }

    #[Test]
    public function itPublishesTheSeekerPingOnTheSeekerTopicOnly(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $seeker, Side::Seeker);

        $hub = new FakeMercureHub();
        $processor = $this->processor(
            $round,
            $seeker,
            $membership,
            $this->createStub(PlayerLocationRepository::class),
            $this->endgameServiceNeverTriggering(),
            $this->limiter(100),
            $hub,
        );

        $processor->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);

        $topics = array_merge(...array_map(
            static fn (Update $update): array => $update->getTopics(),
            $hub->published(),
        ));
        self::assertContains("game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-locations", $topics);
        self::assertNotContains("game/{$game->getUuid()}/round/{$round->getUuid()}/hider-locations", $topics);
    }

    #[Test]
    public function thePingAckCarriesTheEndgameFlagOnlyWhenThisIngestStartedIt(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $seeker, Side::Seeker);

        $endgame = $this->createMock(EndgameService::class);
        $endgame->method('check')->willReturn($seeker);
        $endgame->expects(self::once())->method('start')->with($round);

        $processor = $this->processor(
            $round,
            $seeker,
            $membership,
            $this->createStub(PlayerLocationRepository::class),
            $endgame,
            $this->limiter(100),
        );

        $resource = $processor->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertTrue($resource->endgame);
    }

    #[Test]
    public function itSkipsTheEndgameCheckDuringAMoveWindow(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('+10 minutes'))
            ->setInMovePeriod(true);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $seeker, Side::Seeker);

        $endgame = $this->createMock(EndgameService::class);
        $endgame->expects(self::never())->method('check');
        $endgame->expects(self::never())->method('start');

        $processor = $this->processor(
            $round,
            $seeker,
            $membership,
            $this->createStub(PlayerLocationRepository::class),
            $endgame,
            $this->limiter(100),
        );

        $resource = $processor->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertFalse($resource->endgame);
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $this->expectException(IdentityRequiredException::class);

        $this->processor(
            $round,
            $seeker,
            null,
            $this->createStub(PlayerLocationRepository::class),
            $this->endgameServiceNeverTriggering(),
            $this->limiter(100),
            new FakeMercureHub(),
            new Request(),
            null,
            false,
        )->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor(
            $round,
            $hider,
            null,
            $this->createStub(PlayerLocationRepository::class),
            $this->endgameServiceNeverTriggering(),
            $this->limiter(100),
            new FakeMercureHub(),
            null,
            $rounds,
        )->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function anExhaustedLocationLimiterRejectsThePing(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);

        $limiter = $this->limiter(1);
        $limiter->locationIngest($hider->getUuid());

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Too many requests.');

        $this->processor(
            $round,
            $hider,
            $membership,
            $this->createStub(PlayerLocationRepository::class),
            $this->endgameServiceNeverTriggering(),
            $limiter,
        )->process($this->input(), new Post(), ['roundUuid' => $round->getUuid()]);
    }

    private function input(): LocationInput
    {
        $input = new LocationInput();
        $input->lat = 52.52;
        $input->lng = 13.405;

        return $input;
    }

    private function endgameServiceNeverTriggering(): EndgameService
    {
        $endgame = $this->createStub(EndgameService::class);
        $endgame->method('check')->willReturn(null);

        return $endgame;
    }

    private function limiter(int $limit): RateLimits
    {
        return new RateLimits(
            new RateLimiterFactory(['id' => 'location', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'chat', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
        );
    }

    private function processor(
        Round $round,
        Player $player,
        ?RoundMembership $membership,
        PlayerLocationRepository $locations,
        EndgameService $endgame,
        RateLimits $rateLimits,
        ?FakeMercureHub $hub = null,
        ?Request $request = null,
        ?RoundRepository $rounds = null,
        bool $withHeader = true,
    ): LocationProcessor {
        $resolvedHub = $hub ?? new FakeMercureHub();
        $mercure = new MercureJwtService(self::SECRET);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $defaultRounds = $this->createStub(RoundRepository::class);
        $defaultRounds->method('findOneByUuid')->willReturn($round);
        $resolvedRounds = $rounds ?? $defaultRounds;

        $stack = new RequestStack();
        $request ??= new Request();
        if ($withHeader) {
            $request->headers->set(
                IdentityResolver::HEADER,
                $mercure->issueSubscriberToken([], $player->getUuid()),
            );
        }
        $stack->push($request);

        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $resolvedHub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        $locationService = new LocationService(
            $locations,
            $memberships,
            $mercure,
            $resolvedHub,
            $endgame,
            new RoundClock(),
            new TimeTrapService(
                $this->createStub(RoundRepository::class),
                $this->createStub(PlayerRepository::class),
                $memberships,
                $this->createStub(TimeTrapRepository::class),
                $this->createStub(GameTransitStationRepository::class),
                $locations,
                $chat,
                $this->createStub(ImageStorageInterface::class),
                new RoundClock(),
                new TimeTrapPublisher($mercure, $resolvedHub),
                $entityManager,
            ),
        );

        return new LocationProcessor(
            $this->createStub(ValidatorInterface::class),
            $resolvedRounds,
            $locationService,
            new IdentityResolver($mercure, $players, $stack),
            $rateLimits,
        );
    }
}
