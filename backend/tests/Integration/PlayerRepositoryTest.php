<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Game;
use App\Entity\Player;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Repository\PlayerRepository;
use App\Tests\Support\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayerRepositoryTest extends KernelTestCase
{
    #[Test]
    public function itReturnsOnlyActiveGamesForAnIdentity(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(PlayerRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(PlayerRepository::class, $repository);

        $identity = IdentityFactory::create('AvatarRepoTest');
        $activeGame = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $leftGame = new Game('Paris', GameSize::Medium, Edition::Metric);
        $active = new Player($activeGame, $identity);
        $left = new Player($leftGame, $identity);
        $left->markLeft(new \DateTimeImmutable());

        $em->persist($identity);
        $em->persist($activeGame);
        $em->persist($leftGame);
        $em->persist($active);
        $em->persist($left);
        $em->flush();

        try {
            $games = $repository->findActiveGamesByIdentity($identity);

            self::assertSame(
                [$activeGame->getUuid()],
                array_map(static fn (Game $game): string => $game->getUuid(), $games),
            );
        } finally {
            $em->remove($active);
            $em->remove($left);
            $em->remove($activeGame);
            $em->remove($leftGame);
            $em->remove($identity);
            $em->flush();
        }
    }
}
