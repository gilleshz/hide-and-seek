<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminAreaRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{relId: int, role: string, points: list<array{lat: float, lon: float}>}> $relationWays
     * @param list<array{lat: float, lon: float}> $points
     * @return list<int>
     */
    public function findContainingRelationIds(array $relationWays, array $points): array
    {
        $ways = self::waysPayload($relationWays);
        $pointRows = self::pointsPayload($points);
        if ($ways === [] || $pointRows === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                WITH ways AS (
                  SELECT rel_id, role, ST_GeomFromText(wkt, 4326) AS geom
                  FROM jsonb_to_recordset(CAST(:ways AS jsonb)) AS t(rel_id bigint, role text, wkt text)
                ),
                outers AS (
                  SELECT rel_id, ST_CollectionExtract(ST_Polygonize(geom), 3) AS geom
                  FROM ways WHERE role <> 'inner' GROUP BY rel_id
                ),
                inners AS (
                  SELECT rel_id, ST_CollectionExtract(ST_Polygonize(geom), 3) AS geom
                  FROM ways WHERE role = 'inner' GROUP BY rel_id
                ),
                pts AS (
                  SELECT ST_GeomFromText(wkt, 4326) AS geom
                  FROM jsonb_to_recordset(CAST(:points AS jsonb)) AS t(wkt text)
                )
                SELECT DISTINCT o.rel_id
                FROM outers o
                LEFT JOIN inners i ON i.rel_id = o.rel_id
                JOIN pts p ON o.geom && p.geom AND ST_Contains(o.geom, p.geom)
                WHERE i.geom IS NULL OR NOT ST_Contains(i.geom, p.geom)
                SQL,
            [
                'ways' => json_encode($ways, JSON_THROW_ON_ERROR),
                'points' => json_encode($pointRows, JSON_THROW_ON_ERROR),
            ],
        )->fetchAllAssociative();

        return self::ids($rows);
    }

    /**
     * Relations whose outer ring could not be built from the available ways. A mirror with an
     * incomplete extract returns some members without geometry, and those communes would be
     * silently dropped from the fit.
     *
     * @param list<array{relId: int, role: string, points: list<array{lat: float, lon: float}>}> $relationWays
     * @return list<int>
     */
    public function findEmptyOuterRelationIds(array $relationWays): array
    {
        $ways = self::waysPayload($relationWays);
        if ($ways === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                WITH ways AS (
                  SELECT rel_id, role, ST_GeomFromText(wkt, 4326) AS geom
                  FROM jsonb_to_recordset(CAST(:ways AS jsonb)) AS t(rel_id bigint, role text, wkt text)
                ),
                outers AS (
                  SELECT rel_id, ST_CollectionExtract(ST_Polygonize(geom), 3) AS geom
                  FROM ways WHERE role <> 'inner' GROUP BY rel_id
                )
                SELECT rel_id FROM outers WHERE ST_IsEmpty(geom)
                SQL,
            ['ways' => json_encode($ways, JSON_THROW_ON_ERROR)],
        )->fetchAllAssociative();

        return self::ids($rows);
    }

    /**
     * @param list<array{relId: int, geoJson: string}> $polygons
     * @param list<array{lat: float, lon: float}> $points
     * @return list<int>
     */
    public function findContainingGeoJsonRelationIds(array $polygons, array $points): array
    {
        $polygonRows = [];
        foreach ($polygons as $polygon) {
            if ($polygon['geoJson'] === '') {
                continue;
            }
            $polygonRows[] = ['rel_id' => $polygon['relId'], 'geojson' => $polygon['geoJson']];
        }

        $pointRows = self::pointsPayload($points);
        if ($polygonRows === [] || $pointRows === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                WITH polys AS (
                  SELECT rel_id, ST_SetSRID(ST_GeomFromGeoJSON(geojson), 4326) AS geom
                  FROM jsonb_to_recordset(CAST(:polys AS jsonb)) AS t(rel_id bigint, geojson text)
                ),
                pts AS (
                  SELECT ST_GeomFromText(wkt, 4326) AS geom
                  FROM jsonb_to_recordset(CAST(:points AS jsonb)) AS t(wkt text)
                )
                SELECT DISTINCT p.rel_id
                FROM polys p
                JOIN pts pt ON p.geom && pt.geom AND ST_Contains(p.geom, pt.geom)
                SQL,
            [
                'polys' => json_encode($polygonRows, JSON_THROW_ON_ERROR),
                'points' => json_encode($pointRows, JSON_THROW_ON_ERROR),
            ],
        )->fetchAllAssociative();

        return self::ids($rows);
    }

    /**
     * @param list<array{relId: int, role: string, points: list<array{lat: float, lon: float}>}> $relationWays
     * @return list<array{rel_id: int, role: string, wkt: string}>
     */
    private static function waysPayload(array $relationWays): array
    {
        $ways = [];
        foreach ($relationWays as $way) {
            $wkt = self::lineWkt($way['points']);
            if ($wkt !== null) {
                $ways[] = ['rel_id' => $way['relId'], 'role' => $way['role'], 'wkt' => $wkt];
            }
        }

        return $ways;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return list<array{wkt: string}>
     */
    private static function pointsPayload(array $points): array
    {
        $rows = [];
        foreach ($points as $point) {
            $rows[] = ['wkt' => sprintf('POINT(%.7F %.7F)', $point['lon'], $point['lat'])];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['rel_id'] ?? null;
            if (is_int($id) || is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /** @param list<array{lat: float, lon: float}> $points */
    private static function lineWkt(array $points): ?string
    {
        $parts = [];
        $previous = null;
        foreach ($points as $point) {
            $part = sprintf('%.7F %.7F', $point['lon'], $point['lat']);
            if ($part === $previous) {
                continue;
            }
            $parts[] = $part;
            $previous = $part;
        }

        return count($parts) >= 2 ? 'LINESTRING(' . implode(',', $parts) . ')' : null;
    }
}
