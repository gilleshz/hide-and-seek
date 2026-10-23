<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\JoinInput;
use App\Entity\Game;
use App\Entity\Identity;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;
use App\Repository\IdentityRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\ChatService;
use App\Service\IdentityLookupHasher;
use App\Service\IdentityService;
use App\Service\JoinService;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\State\JoinProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoinProcessor::class)]
final class JoinProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theJoinResponseNeverExposesACredential(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $game->setJoinCode('ABCDEF');
        $created = null;

        $identities = $this->createMock(IdentityRepository::class);
        $identities->method('findByLookupHash')->willReturn(null);
        $identities->method('lockLookup');
        $identities->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (Identity $identity) use (&$created): void {
                $created = $identity;
            });

        $processor = $this->processor(
            $game,
            $round,
            $this->createStub(PlayerRepository::class),
            $identities,
        );
        $input = new JoinInput();
        $input->name = 'Alice';
        $input->deviceKey = 'device-key';

        $resource = $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);

        self::assertSame('Alice', $resource->displayName);
        self::assertSame($round->getUuid(), $resource->roundUuid);
        self::assertObjectNotHasProperty('joinSecret', $resource);
        self::assertObjectNotHasProperty('deviceKey', $resource);
        self::assertObjectNotHasProperty('credentialHash', $resource);
        self::assertInstanceOf(Identity::class, $created);
        self::assertSame('Alice', $created->getDisplayName());
    }

    #[Test]
    public function aRejoinWithTheSameDeviceReturnsTheSamePlayer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $identity = IdentityFactory::create('Alice');
        $player = new Player($game, $identity);
        $game->setJoinCode('ABCDEF');

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn($player);

        $processor = $this->processor($game, $round, $players, $this->identitiesReturning($identity));
        $input = new JoinInput();
        $input->name = 'Alice';
        $input->deviceKey = IdentityFactory::deviceKey('Alice');

        $resource = $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);

        self::assertSame($player->getUuid(), $resource->playerUuid);
        self::assertObjectNotHasProperty('joinSecret', $resource);
    }

    #[Test]
    public function aDifferentDeviceCreatesADifferentIdentityWithTheSameDisplayName(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $game->setJoinCode('ABCDEF');
        $created = null;

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByGameAndIdentity')->willReturn(null);

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByLookupHash')->willReturn(null);
        $identities->method('save')->willReturnCallback(
            static function (Identity $identity) use (&$created): void {
                $created = $identity;
            },
        );

        $processor = $this->processor($game, $round, $players, $identities);
        $input = new JoinInput();
        $input->name = 'Alice';
        $input->deviceKey = 'a-second-device';

        $resource = $processor->process($input, new Post(), ['gameKey' => 'ABCDEF']);

        self::assertSame('Alice', $resource->displayName);
        self::assertInstanceOf(Identity::class, $created);
        self::assertSame('Alice', $created->getDisplayName());
        self::assertTrue($created->credentialMatches('a-second-device'));
        self::assertNotSame(IdentityFactory::lookupHash(IdentityFactory::deviceKey('Alice')), $created->getLookupHash());
    }

    #[Test]
    public function itRejectsAnUnknownGame(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);

        $games = $this->createStub(GameRepository::class);
        $games->method('findOneByUuid')->willReturn(null);
        $games->method('findOneBy')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor(
            $game,
            $round,
            $this->createStub(PlayerRepository::class),
            $this->createStub(IdentityRepository::class),
            games: $games,
        )->process(new JoinInput(), new Post(), ['gameKey' => 'UNKNOWN']);
    }

    private function identitiesReturning(?Identity $identity): IdentityRepository
    {
        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('findByLookupHash')->willReturn($identity);

        return $identities;
    }

    private function processor(
        Game $game,
        Round $round,
        PlayerRepository $players,
        IdentityRepository $identities,
        ?GameRepository $games = null,
    ): JoinProcessor {
        $mercure = new MercureJwtService(self::SECRET, 'https://jetlag.test');
        $hub = new FakeMercureHub();
        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn($round);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')->willReturn([]);

        $defaultGames = $this->createStub(GameRepository::class);
        $defaultGames->method('findOneByUuid')->willReturn($game);
        $defaultGames->method('findOneBy')->willReturn($game);
        $resolvedGames = $games ?? $defaultGames;

        $chat = new ChatService(
            $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $joinService = new JoinService(
            $resolvedGames,
            $rounds,
            $players,
            $entityManager,
            new RosterNotifier($hub, $mercure, new RosterService($players, $rounds, $memberships)),
            $chat,
        );
        $identityService = new IdentityService(
            $identities,
            new IdentityLookupHasher(IdentityFactory::LOOKUP_KEY),
            $entityManager,
        );

        return new JoinProcessor(
            $this->createStub(ValidatorInterface::class),
            $identityService,
            $joinService,
            $mercure,
            $entityManager,
        );
    }
}
