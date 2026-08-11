<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Get;
use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Entity\RoundStreetNetwork;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Enum\StreetNetworkStatus;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\HiderGuard;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassHttpClient;
use App\Service\StreetNetworkService;
use App\State\StreetNetworkProvider;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(StreetNetworkProvider::class)]
final class StreetNetworkProviderTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itReturnsTheNetworkToTheHiderWhoseTokenIsPresented(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $zone = new HidingZone($round, new Point(13.405, 52.52), 500.0);
        $network = new RoundStreetNetwork($round, $zone->getStationPoint(), 500.0);
        $network->setStatus(StreetNetworkStatus::Ready)
            ->setWayCount(2)
            ->setPayload([['class' => 'primary', 'coordinates' => [[0.0, 0.0]], 'junctionIndices' => []]]);

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);
        $networks = $this->createStub(RoundStreetNetworkRepository::class);
        $networks->method('findOneByRound')->willReturn($network);

        $resource = $this->provider($round, $hider, Side::Hider, $zones, $networks)
            ->provide(new Get(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(StreetNetworkStatus::Ready->value, $resource->status);
        self::assertSame(2, $resource->wayCount);
        self::assertCount(1, $resource->ways);
    }

    #[Test]
    public function itRefusesASeekerTokenWithTheHiderOnlyErrorKey(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        try {
            $this->provider(
                $round,
                $seeker,
                Side::Seeker,
                $this->createStub(HidingZoneRepository::class),
                $this->createStub(RoundStreetNetworkRepository::class),
            )->provide(new Get(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('street_network.not_hider', $e->getErrorKey());
        }
    }

    #[Test]
    public function itReportsPendingWhenNoZoneHasBeenPlacedYet(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $zones = $this->createStub(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn(null);

        $resource = $this->provider(
            $round,
            $hider,
            Side::Hider,
            $zones,
            $this->createStub(RoundStreetNetworkRepository::class),
        )->provide(new Get(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(StreetNetworkStatus::Pending->value, $resource->status);
        self::assertSame([], $resource->ways);
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $this->expectException(IdentityRequiredException::class);

        $this->providerWithRequest(
            $round,
            new Request(),
            $this->createStub(HidingZoneRepository::class),
            $this->createStub(RoundStreetNetworkRepository::class),
        )->provide(new Get(), ['roundUuid' => $round->getUuid()]);
    }

    private function provider(
        Round $round,
        Player $player,
        Side $side,
        HidingZoneRepository $zones,
        RoundStreetNetworkRepository $networks,
    ): StreetNetworkProvider {
        $request = new Request();
        $request->headers->set(
            IdentityResolver::HEADER,
            (new MercureJwtService(self::SECRET))->issueSubscriberToken([], $player->getUuid()),
        );
        $stack = new RequestStack();
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);

        return $this->service(
            $round,
            new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack),
            new RoundMembership($round, $player, $side),
            $zones,
            $networks,
        );
    }

    private function providerWithRequest(
        Round $round,
        Request $request,
        HidingZoneRepository $zones,
        RoundStreetNetworkRepository $networks,
    ): StreetNetworkProvider {
        $stack = new RequestStack();
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn(null);

        return $this->service(
            $round,
            new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack),
            null,
            $zones,
            $networks,
        );
    }

    private function service(
        Round $round,
        IdentityResolver $identity,
        ?RoundMembership $membership,
        HidingZoneRepository $zones,
        RoundStreetNetworkRepository $networks,
    ): StreetNetworkProvider {
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        return new StreetNetworkProvider(new StreetNetworkService(
            $rounds,
            $zones,
            $networks,
            new OverpassHttpClient(new MockHttpClient(), 'http://mirror/api', false),
            new HiderGuard($identity, $memberships),
            $this->createStub(LoggerInterface::class),
        ));
    }
}
