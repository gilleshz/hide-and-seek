<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Get;
use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\FeatureRepository;
use App\Repository\GameRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\ChatService;
use App\Service\HiderGuard;
use App\Service\HidingZonePublisher;
use App\Service\HidingZoneService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassHttpClient;
use App\Service\PossibleAreaService;
use App\Service\QuestionMessageFormatter;
use App\Service\RoundClock;
use App\Service\RoundService;
use App\Service\StreetNetworkService;
use App\Service\ZoneCardMessageBuilder;
use App\State\HidingZoneProvider;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(HidingZoneProvider::class)]
final class HidingZoneProviderTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itReturnsTheZoneToTheHiderWhoseTokenIsPresented(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $zone = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);

        $resource = $this->provider($round, $hider, Side::Hider, $zones)
            ->provide(new Get(), ['roundUuid' => $round->getUuid()]);

        self::assertNotNull($resource);
        self::assertSame(52.52, $resource->lat);
        self::assertSame(13.405, $resource->lng);
        self::assertSame(500.0, $resource->radiusMeters);
    }

    #[Test]
    public function itRefusesASeekerTokenWithTheHiderOnlyErrorKey(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        try {
            $this->provider($round, $seeker, Side::Seeker, $this->createStub(HidingZoneRepository::class))
                ->provide(new Get(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('hiding_zone.not_hider', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $this->expectException(IdentityRequiredException::class);

        $this->providerWithRequest($round, new Request())
            ->provide(new Get(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAGarbledToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $request = new Request();
        $request->headers->set(IdentityResolver::HEADER, 'not-a-jwt');

        $this->expectException(IdentityRequiredException::class);

        $this->providerWithRequest($round, $request)
            ->provide(new Get(), ['roundUuid' => $round->getUuid()]);
    }

    private function provider(
        Round $round,
        Player $player,
        Side $side,
        HidingZoneRepository $zones,
    ): HidingZoneProvider {
        $request = new Request();
        $request->headers->set(
            IdentityResolver::HEADER,
            (new MercureJwtService(self::SECRET))
                ->issueSubscriberToken([], $player->getUuid()),
        );
        $stack = new RequestStack();
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $identity = new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack);

        return $this->providerWithIdentity($round, $identity, new RoundMembership($round, $player, $side), $zones);
    }

    private function providerWithRequest(Round $round, Request $request): HidingZoneProvider
    {
        $stack = new RequestStack();
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn(null);
        $identity = new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(null);

        return $this->providerWithIdentity(
            $round,
            $identity,
            null,
            $this->createStub(HidingZoneRepository::class),
        );
    }

    private function providerWithIdentity(
        Round $round,
        IdentityResolver $identity,
        ?RoundMembership $membership,
        HidingZoneRepository $zones,
    ): HidingZoneProvider {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $hiderGuard = new HiderGuard($identity, $memberships);
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );
        $players = $this->createStub(PlayerRepository::class);
        $streetNetworks = new StreetNetworkService(
            $rounds,
            $zones,
            $this->createStub(RoundStreetNetworkRepository::class),
            new OverpassHttpClient(new MockHttpClient(), 'http://mirror/api', false),
            $hiderGuard,
            $this->createStub(LoggerInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        $service = new HidingZoneService(
            $rounds,
            $players,
            $memberships,
            $zones,
            $hiderGuard,
            $chat,
            $this->createStub(ImageStorageInterface::class),
            $this->createStub(PossibleAreaService::class),
            new RoundService(
                $rounds,
                $this->createStub(GameRepository::class),
                $memberships,
                $players,
                $zones,
                $chat,
                $mercure,
                $hub,
                $this->createStub(LoggerInterface::class),
            ),
            new RoundClock(),
            new HidingZonePublisher($mercure, $hub, $streetNetworks),
            new ZoneCardMessageBuilder(new QuestionMessageFormatter($this->createStub(FeatureRepository::class))),
            $entityManager,
        );

        return new HidingZoneProvider($service);
    }
}
