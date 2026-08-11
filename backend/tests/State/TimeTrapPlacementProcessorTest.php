<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Entity\TimeTrap;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\TimeTrapStatus;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\TimeTrapRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\RoundClock;
use App\Service\TimeTrapPublisher;
use App\Service\TimeTrapService;
use App\Service\UploadedImageReader;
use App\State\TimeTrapPlacementProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TimeTrapPlacementProcessor::class)]
final class TimeTrapPlacementProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function aHiderPlacesATrapAndThePhotoIsStoredOnce(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);
        $station = new GameTransitStation(
            $game,
            'station-1',
            'Alexanderplatz',
            new \LongitudeOne\Spatial\PHP\Types\Geography\Point(13.405, 52.52),
            [],
        );

        $traps = $this->createMock(TimeTrapRepository::class);
        $traps->method('countByRound')->willReturn(0);
        $traps->expects(self::once())->method('save')->with(self::isInstanceOf(TimeTrap::class));
        $stations = $this->createStub(GameTransitStationRepository::class);
        $stations->method('findNearestWithin')->willReturn($station);
        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::once())->method('store')->willReturn('photo-ref');

        $processor = $this->processor($round, $hider, $membership, $traps, $stations, $storage);
        $resource = $processor->process(null, new Post(), ['roundUuid' => $round->getUuid()]);

        self::assertSame(TimeTrapStatus::Armed->value, $resource->status);
        self::assertSame('Alexanderplatz', $resource->stationName);
    }

    #[Test]
    public function aSeekerTokenIsRefusedAndThePhotoIsNeverStored(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $seeker, Side::Seeker);

        $traps = $this->createMock(TimeTrapRepository::class);
        $traps->expects(self::never())->method('save');
        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::never())->method('store');

        try {
            $this->processor($round, $seeker, $membership, $traps, $this->createStub(GameTransitStationRepository::class), $storage)
                ->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('time_trap.not_hider', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRefusesAPlacementOutsideTheSeekingPhaseBeforeStoringThePhoto(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Hiding)->setHidingPeriodEndsAt(new \DateTimeImmutable('+10 minutes'));
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);

        $storage = $this->createMock(ImageStorageInterface::class);
        $storage->expects(self::never())->method('store');

        try {
            $this->processor($round, $hider, $membership, $this->createStub(TimeTrapRepository::class), $this->createStub(GameTransitStationRepository::class), $storage)
                ->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('time_trap.not_seeking', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $this->expectException(IdentityRequiredException::class);

        $this->processorWithRequest(
            $round,
            null,
            null,
            $this->multipartRequest(['lat' => '52.52', 'lng' => '13.405']),
        )->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
    }

    #[Test]
    public function itRejectsAMissingLatField(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        try {
            $this->processorWithRequest(
                $round,
                $hider,
                null,
                $this->multipartRequest(['lng' => '13.405']),
            )->process(null, new Post(), ['roundUuid' => $round->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('chat_image.missing_field', $e->getErrorKey());
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
        TimeTrapRepository $traps,
        GameTransitStationRepository $stations,
        ImageStorageInterface $storage,
    ): TimeTrapPlacementProcessor {
        $request = $this->multipartRequest(['lat' => '52.52', 'lng' => '13.405']);
        $request->headers->set(
            IdentityResolver::HEADER,
            (new MercureJwtService(self::SECRET))->issueSubscriberToken([], $player->getUuid()),
        );

        return $this->processorWithRequest($round, $player, $membership, $request, $traps, $stations, $storage);
    }

    private function processorWithRequest(
        Round $round,
        ?Player $player,
        ?RoundMembership $membership,
        Request $request,
        ?TimeTrapRepository $traps = null,
        ?GameTransitStationRepository $stations = null,
        ?ImageStorageInterface $storage = null,
    ): TimeTrapPlacementProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn($round);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $players->method('findOneByUuid')->willReturn($player);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);
        $locations = $this->createStub(PlayerLocationRepository::class);
        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        $service = new TimeTrapService(
            $rounds,
            $players,
            $memberships,
            $traps ?? $this->createStub(TimeTrapRepository::class),
            $stations ?? $this->createStub(GameTransitStationRepository::class),
            $locations,
            $chat,
            $storage ?? $this->createStub(ImageStorageInterface::class),
            new RoundClock(),
            new TimeTrapPublisher($mercure, $hub),
            $entityManager,
        );

        $stack = new RequestStack();
        $stack->push($request);

        return new TimeTrapPlacementProcessor(
            $service,
            new UploadedImageReader($stack),
            new IdentityResolver($mercure, $players, $stack),
        );
    }
}
