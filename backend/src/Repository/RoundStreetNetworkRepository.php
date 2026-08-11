<?php

declare(strict_types=1);

namespace App\Repository;

use App\DateFormat;
use App\Entity\Round;
use App\Entity\RoundStreetNetwork;
use App\Enum\StreetNetworkStatus;
use App\StreetNetworkRules;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RoundStreetNetwork>
 */
class RoundStreetNetworkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoundStreetNetwork::class);
    }

    public function findOneByUuid(string $uuid): ?RoundStreetNetwork
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByRound(Round $round): ?RoundStreetNetwork
    {
        return $this->findOneBy(['round' => $round]);
    }

    /**
     * @return list<RoundStreetNetwork>
     */
    public function findPendingForWarm(int $limit): array
    {
        $sql = <<<'SQL'
            SELECT n.id
            FROM round_street_networks n
            WHERE n.status = :status AND n.attempts < :maxAttempts
            ORDER BY n.updated_at ASC, n.id ASC
            LIMIT :limit
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'status' => StreetNetworkStatus::Pending->value,
            'maxAttempts' => StreetNetworkRules::MAX_WARM_ATTEMPTS,
            'limit' => $limit,
        ]);

        $ids = [];
        foreach ($rows as $row) {
            if (is_numeric($row)) {
                $ids[] = (int) $row;
            }
        }

        return $ids === [] ? [] : $this->findByIdsInOrder($ids);
    }

    /**
     * Both hider devices can insert at app start; ON CONFLICT yields to the first
     * instead of raising a unique violation and closing the manager.
     */
    public function insertPendingForRound(Round $round, Point $center, float $radiusMeters): ?RoundStreetNetwork
    {
        $sql = <<<'SQL'
            INSERT INTO round_street_networks (
                uuid, round_id, center, radius_meters, status, way_count, attempts, created_at, updated_at
            ) VALUES (
                :uuid, :roundId, ST_SetSRID(ST_MakePoint(:longitude, :latitude), 4326)::geography,
                :radiusMeters, :status, 0, 0, :now, :now
            )
            ON CONFLICT (round_id) DO NOTHING
        SQL;

        $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'uuid' => Uuid::v4()->toRfc4122(),
            'roundId' => $round->getId(),
            'longitude' => $center->getLongitude(),
            'latitude' => $center->getLatitude(),
            'radiusMeters' => $radiusMeters,
            'status' => StreetNetworkStatus::Pending->value,
            'now' => new \DateTimeImmutable()->format(DateFormat::DB_DATETIME),
        ]);

        return $this->findOneByRound($round);
    }

    /**
     * Serialises warm-up without waiting: the row stays pending for the whole
     * fetch, so later ticks' children fail the try-lock and exit rather than
     * pile up holding a connection each.
     */
    public function acquireWarmLock(Round $round): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:key)',
            ['key' => self::lockId($round)],
        );
    }

    public function releaseWarmLock(Round $round): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_unlock(:key)',
            ['key' => self::lockId($round)],
        );
    }

    public function refresh(RoundStreetNetwork $network): void
    {
        $this->getEntityManager()->refresh($network);
    }

    public function save(RoundStreetNetwork $network, bool $flush = true): void
    {
        $this->getEntityManager()->persist($network);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<RoundStreetNetwork>
     */
    private function findByIdsInOrder(array $ids): array
    {
        $qb = $this->createQueryBuilder('n');
        $qb->where($qb->expr()->in('n.id', $ids))->orderBy('n.updatedAt', 'ASC')->addOrderBy('n.id', 'ASC');

        /** @var list<RoundStreetNetwork> */
        return $qb->getQuery()->getResult();
    }

    private static function lockId(Round $round): int
    {
        return \abs(\crc32('street-network:' . $round->getId()));
    }
}
