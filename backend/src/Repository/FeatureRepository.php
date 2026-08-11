<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feature;
use App\Entity\Game;
use App\Enum\FeatureType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Feature>
 */
class FeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feature::class);
    }

    public function findOneByUuid(string $uuid): ?Feature
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<Feature>
     */
    public function findByGame(Game $game): array
    {
        /** @var list<Feature> */
        return $this->findBy(['game' => $game]);
    }

    public function countByGame(Game $game): int
    {
        return $this->count(['game' => $game]);
    }

    public function countByGameAndType(Game $game, FeatureType $type): int
    {
        return $this->count(['game' => $game, 'featureType' => $type->value]);
    }

    /**
     * Serialise concurrent ingests for the same game+type so the idempotency check cannot race.
     */
    public function acquireIngestLock(Game $game, FeatureType $type): void
    {
        $lockId = \abs(\crc32($game->getId() . ':' . $type->value));
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_lock(:key)',
            ['key' => $lockId],
        );
    }

    public function releaseIngestLock(Game $game, FeatureType $type): void
    {
        $lockId = \abs(\crc32($game->getId() . ':' . $type->value));
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_unlock(:key)',
            ['key' => $lockId],
        );
    }

    /**
     * @return list<Feature>
     */
    public function findNearestWithin(Game $game, FeatureType $type, Point $point, int $limit): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT f.id
            FROM features f
            WHERE f.game_id = :gameId
              AND f.feature_type = :featureType
              AND f.point && ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat, 4326)
            ORDER BY ST_Distance(
                COALESCE(
                    ST_GeomFromText(f.geometry::text, 4326)::geography,
                    f.point::geography
                ),
                ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            )
            LIMIT :limit
        SQL;

        $rows = $conn->fetchAllAssociative($sql, [
            'gameId' => $game->getId(),
            'featureType' => $type->value,
            'swLat' => $game->getBoundarySwLat() ?? -90.0,
            'swLng' => $game->getBoundarySwLng() ?? -180.0,
            'neLat' => $game->getBoundaryNeLat() ?? 90.0,
            'neLng' => $game->getBoundaryNeLng() ?? 180.0,
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
            'limit' => $limit,
        ]);

        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!is_numeric($id)) {
                continue;
            }
            $ids[] = (int) $id;
        }

        if ($ids === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('f');
        $qb->where($qb->expr()->in('f.id', $ids));
        /** @var list<Feature> $features */
        $features = $qb->getQuery()->getResult();

        // The IN requery loses the distance ordering; restore it from $ids (nearest first).
        $byId = [];
        foreach ($features as $feature) {
            $byId[(int) $feature->getId()] = $feature;
        }

        return array_values(array_filter(array_map(static fn(int $id): ?Feature => $byId[$id] ?? null, $ids)));
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public function findNamedUuidsByType(Game $game, FeatureType $type): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT uuid, name FROM features WHERE game_id = :gameId AND feature_type = :ftype AND name IS NOT NULL',
            ['gameId' => $game->getId(), 'ftype' => $type->value],
        );

        $named = [];
        foreach ($rows as $row) {
            $uuid = $row['uuid'];
            $name = $row['name'];
            if (is_string($uuid) && is_string($name)) {
                $named[] = ['uuid' => $uuid, 'name' => $name];
            }
        }

        return $named;
    }

    public function countWithinRadius(Game $game, FeatureType $type, Point $point, float $radiusMeters): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT COUNT(f.id) as cnt
            FROM features f
            WHERE f.game_id = :gameId
              AND f.feature_type = :featureType
              AND ST_DWithin(
                  COALESCE(
                      ST_GeomFromText(f.geometry::text, 4326)::geography,
                      f.point::geography
                  ),
                  ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                  :radius
              )
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'featureType' => $type->value,
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
            'radius' => $radiusMeters,
        ]);

        $cnt = $result['cnt'] ?? 0;
        if (!is_numeric($cnt)) {
            return 0;
        }

        return (int) $cnt;
    }

    /**
     * @return list<array{uuid: string, name: ?string, lat: float, lng: float}>
     */
    public function findByGameAndType(Game $game, FeatureType $type): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT f.uuid, f.name,
                   ST_Y(f.point::geometry) AS lat,
                   ST_X(f.point::geometry) AS lng
            FROM features f
            WHERE f.game_id = :gameId AND f.feature_type = :featureType
            ORDER BY f.name ASC
        SQL;

        /** @var list<array{uuid: string, name: ?string, lat: float, lng: float}> */
        return $conn->fetchAllAssociative($sql, [
            'gameId' => $game->getId(),
            'featureType' => $type->value,
        ]);
    }

    public function findNearestFeatureUuid(Game $game, FeatureType $type, Point $point): ?string
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT f.uuid
            FROM features f
            WHERE f.game_id = :gameId AND f.feature_type = :featureType
            ORDER BY COALESCE(ST_GeomFromText(f.geometry::text, 4326), f.point::geometry)
                <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)
            LIMIT 1
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'featureType' => $type->value,
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
        ]);

        $uuid = $result['uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function distanceToFeature(Feature $feature, Point $point): float
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT ST_Distance(
                COALESCE(
                    ST_GeomFromText(f.geometry::text, 4326)::geography,
                    f.point::geography
                ),
                ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            ) AS distance
            FROM features f
            WHERE f.id = :id
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'id' => $feature->getId(),
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
        ]);

        $distance = $result['distance'] ?? null;
        if (!is_numeric($distance)) {
            return 0.0;
        }

        return (float) $distance;
    }

    public function save(Feature $feature, bool $flush = true): void
    {
        $this->getEntityManager()->persist($feature);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function insertAssembledAdminDivision(Game $game, FeatureType $type, string $name, string $multiLineWkt): bool
    {
        $sql = <<<'SQL'
            INSERT INTO features (uuid, game_id, feature_type, name, point, geometry)
            SELECT :uuid, :gameId, :ftype, :name,
                   ST_PointOnSurface(asm.g)::geography,
                   ST_AsText(asm.g)
            FROM (
                SELECT ST_SetSRID(
                    ST_Multi(ST_CollectionExtract(ST_MakeValid(
                        ST_BuildArea(ST_LineMerge(ST_GeomFromText(:mls, 4326)))
                    ), 3)), 4326
                ) AS g
            ) asm
            WHERE asm.g IS NOT NULL AND NOT ST_IsEmpty(asm.g)
        SQL;

        $affected = $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'uuid' => Uuid::v4()->toRfc4122(),
            'gameId' => $game->getId(),
            'ftype' => $type->value,
            'name' => $name,
            'mls' => $multiLineWkt,
        ]);

        return $affected > 0;
    }
}
