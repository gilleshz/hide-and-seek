<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Game;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupCommandTest extends KernelTestCase
{
    #[Test]
    public function itReportsNoMatchingGamesForAPastCutoff(): void
    {
        $tester = $this->tester();
        $tester->execute(['--created-before' => '2000-01-01', '--force' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No matching games', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsAnInvalidDate(): void
    {
        $tester = $this->tester();
        $tester->execute(['--created-before' => 'not-a-date', '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid --created-before', $tester->getDisplay());
    }

    #[Test]
    public function itPurgesGamesWithForceAndListsThemWhenVerbose(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var GameRepository $games */
        $games = $container->get(GameRepository::class);

        $game = new Game('Command Purge', GameSize::Medium, Edition::Metric);
        $em->persist($game);
        $em->flush();
        $uuid = $game->getUuid();

        $tester = $this->tester();
        $tester->execute(['--force' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Purged', $tester->getDisplay());
        self::assertStringContainsString($uuid, $tester->getDisplay());
        self::assertNull($games->findOneByUuid($uuid));
    }

    private function tester(): CommandTester
    {
        // Reuse the already-booted kernel; rebooting here would close the EntityManager used to seed data.
        static::getContainer();
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('jetlag:cleanup'));
    }
}
