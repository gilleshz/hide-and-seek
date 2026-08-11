<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundStreetNetwork;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Enum\StreetNetworkStatus;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\StreetNetworkFillSpawner;
use App\StreetNetworkRules;
use App\Tests\Fake\FakeStreetNetworkFillSpawner;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class StreetNetworkWarmCommandTest extends KernelTestCase
{
    /** The test database is not rolled back between cases, and a stray pending row would fetch for real. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager()->getConnection()->executeStatement('TRUNCATE round_street_networks');
    }

    #[Test]
    public function itPicksUpOnlyPendingRowsUnderTheAttemptCapOldestFirst(): void
    {
        $ready = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Ready, 0);
        $exhausted = $this->network(
            RoundStatus::Hiding,
            StreetNetworkStatus::Pending,
            StreetNetworkRules::MAX_WARM_ATTEMPTS,
        );
        $newer = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 0);
        $older = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 1);

        $this->backdate($older, '2020-01-01 00:00:00');
        $this->backdate($newer, '2020-01-02 00:00:00');

        $due = $this->networks()->findPendingForWarm(StreetNetworkRules::WARM_BATCH_LIMIT);

        $uuids = array_map(static fn (RoundStreetNetwork $n): string => $n->getUuid(), $due);
        self::assertSame([$older->getUuid(), $newer->getUuid()], $uuids);
        self::assertNotContains($ready->getUuid(), $uuids);
        self::assertNotContains($exhausted->getUuid(), $uuids);
    }

    #[Test]
    public function itHonoursTheBatchLimit(): void
    {
        for ($i = 0; $i <= StreetNetworkRules::WARM_BATCH_LIMIT; ++$i) {
            $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 0);
        }

        self::assertCount(StreetNetworkRules::WARM_BATCH_LIMIT, $this->networks()->findPendingForWarm(
            StreetNetworkRules::WARM_BATCH_LIMIT,
        ));
    }

    /** A finished round needs no network, so the tick retires the row instead of fetching a bbox for it. */
    #[Test]
    public function itRetiresTheRowOfARoundThatIsNoLongerRunning(): void
    {
        $network = $this->network(RoundStatus::Ended, StreetNetworkStatus::Pending, 0);

        $spawner = $this->recordingSpawner();
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Handled 1 street network row', $tester->getDisplay());
        self::assertSame([], $spawner->spawned());

        $this->entityManager()->clear();
        $reloaded = $this->networks()->findOneByUuid($network->getUuid());
        self::assertNotNull($reloaded);
        self::assertSame(StreetNetworkStatus::Unavailable, $reloaded->getStatus());
        self::assertSame(0, $reloaded->getAttempts());
    }

    /**
     * The tick shares its consumer with app:round:tick, so it must hand the fetch to a detached child and
     * leave the row pending rather than spend up to eight minutes on a degraded mirror pool.
     */
    #[Test]
    public function itHandsARunningRoundToADetachedFillAndLeavesTheRowPending(): void
    {
        $network = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 0);

        $spawner = $this->recordingSpawner();
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([$network->getUuid()], $spawner->spawned());

        $this->entityManager()->clear();
        $reloaded = $this->networks()->findOneByUuid($network->getUuid());
        self::assertNotNull($reloaded);
        self::assertSame(StreetNetworkStatus::Pending, $reloaded->getStatus());
        self::assertSame(0, $reloaded->getAttempts());
    }

    /** One row that cannot be spawned must not cost the rest of the batch its tick. */
    #[Test]
    public function itCarriesOnAfterARowThatThrows(): void
    {
        $first = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 0);
        $second = $this->network(RoundStatus::Hiding, StreetNetworkStatus::Pending, 0);
        $this->backdate($first, '2020-01-01 00:00:00');
        $this->backdate($second, '2020-01-02 00:00:00');

        $spawner = $this->recordingSpawner($first->getUuid());
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([$second->getUuid()], $spawner->spawned());
        self::assertStringContainsString('Handled 1 street network row', $tester->getDisplay());
    }

    #[Test]
    public function itSaysNothingWhenThereIsNoRowToWarm(): void
    {
        $this->recordingSpawner();
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('Handled', $tester->getDisplay());
    }

    private function network(RoundStatus $status, StreetNetworkStatus $networkStatus, int $attempts): RoundStreetNetwork
    {
        $em = $this->entityManager();
        $game = new Game('Warm ' . uniqid(), GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $round->setStatus($status);
        $em->persist($game);
        $em->persist($round);

        $network = new RoundStreetNetwork($round, new Point(13.405, 52.52), 500.0);
        $network->setStatus($networkStatus)->setAttempts($attempts);
        $em->persist($network);
        $em->flush();

        return $network;
    }

    private function backdate(RoundStreetNetwork $network, string $updatedAt): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE round_street_networks SET updated_at = :updatedAt WHERE uuid = :uuid',
            ['updatedAt' => $updatedAt, 'uuid' => $network->getUuid()],
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function networks(): RoundStreetNetworkRepository
    {
        /** @var RoundStreetNetworkRepository $networks */
        $networks = static::getContainer()->get(RoundStreetNetworkRepository::class);

        return $networks;
    }

    /** Nothing here may reach Overpass, so the spawner is replaced before the command is ever instantiated. */
    private function recordingSpawner(string $refusedUuid = ''): FakeStreetNetworkFillSpawner
    {
        $spawner = new FakeStreetNetworkFillSpawner($refusedUuid);
        static::getContainer()->set(StreetNetworkFillSpawner::class, $spawner);

        return $spawner;
    }

    private function tester(): CommandTester
    {
        /** @var KernelInterface $kernel */
        $kernel = static::getContainer()->get('kernel');

        return new CommandTester(new Application($kernel)->find('app:street-network:warm'));
    }
}
