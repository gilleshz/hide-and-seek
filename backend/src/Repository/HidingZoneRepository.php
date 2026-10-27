<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\HidingZone;
use App\Entity\Round;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/**
 * @extends ServiceEntityRepository<HidingZone>
 */
class HidingZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HidingZone::class);
    }

    public function findOneByRound(Round $round): ?HidingZone
    {
        return $this->findOneBy(['round' => $round]);
    }

    public function save(HidingZone $zone, bool $flush = true): void
    {
        $this->getEntityManager()->persist($zone);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function zoneFitsInsideBoundary(Game $game, Point $point, float $radiusMeters): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $fits = $conn->fetchOne(<<<'SQL'
            SELECT ST_Covers(
                ST_GeomFromGeoJSON(boundary_geo_json),
                ST_Buffer(ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radiusMeters)::geometry
            )::int
            FROM games
            WHERE id = :gameId AND boundary_geo_json IS NOT NULL
        SQL, [
            'gameId' => $game->getId(),
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
            'radiusMeters' => $radiusMeters,
        ]);

        return is_numeric($fits) && (int) $fits === 1;
    }
}
