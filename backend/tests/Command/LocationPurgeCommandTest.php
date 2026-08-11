<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Game;
use App\Entity\GtfsSource;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LocationPurgeCommandTest extends KernelTestCase
{
    #[Test]
    public function itDeletesLocationsOfEndedRoundsOlderThanTheWindowAndKeepsEverythingElse(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $oldEnded = $this->seedLocation($em, RoundStatus::Ended, old: true);
        $freshEnded = $this->seedLocation($em, RoundStatus::Ended, old: false);
        $oldActive = $this->seedLocation($em, RoundStatus::Hiding, old: true);

        $this->purge();
        $em->clear();

        self::assertNull($em->find(PlayerLocation::class, $oldEnded));
        self::assertNotNull($em->find(PlayerLocation::class, $freshEnded));
        self::assertNotNull($em->find(PlayerLocation::class, $oldActive));
    }

    #[Test]
    public function itDeletesOrphanedGtfsSourcesAndTheirFilesButKeepsAttachedOnes(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $orphanPath = $this->seedSource($em, old: true, attachToGame: false);
        $attachedPath = $this->seedSource($em, old: true, attachToGame: true);

        $this->purge();

        self::assertFileDoesNotExist($orphanPath);
        self::assertFileExists($attachedPath);
        $em->clear();
        self::assertNull($em->getRepository(GtfsSource::class)->findOneBy(['filePath' => $orphanPath]));
        self::assertNotNull($em->getRepository(GtfsSource::class)->findOneBy(['filePath' => $attachedPath]));

        // Leave no fixtures behind: the DBAL-deleted source rows would trip a later purge's flush.
        $em->getConnection()->executeStatement('DELETE FROM gtfs_source WHERE file_path = :path', ['path' => $attachedPath]);
        $em->getConnection()->executeStatement('DELETE FROM games WHERE name = :name', ['name' => 'Attached Game']);
        $em->clear();
    }

    private function purge(): CommandTester
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:locations:purge'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function seedLocation(
        EntityManagerInterface $em,
        RoundStatus $status,
        bool $old,
    ): int {
        $game = new Game('Purge Game', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus($status);
        $account = AccountFactory::create('Alice ' . uniqid(), 'test-password');
        $player = new Player($game, $account);
        $location = new PlayerLocation($round, $player, new Point(13.405, 52.52));
        $em->persist($game);
        $em->persist($round);
        $em->persist($account);
        $em->persist($player);
        $em->persist($location);
        $em->flush();
        $id = $location->getId();
        self::assertIsInt($id);

        if ($old) {
            $em->getConnection()->executeStatement(
                "UPDATE player_locations SET recorded_at = now() - interval '8 days' WHERE id = :id",
                ['id' => $id],
            );
        }

        return $id;
    }

    private function seedSource(
        EntityManagerInterface $em,
        bool $old,
        bool $attachToGame,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'gtfs-orphan-') . '.zip';
        file_put_contents($path, 'fixture bytes');
        $source = new GtfsSource('Orphan Feed', $path);

        $game = null;
        if ($attachToGame) {
            $game = new Game('Attached Game', GameSize::Medium, Edition::Metric);
            $em->persist($game);
            $source->setGame($game);
        }
        $em->persist($source);
        $em->flush();

        if ($old) {
            $em->getConnection()->executeStatement(
                "UPDATE gtfs_source SET created_at = now() - interval '8 days' WHERE id = :id",
                ['id' => $source->getId()],
            );
        }

        return $path;
    }
}
