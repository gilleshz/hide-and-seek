<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GameArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameArea>
 */
class GameAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameArea::class);
    }

    public function save(GameArea $area, bool $flush = true): void
    {
        $this->getEntityManager()->persist($area);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function insert(GameArea $area): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'INSERT INTO game_areas (uuid, game_id, osm_type, osm_id, admin_level, name) '
            . 'VALUES (:uuid, :gameId, :osmType, :osmId, :adminLevel, :name)',
            [
                'uuid' => $area->getUuid(),
                'gameId' => $area->getGame()->getId(),
                'osmType' => $area->getOsmType(),
                'osmId' => $area->getOsmId(),
                'adminLevel' => $area->getAdminLevel(),
                'name' => $area->getName(),
            ],
        );
    }

    /** @return list<GameArea> */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game]);
    }

    /** @param list<string> $geoJsonStrings */
    public function unionGeoJsonStrings(array $geoJsonStrings): string
    {
        if ($geoJsonStrings === []) {
            return '{"type":"FeatureCollection","features":[]}';
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = [];
        $params = [];
        foreach ($geoJsonStrings as $i => $geoJson) {
            $key = 'gj' . $i;
            $placeholders[] = 'ST_GeomFromGeoJSON(:' . $key . ')::geometry';
            $params[$key] = $geoJson;
        }

        $sql = 'SELECT ST_AsGeoJSON(ST_Union(ARRAY[' . implode(', ', $placeholders) . ']))';
        $merged = $conn->fetchOne($sql, $params);

        if ($merged === false || !is_string($merged)) {
            return '{"type":"FeatureCollection","features":[]}';
        }

        $geometry = json_decode($merged, true, 512, JSON_THROW_ON_ERROR);

        return json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => is_array($geometry) ? $geometry : [],
                    'properties' => new \stdClass(),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $geoJsonStrings */
    public function unionGeoJsonToGeometryString(array $geoJsonStrings): ?string
    {
        if ($geoJsonStrings === []) {
            return null;
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = [];
        $params = [];
        foreach ($geoJsonStrings as $i => $geoJson) {
            $key = 'gj' . $i;
            $placeholders[] = 'ST_GeomFromGeoJSON(:' . $key . ')::geometry';
            $params[$key] = $geoJson;
        }

        $sql = 'SELECT ST_AsGeoJSON(ST_Union(ARRAY[' . implode(', ', $placeholders) . ']))';
        $merged = $conn->fetchOne($sql, $params);

        return $merged === false || !is_string($merged) ? null : $merged;
    }

    public function deleteByGame(Game $game): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM game_areas WHERE game_id = :gameId', ['gameId' => $game->getId()]);
    }
}
