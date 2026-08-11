<?php

declare(strict_types=1);

namespace App\Repository;

use App\DateFormat;
use App\Entity\Round;
use App\Entity\TimeTrap;
use App\Enum\TimeTrapStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * @extends ServiceEntityRepository<TimeTrap>
 */
class TimeTrapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeTrap::class);
    }

    public function findOneByUuid(string $uuid): ?TimeTrap
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<TimeTrap>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['placedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function countByRound(Round $round): int
    {
        return $this->count(['round' => $round]);
    }

    /**
     * The detection dialog pops on every seeker device, so Confirm and Dismiss race each other and two
     * seekers pinging in the same second race the detection itself. Only the caller that moves the status
     * wins; without this a trap could bank its value and re-arm, paying out twice.
     */
    public function claimStatus(TimeTrap $trap, TimeTrapStatus $from, TimeTrapStatus $to): bool
    {
        $updated = $this->createQueryBuilder('t')
            ->update()
            ->set('t.status', ':to')
            ->andWhere('t.id = :id')
            ->andWhere('t.status = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->setParameter('id', $trap->getId())
            ->getQuery()
            ->execute();

        return $updated === 1;
    }

    public function save(TimeTrap $trap, bool $flush = true): void
    {
        $this->getEntityManager()->persist($trap);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Tests the segment between a seeker's two most recent pings, not the latest point alone: at the
     * ping cadence a train covers over 300 m between fixes, so a point test would miss the station it
     * just flew through.
     *
     * @return list<string>
     */
    public function findTrippedUuids(
        Round $round,
        Point $from,
        Point $to,
        int $radiusMeters,
        \DateTimeImmutable $cooldownBefore,
    ): array {
        $sql = <<<'SQL'
            SELECT t.uuid FROM time_traps t
            WHERE t.round_id = :roundId AND t.status = :status
              AND (t.rearmed_at IS NULL OR t.rearmed_at <= :cooldownBefore)
              AND ST_DWithin(
                    t.point,
                    ST_SetSRID(
                        ST_MakeLine(ST_MakePoint(:lng1, :lat1), ST_MakePoint(:lng2, :lat2)),
                        4326
                    )::geography,
                    :radius)
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'roundId' => $round->getId(),
            'status' => TimeTrapStatus::Armed->value,
            'cooldownBefore' => $cooldownBefore->format(DateFormat::DB_DATETIME),
            'lng1' => $from->getLongitude(),
            'lat1' => $from->getLatitude(),
            'lng2' => $to->getLongitude(),
            'lat2' => $to->getLatitude(),
            'radius' => $radiusMeters,
        ]);

        return array_values(array_filter($rows, 'is_string'));
    }
}
