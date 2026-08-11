<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Exception\IdentityRequiredException;
use App\Repository\PlayerRepository;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(IdentityResolver::class)]
final class IdentityResolverTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function itReturnsThePlayerForAValidToken(): void
    {
        $player = $this->player();
        $resolver = $this->resolver($player, $this->requestWith($this->tokenFor($player)));

        self::assertSame($player, $resolver->requirePlayer());
    }

    #[Test]
    public function aMissingHeaderYieldsTokenMissing(): void
    {
        $resolver = $this->resolver($this->player(), new RequestStack());

        $this->expectException(IdentityRequiredException::class);
        $this->expectExceptionMessage('subscriber token is required');
        $resolver->requirePlayer();
    }

    #[Test]
    public function aGarbledTokenYieldsTokenInvalid(): void
    {
        $resolver = $this->resolver($this->player(), $this->requestWith('not-a-jwt'));

        $this->expectException(IdentityRequiredException::class);
        $this->expectExceptionMessage('invalid or expired');
        $resolver->requirePlayer();
    }

    #[Test]
    public function anUnknownPlayerYieldsPlayerNotFound(): void
    {
        $resolver = $this->resolver(null, $this->requestWith($this->tokenFor($this->player())));

        $this->expectException(IdentityRequiredException::class);
        $this->expectExceptionMessage('unknown player');
        $resolver->requirePlayer();
    }

    #[Test]
    public function aLeftPlayerYieldsPlayerLeft(): void
    {
        $left = $this->player()->markLeft(new \DateTimeImmutable());
        $resolver = $this->resolver($left, $this->requestWith($this->tokenFor($left)));

        $this->expectException(IdentityRequiredException::class);
        $this->expectExceptionMessage('has left');
        $resolver->requirePlayer();
    }

    private function player(): Player
    {
        return new Player(new Game('Berlin', GameSize::Small, Edition::Metric), AccountFactory::create('Alice'));
    }

    private function tokenFor(Player $player): string
    {
        $mercure = new MercureJwtService(self::SECRET);

        return $mercure->issueSubscriberToken([$mercure->playerEndgameTopic($player->getUuid())], $player->getUuid());
    }

    private function requestWith(string $token): RequestStack
    {
        $request = new Request();
        $request->headers->set(IdentityResolver::HEADER, $token);
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function resolver(?Player $player, RequestStack $stack): IdentityResolver
    {
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);

        return new IdentityResolver(new MercureJwtService(self::SECRET), $players, $stack);
    }
}
