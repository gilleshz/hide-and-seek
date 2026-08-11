<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\StreetNetworkStatus;
use App\Repository\RoundStreetNetworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoundStreetNetworkRepositoryTest extends KernelTestCase
{
    /**
     * Two hider devices reading GET .../street-network at app start both find no row and both insert; the
     * loser used to violate uniq_round_street_network_round and close the entity manager for that request.
     */
    #[Test]
    public function itYieldsToARowAnotherRequestInsertedFirst(): void
    {
        $round = $this->round();
        $networks = $this->networks();
        $center = new Point(13.405, 52.52);

        $first = $networks->insertPendingForRound($round, $center, 500.0);
        $second = $networks->insertPendingForRound($round, new Point(13.5, 52.6), 750.0);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->getUuid(), $second->getUuid());
        self::assertSame(StreetNetworkStatus::Pending, $second->getStatus());
        self::assertSame(0, $second->getAttempts());
        self::assertNull($second->getPayload());

        $rows = $this->entityManager()->getConnection()->fetchAllAssociative(
            'SELECT uuid, radius_meters FROM round_street_networks WHERE round_id = :roundId',
            ['roundId' => $round->getId()],
        );
        self::assertCount(1, $rows);
        self::assertSame($first->getUuid(), $rows[0]['uuid']);
        self::assertEquals(500.0, $rows[0]['radius_meters']);
    }

    private function round(): Round
    {
        $em = $this->entityManager();
        $game = new Game('Race ' . uniqid(), GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $em->persist($game);
        $em->persist($round);
        $em->flush();

        return $round;
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
}
