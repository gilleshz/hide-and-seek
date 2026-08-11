<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameAreaRepository;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\GtfsSourceRepository;
use App\Repository\HeavyWorkLockRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\AdminLevelResolver;
use App\Service\ChatService;
use App\Service\GameCleanupService;
use App\Service\GameService;
use App\Service\GameTransitBuilder;
use App\Service\GtfsService;
use App\Service\HeavyWorkGuard;
use App\Service\IdentityResolver;
use App\Service\LeaveService;
use App\Service\MercureJwtService;
use App\Service\NominatimService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Service\TransitStationImporter;
use App\Service\TransitTilePipeline;
use App\Service\TransitTileService;
use App\State\GameDeleteProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[CoversClass(GameDeleteProcessor::class)]
final class GameDeleteProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theHostDeletesTheGameWithTheirToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $host = new Player($game, AccountFactory::create('Host', 'test-password'));
        $other = new Player($game, AccountFactory::create('Other', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([$host, $other]);

        $gtfsSources = $this->createMock(GtfsSourceRepository::class);
        $gtfsSources->expects(self::once())->method('findByGame')->willReturn([]);
        $gtfsSources->expects(self::once())->method('deleteByGame')->with($game);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($game);
        $entityManager->expects(self::once())->method('flush');

        $processor = $this->processor($game, $host, $games, $players, $gtfsSources, $entityManager);

        $processor->process(null, new Post(), ['uuid' => $game->getUuid()]);
    }

    #[Test]
    public function aNonHostTokenIsRefusedAndNothingIsPurged(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $host = new Player($game, AccountFactory::create('Host', 'test-password'));
        $other = new Player($game, AccountFactory::create('Other', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findByGameOrdered')->willReturn([$host, $other]);
        $gtfsSources = $this->createMock(GtfsSourceRepository::class);
        $gtfsSources->expects(self::never())->method('findByGame');

        try {
            $this->processor($game, $other, $games, $players, $gtfsSources)
                ->process(null, new Post(), ['uuid' => $game->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('game.only_host_can_delete', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($game, null, $this->createStub(GameRepository::class), $this->createStub(PlayerRepository::class), $this->createStub(GtfsSourceRepository::class), withHeader: false)
            ->process(null, new Post(), ['uuid' => $game->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($game, $player, $games, $this->createStub(PlayerRepository::class), $this->createStub(GtfsSourceRepository::class))
            ->process(null, new Post(), ['uuid' => $game->getUuid()]);
    }

    private function processor(
        Game $game,
        ?Player $player,
        GameRepository $games,
        PlayerRepository $players,
        GtfsSourceRepository $gtfsSources,
        ?EntityManagerInterface $entityManager = null,
        bool $withHeader = true,
    ): GameDeleteProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $identityPlayers = $this->createStub(PlayerRepository::class);
        $identityPlayers->method('findOneByUuidIncludingLeft')->willReturn($player);

        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $hub = $this->createStub(HubInterface::class);
        $leaveService = new LeaveService(
            $games,
            $players,
            new RosterNotifier(
                $hub,
                $mercure,
                new RosterService(
                    $this->createStub(PlayerRepository::class),
                    $this->createStub(RoundRepository::class),
                    $this->createStub(RoundMembershipRepository::class),
                ),
            ),
            new ChatService($this->createStub(ChatMessageRepository::class), $mercure, $hub, $this->createStub(ImageStorageInterface::class)),
            $this->createStub(RoundMembershipRepository::class),
            $entityManager,
        );
        $cleanup = new GameCleanupService(
            $this->createStub(GameGtfsLineRepository::class),
            $gtfsSources,
            new GtfsService(
                $this->createStub(GtfsSourceRepository::class),
                '/tmp/gtfs',
                new MockHttpClient(),
                $this->createStub(LoggerInterface::class),
            ),
            $entityManager,
            new Filesystem(),
            '/tmp/chat-images',
            '/tmp/tiles',
        );
        $gameService = new GameService(
            $games,
            $this->createStub(RoundRepository::class),
            $this->createStub(GameAreaRepository::class),
            $players,
            new NominatimService(new MockHttpClient(), $this->createStub(CacheInterface::class), 'test-agent', 'https://nominatim.test'),
            new AdminLevelResolver(new NominatimService(new MockHttpClient(), $this->createStub(CacheInterface::class), 'test-agent', 'https://nominatim.test')),
            $entityManager,
            2.0,
            new HeavyWorkGuard($this->createStub(HeavyWorkLockRepository::class)),
            new GameTransitBuilder(
                $this->createStub(GameTransitLineRepository::class),
                $this->createStub(GameGtfsLineRepository::class),
                $this->createStub(GtfsSourceRepository::class),
                new TransitTilePipeline('http://mirror/api', '/tmp/tiles'),
                new TransitStationImporter(
                    new TransitTileService('/tmp/tiles'),
                    $this->createStub(GameTransitStationRepository::class),
                    $entityManager,
                ),
                $entityManager,
                new Filesystem(),
                '/tmp/tiles',
            ),
            $cleanup,
            $leaveService,
        );

        return new GameDeleteProcessor($gameService, new IdentityResolver($mercure, $identityPlayers, $stack));
    }
}
