<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Identity;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Repository\IdentityRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\AvatarService;
use App\Service\IdentityRosterPublisher;
use App\Service\MercureJwtService;
use App\Service\RosterNotifier;
use App\Service\RosterService;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\IdentityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mercure\Update;

#[CoversClass(AvatarService::class)]
final class AvatarServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    private const string AVATAR_REF = 'avatar-new.jpg';

    #[Test]
    public function itPublishesTheRosterAfterAnAvatarUpload(): void
    {
        [$avatars, $hub, $games, $identity] = $this->avatars();

        $avatars->replace($identity, $this->createStub(UploadedFile::class));

        $updates = $hub->published();
        self::assertSame($this->rosterTopics($games), $this->updateTopics($updates));
        foreach ($updates as $update) {
            self::assertSame(self::AVATAR_REF, self::publishedAvatarRef($update->getData()));
        }
    }

    #[Test]
    public function itPublishesTheRosterAfterAnAvatarDelete(): void
    {
        [$avatars, $hub, $games, $identity] = $this->avatars();
        $identity->replaceAvatar(self::AVATAR_REF);

        $avatars->remove($identity);

        $updates = $hub->published();
        self::assertSame($this->rosterTopics($games), $this->updateTopics($updates));
        foreach ($updates as $update) {
            self::assertNull(self::publishedAvatarRef($update->getData()));
        }
    }

    #[Test]
    public function itDoesNotPublishWhenThereIsNoAvatarToDelete(): void
    {
        [$avatars, $hub] = $this->avatars();

        $avatars->remove(IdentityFactory::create('Bob'));

        self::assertSame([], $hub->published());
    }

    /**
     * @return array{AvatarService, FakeMercureHub, list<Game>, Identity}
     */
    private function avatars(): array
    {
        $berlin = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $paris = new Game('Paris', GameSize::Medium, Edition::Metric);
        $round = new Round($berlin);
        $identity = IdentityFactory::create('Alice');
        $berlinPlayer = new Player($berlin, $identity);
        $parisPlayer = new Player($paris, $identity);

        $players = $this->createStub(PlayerRepository::class);
        $players->method('findActiveGamesByIdentity')->willReturn([$berlin, $paris]);
        $players->method('findByGameOrdered')->willReturnCallback(
            static fn (Game $game): array => $game->getUuid() === $berlin->getUuid()
                ? [$berlinPlayer]
                : [$parisPlayer],
        );

        $storage = $this->createStub(ImageStorageInterface::class);
        $storage->method('store')->willReturn(self::AVATAR_REF);

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findActiveByGame')->willReturn($round);
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findByRound')->willReturn([]);

        $hub = new FakeMercureHub();
        $notifier = new RosterNotifier(
            $hub,
            new MercureJwtService(self::SECRET, 'https://jetlag.test'),
            new RosterService($players, $rounds, $memberships),
        );

        $avatars = new AvatarService(
            $storage,
            $this->createStub(IdentityRepository::class),
            new IdentityRosterPublisher($players, $notifier, new NullLogger()),
        );

        return [$avatars, $hub, [$berlin, $paris], $identity];
    }

    /**
     * @param list<Game> $games
     *
     * @return list<string>
     */
    private function rosterTopics(array $games): array
    {
        return array_map(
            static fn (Game $game): string => "game/{$game->getUuid()}/roster",
            $games,
        );
    }

    /**
     * @param list<Update> $updates
     *
     * @return list<string>
     */
    private function updateTopics(array $updates): array
    {
        return array_map(static function (Update $update): string {
            $topic = $update->getTopics()[0] ?? null;
            self::assertIsString($topic);

            return $topic;
        }, $updates);
    }

    private static function publishedAvatarRef(string $data): ?string
    {
        $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $players = $payload['players'] ?? null;
        self::assertIsArray($players);
        $player = $players[0] ?? null;
        self::assertIsArray($player);
        $avatarRef = $player['avatarRef'] ?? null;

        if ($avatarRef !== null) {
            self::assertIsString($avatarRef);
        }

        return $avatarRef;
    }
}
