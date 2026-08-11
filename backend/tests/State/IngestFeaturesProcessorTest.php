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
use App\Repository\GameRepository;
use App\Repository\HeavyWorkLockRepository;
use App\Repository\PlayerRepository;
use App\Service\HeavyWorkGuard;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassService;
use App\State\IngestFeaturesProcessor;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(IngestFeaturesProcessor::class)]
final class IngestFeaturesProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function aMemberOfTheGameTriggersTheIngest(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $overpass = $this->createStub(OverpassService::class);
        $overpass->method('ingestFeatures')->willReturn(7);

        $resource = $this->processor($game, $player, $games, $overpass, lockAcquired: true)
            ->process(null, new Post(), ['gameUuid' => $game->getUuid()]);

        self::assertSame(7, $resource->featuresIngested);
    }

    #[Test]
    public function aPlayerOfAnotherGameIsRefusedBeforeTheIngest(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $otherGame = new Game('Paris', GameSize::Small, Edition::Metric);
        $player = new Player($otherGame, AccountFactory::create('Eve', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);
        $overpass = $this->createMock(OverpassService::class);
        $overpass->expects(self::never())->method('ingestFeatures');

        try {
            $this->processor($game, $player, $games, $overpass)
                ->process(null, new Post(), ['gameUuid' => $game->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('game.player_wrong_game', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn($game);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($game, null, $games, $this->createStub(OverpassService::class), withHeader: false)
            ->process(null, new Post(), ['gameUuid' => $game->getUuid()]);
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($game, $player, $games, $this->createStub(OverpassService::class))
            ->process(null, new Post(), ['gameUuid' => '00000000-0000-0000-0000-000000000000']);
    }

    private function processor(
        Game $game,
        ?Player $player,
        GameRepository $games,
        OverpassService $overpass,
        bool $withHeader = true,
        bool $lockAcquired = false,
    ): IngestFeaturesProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $locks = $this->createStub(HeavyWorkLockRepository::class);
        $locks->method('tryAcquireGlobal')->willReturn($lockAcquired);

        return new IngestFeaturesProcessor(
            $games,
            $overpass,
            new HeavyWorkGuard($locks),
            new IdentityResolver($mercure, $players, $stack),
        );
    }
}
