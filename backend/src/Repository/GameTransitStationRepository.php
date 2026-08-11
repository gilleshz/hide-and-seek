<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GameTransitStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * @extends ServiceEntityRepository<GameTransitStation>
 */
class GameTransitStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameTransitStation::class);
    }

    public function save(GameTransitStation $station, bool $flush = true): void
    {
        $this->getEntityManager()->persist($station);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * The station a tap snaps to, so every later test runs against an imported station rather than a
     * client-supplied coordinate. A tap with no station in reach snaps to nothing.
     */
    public function findNearestWithin(Game $game, Point $point, int $maxMeters): ?GameTransitStation
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT s.id
            FROM game_transit_stations s
            WHERE s.game_id = :gameId
              AND ST_DWithin(s.point, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
            ORDER BY s.point::geometry <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)
            LIMIT 1
        SQL;

        $raw = $conn->fetchOne($sql, [
            'gameId' => $game->getId(),
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
            'radius' => $maxMeters,
        ]);

        return is_numeric($raw) ? $this->find((int) $raw) : null;
    }

    /**
     * @return list<string>|null
     */
    public function findNearestServingRefs(Game $game, Point $point): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT s.line_refs
            FROM game_transit_stations s
            WHERE s.game_id = :gameId
            ORDER BY s.point::geometry <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)
            LIMIT 1
        SQL;

        $raw = $conn->fetchOne($sql, [
            'gameId' => $game->getId(),
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
        ]);
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
