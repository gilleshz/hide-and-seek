<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
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
use App\Service\UploadedImageReader;
use App\Service\ZoneCardMessageBuilder;
use App\State\ZoneCardProcessor;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ZoneCardProcessor::class)]
final class ZoneCardProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function aHiderPlaysACardAndThePhotoIsStoredOnce(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);
        $zone = new HidingZone($round, new Point(13.405, 52.52), 500.0);

        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->method('findOneByRound')->willReturn($zone);
        $zones->expects(self::once())->method('save')->with($zone);
        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::once())->method('store')->willReturn('photo-ref');

        $processor = $this->processor($round, $hider, $membership, $zones, $storage);
        $resource = $processor->process(null, new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(750.0, $resource->radiusMeters);
    }

    #[Test]
    public function aSeekerTokenIsRefusedAndThePhotoIsNeverStored(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $seeker, Side::Seeker);

        $zones = $this->createMock(HidingZoneRepository::class);
        $zones->expects(self::never())->method('save');
        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::never())->method('store');

        try {
            $this->processor($round, $seeker, $membership, $zones, $storage)
                ->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('hiding_zone.not_hider', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRefusesAnUnknownCard(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $request = $this->multipartRequest(['card' => 'not-a-card']);
        $request->headers->set(
            IdentityResolver::HEADER,
            (new MercureJwtService(self::SECRET))->issueSubscriberToken([], $hider->getUuid()),
        );

        try {
            $this->processorWithRequest($round, $hider, null, $request)
                ->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('zone_card.unknown', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $this->expectException(IdentityRequiredException::class);

        $this->processorWithRequest($round, null, null, $this->multipartRequest(['card' => 'tiny_home']))
            ->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAMissingRoundUuid(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        try {
            $this->processorWithRequest($round, $hider, null, $this->multipartRequest(['card' => 'tiny_home']))
                ->process(null, new Post(), []);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('round.not_found', $e->getErrorKey());
        }
    }

    /** @param array<string, string> $fields */
    private function multipartRequest(array $fields): Request
    {
        $request = new Request();
        $request->request->replace($fields);
        $photo = $this->createStub(UploadedFile::class);
        $photo->method('getError')->willReturn(\UPLOAD_ERR_OK);
        $photo->method('getSize')->willReturn(1024);
        $photo->method('getMimeType')->willReturn('image/jpeg');
        $request->files->set('image', $photo);

        return $request;
    }

    private function processor(
        Round $round,
        Player $player,
        ?RoundMembership $membership,
        HidingZoneRepository $zones,
        ImageStorageInterface $storage,
    ): ZoneCardProcessor {
        $request = $this->multipartRequest(['card' => 'prosperous_home']);
        $request->headers->set(
            IdentityResolver::HEADER,
            (new MercureJwtService(self::SECRET))->issueSubscriberToken([], $player->getUuid()),
        );

        return $this->processorWithRequest($round, $player, $membership, $request, $zones, $storage);
    }

    private function processorWithRequest(
        Round $round,
        ?Player $player,
        ?RoundMembership $membership,
        Request $request,
        ?HidingZoneRepository $zones = null,
        ?ImageStorageInterface $storage = null,
    ): ZoneCardProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $stack = new RequestStack();
        $stack->push($request);
        $identity = new IdentityResolver($mercure, $players, $stack);
        $hiderGuard = new HiderGuard($identity, $memberships);
        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );
        $streetNetworks = new StreetNetworkService(
            $rounds,
            $zones ?? $this->createStub(HidingZoneRepository::class),
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
            $zones ?? $this->createStub(HidingZoneRepository::class),
            $hiderGuard,
            $chat,
            $storage ?? $this->createStub(ImageStorageInterface::class),
            $this->createStub(PossibleAreaService::class),
            new RoundService(
                $rounds,
                $this->createStub(GameRepository::class),
                $memberships,
                $players,
                $zones ?? $this->createStub(HidingZoneRepository::class),
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

        return new ZoneCardProcessor($service, new UploadedImageReader($stack), $identity);
    }
}
