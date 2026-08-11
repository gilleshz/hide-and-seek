<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\ManualConstraintDraft;
use App\Entity\Game;
use App\Entity\PossibleAreaConstraint;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ConstraintMode;
use App\Enum\ConstraintSource;
use App\Enum\FeatureType;
use App\Exception\FunctionalException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PossibleAreaConstraint>
 */
class PossibleAreaConstraintRepository extends ServiceEntityRepository
{
    private const float COMPLEMENT_FALLBACK_RADIUS_M = 300000.0;
    private const float FALLBACK_ENVELOPE_DEG = 2.7;

    public function __construct(
        ManagerRegistry $registry,
        private readonly FeatureRepository $featureRepository,
    ) {
        parent::__construct($registry, PossibleAreaConstraint::class);
    }

    /**
     * @return list<PossibleAreaConstraint>
     */
    public function findByRound(Round $round): array
    {
        /** @var list<PossibleAreaConstraint> */
        return $this->findBy(['round' => $round], ['createdAt' => 'ASC']);
    }

    public function findOneByUuid(string $uuid): ?PossibleAreaConstraint
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<PossibleAreaConstraint>
     */
    public function findManualByRound(Round $round): array
    {
        /** @var list<PossibleAreaConstraint> */
        return $this->findBy(
            ['round' => $round, 'source' => ConstraintSource::Manual],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function remove(PossibleAreaConstraint $constraint): void
    {
        $this->getEntityManager()->remove($constraint);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<array{
     *     uuid: string,
     *     mode: string,
     *     source: string,
     *     label: string,
     *     geoJson: ?string,
     *     createdByName: ?string,
     * }>
     */
    public function findManualWithDetailsByRound(Round $round): array
    {
        $sql = <<<'SQL'
            SELECT pac.uuid AS uuid, pac.mode AS mode, pac.source AS source, pac.label AS label,
                   ST_AsGeoJSON(pac.draw_geometry::geometry) AS geo_json,
                   accounts.name AS created_by_name
            FROM possible_area_constraints pac
            LEFT JOIN round_memberships rm ON rm.id = pac.created_by_membership_id
            LEFT JOIN players p ON p.id = rm.player_id
            LEFT JOIN accounts ON accounts.id = p.account_id
            WHERE pac.round_id = :roundId AND pac.source = 'manual'
            ORDER BY pac.created_at ASC, pac.id ASC
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['roundId' => $round->getId()]);

        return array_map(
            static fn (array $row): array => [
                'uuid' => is_string($row['uuid']) ? $row['uuid'] : '',
                'mode' => is_string($row['mode']) ? $row['mode'] : '',
                'source' => is_string($row['source']) ? $row['source'] : '',
                'label' => is_string($row['label']) ? $row['label'] : '',
                'geoJson' => is_string($row['geo_json']) ? $row['geo_json'] : null,
                'createdByName' => is_string($row['created_by_name']) ? $row['created_by_name'] : null,
            ],
            $rows,
        );
    }

    /**
     * The ring is validated server-side so malformed geometry can never corrupt
     * the recursive intersection fold.
     */
    public function insertManualConstraint(Round $round, RoundMembership $creator, ManualConstraintDraft $draft): string
    {
        $this->assertValidRing($draft->ringGeoJson);

        $uuid = Uuid::v4()->toRfc4122();
        $geometryExpr = $this->manualGeometryExpression($round->getGame(), $draft->mode);
        $sql = <<<SQL
            INSERT INTO possible_area_constraints
                (uuid, round_id, geometry, draw_geometry, label, source, mode, created_by_membership_id, created_at)
            VALUES (
                :uuid, :roundId,
                {$geometryExpr},
                ST_GeomFromGeoJSON(:ring)::geography,
                :label, 'manual', :mode, :creatorId, NOW()
            )
        SQL;

        $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            $this->manualInsertParams($round, $creator, $draft, $uuid),
        );

        return $uuid;
    }

    private function assertValidRing(string $ringGeoJson): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT (ST_IsValid(g) AND GeometryType(ST_MakeValid(g)) IN ('POLYGON', 'MULTIPOLYGON')) AS ok
            FROM (SELECT ST_GeomFromGeoJSON(:ring) AS g) s
        SQL;

        try {
            $row = $conn->fetchAssociative($sql, ['ring' => $ringGeoJson]);
        } catch (DbalException $e) {
            throw new FunctionalException('Manual constraint geometry is malformed.', 'constraint.manual.invalid_geometry', null, $e);
        }

        if (!(bool) (($row['ok'] ?? false))) {
            throw new FunctionalException('Manual constraint must be a valid polygon.', 'constraint.manual.invalid_geometry');
        }
    }

    private function manualGeometryExpression(Game $game, ConstraintMode $mode): string
    {
        $ring = 'ST_MakeValid(ST_GeomFromGeoJSON(:ring))';
        if ($mode === ConstraintMode::Include) {
            return "{$ring}::geography";
        }

        return 'ST_Difference(' . $this->manualEnvelopeExpression($game) . ", {$ring})::geography";
    }

    private function manualEnvelopeExpression(Game $game): string
    {
        if ($game->getBoundarySwLat() !== null) {
            return 'ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326)';
        }

        return 'ST_Buffer(ST_Centroid(ST_GeomFromGeoJSON(:ring))::geography, :fallbackRadius)::geometry';
    }

    /**
     * @return array<string, string|int|float|null>
     */
    private function manualInsertParams(Round $round, RoundMembership $creator, ManualConstraintDraft $draft, string $uuid): array
    {
        $params = [
            'uuid' => $uuid,
            'roundId' => $round->getId(),
            'ring' => $draft->ringGeoJson,
            'label' => $draft->label,
            'mode' => $draft->mode->value,
            'creatorId' => $creator->getId(),
        ];

        if ($draft->mode === ConstraintMode::Exclude) {
            return array_merge($params, $this->manualEnvelopeParams($round->getGame()));
        }

        return $params;
    }

    /**
     * @return array<string, float>
     */
    private function manualEnvelopeParams(Game $game): array
    {
        if ($game->getBoundarySwLat() === null) {
            return ['fallbackRadius' => self::COMPLEMENT_FALLBACK_RADIUS_M];
        }

        return [
            'swLng' => (float) $game->getBoundarySwLng(),
            'swLat' => (float) $game->getBoundarySwLat(),
            'neLng' => (float) $game->getBoundaryNeLng(),
            'neLat' => (float) $game->getBoundaryNeLat(),
        ];
    }

    public function save(PossibleAreaConstraint $constraint, bool $flush = true): void
    {
        $this->getEntityManager()->persist($constraint);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function deleteByRound(Round $round): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(PossibleAreaConstraint::class, 'pac')
            ->where('pac.round = :round')
            ->setParameter('round', $round)
            ->getQuery()
            ->execute();
    }

    public function computePossibleArea(Round $round): ?string
    {
        $conn = $this->getEntityManager()->getConnection();
        // Clip to the play-area polygon so the area hugs the city, not the bbox.
        // PostGIS lacks aggregate intersection, hence the CTE.
        $boundary = $round->getGame()->getBoundaryGeoJson();
        $clipOpen = $boundary !== null ? 'ST_Intersection(' : '';
        $clipClose = $boundary !== null ? ', ST_GeomFromGeoJSON(:boundaryGeoJson))' : '';
        // A step that empties the area is a degenerate constraint, so keep the parent, not EMPTY.
        $step = 'ST_CollectionExtract(ST_Intersection(f.geom, o.geom), 3)';
        $sql = <<<SQL
            WITH RECURSIVE ordered AS (
                SELECT geometry::geometry AS geom,
                       ROW_NUMBER() OVER (ORDER BY created_at, id) AS rn
                FROM possible_area_constraints
                WHERE round_id = :roundId AND enabled = true
            ),
            folded AS (
                SELECT geom, rn FROM ordered WHERE rn = 1
                UNION ALL
                SELECT CASE
                           WHEN ST_IsEmpty({$step}) OR ST_Area({$step}) <= 0 THEN f.geom
                           ELSE {$step}
                       END,
                       o.rn
                FROM folded f
                JOIN ordered o ON o.rn = f.rn + 1
            )
            SELECT ST_AsGeoJSON(g) AS geojson, ST_Area(g) AS area
            FROM (
                SELECT {$clipOpen}geom{$clipClose} AS g
                FROM folded
                ORDER BY rn DESC
                LIMIT 1
            ) clipped
        SQL;

        $params = ['roundId' => $round->getId()];
        if ($boundary !== null) {
            $params['boundaryGeoJson'] = $boundary;
        }

        $result = $conn->fetchAssociative($sql, $params);

        $geojson = $result['geojson'] ?? null;
        $area = $result['area'] ?? null;

        if (!is_string($geojson) || $geojson === '' || !is_numeric($area) || (float) $area <= 0.0) {
            return null;
        }

        return $geojson;
    }

    public function computeExclusion(Round $round): ?string
    {
        $game = $round->getGame();
        $conn = $this->getEntityManager()->getConnection();
        // Expand the envelope each way so the overlay also covers outside the play area.
        $envelope = $game->getBoundarySwLat() !== null
            ? 'ST_Expand(ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326), :expandLng, :expandLat)'
            : $this->fallbackEnvelopeSql();
        $boundary = $game->getBoundaryGeoJson();
        $clipOpen = $boundary !== null ? 'ST_Intersection(' : '';
        $clipClose = $boundary !== null ? ', ST_GeomFromGeoJSON(:boundaryGeoJson))' : '';
        $sql = <<<SQL
            WITH area AS (
                WITH RECURSIVE ordered AS (
                    SELECT geometry::geometry AS geom,
                           ROW_NUMBER() OVER (ORDER BY created_at, id) AS rn
                    FROM possible_area_constraints
                    WHERE round_id = :roundId AND enabled = true
                ),
                folded AS (
                    SELECT geom, rn FROM ordered WHERE rn = 1
                    UNION ALL
                    SELECT ST_Intersection(f.geom, o.geom), o.rn
                    FROM folded f
                    JOIN ordered o ON o.rn = f.rn + 1
                )
                SELECT {$clipOpen}geom{$clipClose} AS geom FROM folded ORDER BY rn DESC LIMIT 1
            )
            SELECT ST_AsGeoJSON(
                CASE WHEN (SELECT geom FROM area) IS NOT NULL
                     THEN ST_Difference($envelope, (SELECT geom FROM area))
                     ELSE $envelope
                END
            ) AS geojson
        SQL;

        $params = ['roundId' => $round->getId()];
        if ($boundary !== null) {
            $params['boundaryGeoJson'] = $boundary;
        }
        if ($game->getBoundarySwLat() !== null) {
            $swLng = (float) $game->getBoundarySwLng();
            $swLat = (float) $game->getBoundarySwLat();
            $neLng = (float) $game->getBoundaryNeLng();
            $neLat = (float) $game->getBoundaryNeLat();
            $params['swLng'] = $swLng;
            $params['swLat'] = $swLat;
            $params['neLng'] = $neLng;
            $params['neLat'] = $neLat;
            $params['expandLng'] = abs($neLng - $swLng);
            $params['expandLat'] = abs($neLat - $swLat);
        }

        $result = $conn->fetchAssociative($sql, $params);
        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : null;
    }

    /**
     * What the seeker's hypothetical constraint would yield, without persisting.
     * Reads only the current possible area and the public game envelope; never
     * touches PlayerLocation.
     *
     * @return array{
     *     constraintGeoJson: ?string,
     *     currentAreaKm2: float,
     *     projectedAreaKm2: float,
     *     currentPossibleAreaGeoJson: ?string,
     *     projectedPossibleAreaGeoJson: ?string,
     *     excludedPossibleAreaGeoJson: ?string,
     * }
     */
    public function computePreviewArea(
        Round $round,
        string $category,
        float $seekerLat,
        float $seekerLng,
        ?float $endLat,
        ?float $endLng,
        ?int $radiusMeters,
        ?string $featureType,
        string $hypotheticalAnswer,
        ?string $hypotheticalFeatureId = null,
        ?int $withinMeters = null,
    ): array {
        $game = $round->getGame();
        $conn = $this->getEntityManager()->getConnection();
        $env = $this->envelopeCorners($game, new Point($seekerLng, $seekerLat));

        $constraintGeoJson = match ($category) {
            'radar' => $this->buildDiskGeoJson(
                $game,
                $seekerLat,
                $seekerLng,
                (float) ($radiusMeters ?? 1000),
                $hypotheticalAnswer === 'inside',
            ),
            'thermometer' => $this->buildHalfPlaneGeoJson(
                $game,
                $seekerLat,
                $seekerLng,
                $endLat ?? $seekerLat,
                $endLng ?? $seekerLng,
                $hypotheticalAnswer === 'hotter',
            ),
            'measuring' => $this->buildMeasuringPreviewGeoJson(
                $game,
                $featureType,
                $seekerLat,
                $seekerLng,
                $hypotheticalAnswer,
            ),
            'matching' => $this->buildMatchingCellGeoJson(
                $game,
                FeatureType::from($featureType ?? ''),
                $hypotheticalFeatureId ?? '',
                $hypotheticalAnswer === 'same',
            ),
            'tentacles' => $hypotheticalAnswer === 'none'
                ? $this->buildDiskGeoJson(
                    $game,
                    $seekerLat,
                    $seekerLng,
                    (float) ($withinMeters ?? 1000),
                    false,
                )
                : $this->buildTentaclesCellGeoJson(
                    $game,
                    FeatureType::from($featureType ?? ''),
                    $hypotheticalFeatureId ?? '',
                    (float) ($withinMeters ?? 1000),
                    $seekerLat,
                    $seekerLng,
                ),
            default => throw new \InvalidArgumentException('Unsupported category'),
        };

        // Prefer the real play-area polygon so the region hugs the boundary, not its bbox.
        $bboxOrSeeker = $game->getBoundarySwLat() !== null
            ? 'ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326)'
            : 'ST_Buffer(ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :fallbackRadius)::geometry';
        $boundaryGeoJson = $game->getBoundaryGeoJson();
        $fallbackExpr = $boundaryGeoJson !== null
            ? "COALESCE(ST_GeomFromGeoJSON(:boundaryGeoJson), $bboxOrSeeker)"
            : $bboxOrSeeker;
        $clipOpen = $boundaryGeoJson !== null ? 'ST_Intersection(' : '';
        $clipClose = $boundaryGeoJson !== null ? ', ST_GeomFromGeoJSON(:boundaryGeoJson))' : '';
        $effectiveAreaExpr = "COALESCE({$clipOpen}(SELECT geom FROM current_area){$clipClose}, $fallbackExpr)";

        $params = [
            'roundId' => $round->getId(),
            'gameId' => $game->getId(),
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
            'lng' => $seekerLng,
            'lat' => $seekerLat,
            'fallbackRadius' => self::COMPLEMENT_FALLBACK_RADIUS_M,
        ];
        if ($boundaryGeoJson !== null) {
            $params['boundaryGeoJson'] = $boundaryGeoJson;
        }

        if ($constraintGeoJson === '') {
            return $this->previewFallbackResult($conn, $params, $effectiveAreaExpr);
        }

        $params['constraintJson'] = $constraintGeoJson;

        $sql = <<<SQL
            WITH current_area AS (
                WITH RECURSIVE ordered AS (
                    SELECT geometry::geometry AS geom,
                           ROW_NUMBER() OVER (ORDER BY created_at, id) AS rn
                    FROM possible_area_constraints
                    WHERE round_id = :roundId AND enabled = true
                ),
                folded AS (
                    SELECT geom, rn FROM ordered WHERE rn = 1
                    UNION ALL
                    SELECT ST_Intersection(f.geom, o.geom), o.rn
                    FROM folded f
                    JOIN ordered o ON o.rn = f.rn + 1
                )
                SELECT geom FROM folded ORDER BY rn DESC LIMIT 1
            ),
            effective_area AS (
                SELECT $effectiveAreaExpr AS geom
            ),
            constraint_geom AS (
                SELECT ST_GeomFromGeoJSON(:constraintJson) AS geom
            )
            SELECT
                COALESCE(
                    ST_Area((SELECT geom FROM effective_area)::geography) / 1e6,
                    0.0
                ) AS current_area_km2,
                COALESCE(
                    ST_Area(
                        ST_Intersection(
                            (SELECT geom FROM effective_area),
                            (SELECT geom FROM constraint_geom)
                        )::geography
                    ) / 1e6,
                    0.0
                ) AS projected_area_km2,
                ST_AsGeoJSON((SELECT geom FROM effective_area)) AS current_geojson,
                ST_AsGeoJSON(
                    ST_Intersection(
                        (SELECT geom FROM effective_area),
                        (SELECT geom FROM constraint_geom)
                    )
                ) AS projected_geojson,
                ST_AsGeoJSON((SELECT geom FROM constraint_geom)) AS constraint_geojson,
                ST_AsGeoJSON(
                    ST_Difference(
                        (SELECT geom FROM effective_area),
                        (SELECT geom FROM constraint_geom)
                    )
                ) AS excluded_geojson
        SQL;

        /** @var array<string, mixed>|false $raw */
        $raw = $conn->fetchAssociative($sql, $params);
        $result = is_array($raw) ? $raw : [];

        return $this->buildPreviewResult($result, $constraintGeoJson);
    }

    private function buildMeasuringPreviewGeoJson(
        Game $game,
        ?string $featureType,
        float $seekerLat,
        float $seekerLng,
        string $hypotheticalAnswer,
    ): string {
        if ($featureType === null) {
            return '';
        }

        $type = FeatureType::tryFrom($featureType);
        if ($type === null) {
            return '';
        }

        $kind = $type->geometryKind();

        if ($kind === 'linear') {
            return $this->buildMeasuringLinearGeoJson(
                $game,
                $type,
                $seekerLat,
                $seekerLng,
                $hypotheticalAnswer === 'closer',
            );
        }

        return $this->buildMeasuringPointGeoJson(
            $game,
            $type,
            $seekerLat,
            $seekerLng,
            $hypotheticalAnswer === 'closer',
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *     constraintGeoJson: ?string,
     *     currentAreaKm2: float,
     *     projectedAreaKm2: float,
     *     currentPossibleAreaGeoJson: ?string,
     *     projectedPossibleAreaGeoJson: ?string,
     *     excludedPossibleAreaGeoJson: ?string,
     * }
     */
    private function previewFallbackResult(\Doctrine\DBAL\Connection $conn, array $params, string $effectiveAreaExpr): array
    {
        $sql = <<<SQL
            WITH current_area AS (
                WITH RECURSIVE ordered AS (
                    SELECT geometry::geometry AS geom,
                           ROW_NUMBER() OVER (ORDER BY created_at, id) AS rn
                    FROM possible_area_constraints
                    WHERE round_id = :roundId AND enabled = true
                ),
                folded AS (
                    SELECT geom, rn FROM ordered WHERE rn = 1
                    UNION ALL
                    SELECT ST_Intersection(f.geom, o.geom), o.rn
                    FROM folded f
                    JOIN ordered o ON o.rn = f.rn + 1
                )
                SELECT geom FROM folded ORDER BY rn DESC LIMIT 1
            ),
            effective_area AS (
                SELECT $effectiveAreaExpr AS geom
            )
            SELECT
                ST_Area((SELECT geom FROM effective_area)::geography) / 1e6 AS current_area_km2,
                ST_AsGeoJSON((SELECT geom FROM effective_area)) AS current_geojson
        SQL;

        /** @var array<string, mixed>|false $raw */
        $raw = $conn->fetchAssociative($sql, $params);
        $result = is_array($raw) ? $raw : [];

        $currentGeojson = $result['current_geojson'] ?? null;
        $currentIsString = is_string($currentGeojson) && $currentGeojson !== '';
        $currentArea = $result['current_area_km2'] ?? null;

        return [
            'constraintGeoJson' => null,
            'currentAreaKm2' => is_numeric($currentArea) ? (float) $currentArea : 0.0,
            'projectedAreaKm2' => is_numeric($currentArea) ? (float) $currentArea : 0.0,
            'currentPossibleAreaGeoJson' => $currentIsString ? $currentGeojson : null,
            'projectedPossibleAreaGeoJson' => $currentIsString ? $currentGeojson : null,
            'excludedPossibleAreaGeoJson' => null,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{
     *     constraintGeoJson: ?string,
     *     currentAreaKm2: float,
     *     projectedAreaKm2: float,
     *     currentPossibleAreaGeoJson: ?string,
     *     projectedPossibleAreaGeoJson: ?string,
     *     excludedPossibleAreaGeoJson: ?string,
     * }
     */
    private function buildPreviewResult(array $result, string $constraintGeoJson): array
    {
        $currentGeojson = $result['current_geojson'] ?? null;
        $projectedGeojson = $result['projected_geojson'] ?? null;
        $constraintGeojson = $result['constraint_geojson'] ?? null;
        $excludedGeojson = $result['excluded_geojson'] ?? null;

        $projectedIsString = is_string($projectedGeojson) && $projectedGeojson !== '';
        $currentIsString = is_string($currentGeojson) && $currentGeojson !== '';
        $constraintIsString = is_string($constraintGeojson) && $constraintGeojson !== '';
        $excludedIsString = is_string($excludedGeojson) && $excludedGeojson !== '';

        $currentArea = $result['current_area_km2'] ?? null;
        $projectedArea = $result['projected_area_km2'] ?? null;

        return [
            'constraintGeoJson' => $constraintIsString ? $constraintGeojson : ($constraintGeoJson ?: null),
            'currentAreaKm2' => is_numeric($currentArea) ? (float) $currentArea : 0.0,
            'projectedAreaKm2' => is_numeric($projectedArea) ? (float) $projectedArea : 0.0,
            'currentPossibleAreaGeoJson' => $currentIsString ? $currentGeojson : null,
            'projectedPossibleAreaGeoJson' => $projectedIsString ? $projectedGeojson : null,
            'excludedPossibleAreaGeoJson' => $excludedIsString ? $excludedGeojson : null,
        ];
    }

    private function fallbackEnvelopeSql(): string
    {
        return 'ST_Buffer(ST_SetSRID(ST_MakePoint(0, 0), 4326)::geography, ' . ((int) self::COMPLEMENT_FALLBACK_RADIUS_M) . ')::geometry';
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertConstraint(Round $round, string $wkt, string $label, ?string $labelKey = null, ?array $labelArgs = null): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            INSERT INTO possible_area_constraints (uuid, round_id, geometry, label, label_key, label_args, created_at)
            VALUES (:uuid, :roundId, ST_GeomFromText(:wkt, 4326), :label, :labelKey, :labelArgs, NOW())
        SQL;

        $conn->executeStatement($sql, [
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'roundId' => $round->getId(),
            'wkt' => $wkt,
            'label' => $label,
            'labelKey' => $labelKey,
            'labelArgs' => $labelArgs !== null ? json_encode($labelArgs) : null,
        ]);
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertConstraintFromGeoJson(Round $round, string $geoJson, string $label, ?string $labelKey = null, ?array $labelArgs = null): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            INSERT INTO possible_area_constraints (uuid, round_id, geometry, label, label_key, label_args, created_at)
            VALUES (:uuid, :roundId, ST_GeomFromGeoJSON(:geoJson)::geography, :label, :labelKey, :labelArgs, NOW())
        SQL;

        $conn->executeStatement($sql, [
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'roundId' => $round->getId(),
            'geoJson' => $geoJson,
            'label' => $label,
            'labelKey' => $labelKey,
            'labelArgs' => $labelArgs !== null ? json_encode($labelArgs) : null,
        ]);
    }

    public function buildDiskGeoJson(
        Game $game,
        float $centerLat,
        float $centerLng,
        float $radiusMeters,
        bool $inside,
    ): string {
        $conn = $this->getEntityManager()->getConnection();

        if ($inside) {
            $sql = <<<'SQL'
                SELECT ST_AsGeoJSON(
                    ST_Buffer(
                        ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                        :radius
                    )::geometry
                ) AS geojson
            SQL;
            $result = $conn->fetchAssociative($sql, [
                'lng' => $centerLng,
                'lat' => $centerLat,
                'radius' => $radiusMeters,
            ]);
        } else {
            $envelopeExpr = $this->boundaryEnvelopeExpression($game);
            $params = $this->diskComplementParams($game, $centerLng, $centerLat, $radiusMeters);
            $sql = <<<SQL
                SELECT ST_AsGeoJSON(
                    ST_Difference(
                        $envelopeExpr,
                        ST_Buffer(
                            ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius
                        )::geometry
                    )
                ) AS geojson
            SQL;
            $result = $conn->fetchAssociative($sql, $params);
        }

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    public function buildHalfPlaneGeoJson(
        Game $game,
        float $startLat,
        float $startLng,
        float $endLat,
        float $endLng,
        bool $hotter,
    ): string {
        $conn = $this->getEntityManager()->getConnection();
        $midLng = ($startLng + $endLng) / 2;
        $midLat = ($startLat + $endLat) / 2;
        $dx = $endLng - $startLng;
        $dy = $endLat - $startLat;

        // 1° lon ≠ 1° lat in meters; project to meter space before rotating.
        $mPerDegLat = 6371008.8 * M_PI / 180.0;
        $mPerDegLng = $mPerDegLat * cos(deg2rad($midLat));
        $dxM = $dx * $mPerDegLng;
        $dyM = $dy * $mPerDegLat;

        $perpLng = -$dyM / $mPerDegLng;
        $perpLat = $dxM / $mPerDegLat;
        $len = max(abs($perpLng), abs($perpLat), 0.0001);
        $scale = 10.0 / $len;
        $b1lng = $midLng - $perpLng * $scale;
        $b1lat = $midLat - $perpLat * $scale;
        $b2lng = $midLng + $perpLng * $scale;
        $b2lat = $midLat + $perpLat * $scale;
        $testLng = $hotter ? $endLng : $startLng;
        $testLat = $hotter ? $endLat : $startLat;
        $env = $this->envelopeCorners($game, new Point($startLng, $startLat));

        $sql = <<<'SQL'
            WITH env AS (
                SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom
            ),
            bisector AS (
                SELECT ST_SetSRID(ST_MakeLine(
                    ST_MakePoint(:b1lng, :b1lat),
                    ST_MakePoint(:b2lng, :b2lat)
                ), 4326) AS geom
            ),
            halves AS (
                SELECT (ST_Dump(ST_Split(env.geom, bisector.geom))).geom AS half
                FROM env, bisector
            )
            SELECT ST_AsGeoJSON(half) AS geojson
            FROM halves
            WHERE ST_Contains(half, ST_SetSRID(ST_MakePoint(:testLng, :testLat), 4326))
            LIMIT 1
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
            'b1lng' => $b1lng,
            'b1lat' => $b1lat,
            'b2lng' => $b2lng,
            'b2lat' => $b2lat,
            'testLng' => $testLng,
            'testLat' => $testLat,
        ]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    public function buildMeasuringPointGeoJson(
        Game $game,
        FeatureType $type,
        float $seekerLat,
        float $seekerLng,
        bool $closer,
    ): string {
        $env = $this->envelopeCorners($game, new Point($seekerLng, $seekerLat));
        $conn = $this->getEntityManager()->getConnection();
        $kept = $closer
            ? 'ST_Intersection(u.geom, env.geom)'
            : 'ST_Difference(env.geom, u.geom)';

        $sql = <<<SQL
            WITH feats AS (
                SELECT COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry) AS g
                FROM features WHERE game_id = :gameId AND feature_type = :ftype
            ),
            ds AS (
                SELECT MIN(ST_Distance(g::geography,
                    ST_SetSRID(ST_MakePoint(:slng, :slat), 4326)::geography)) AS d
                FROM feats
            ),
            u AS (
                SELECT ST_Union(
                    ST_Buffer(f.g::geography, (SELECT d FROM ds))::geometry
                ) AS geom FROM feats f
            ),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom)
            SELECT ST_AsGeoJSON($kept) AS geojson
            FROM u, env WHERE u.geom IS NOT NULL
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => $type->value,
            'slng' => $seekerLng,
            'slat' => $seekerLat,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    public function buildMeasuringLinearGeoJson(
        Game $game,
        FeatureType $type,
        float $seekerLat,
        float $seekerLng,
        bool $closer,
    ): string {
        $env = $this->envelopeCorners($game, new Point($seekerLng, $seekerLat));
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            WITH line AS (
                SELECT ST_Union(ST_SetSRID(geometry::geometry, 4326)) AS g FROM features
                WHERE game_id = :gameId AND feature_type = :ftype AND geometry IS NOT NULL
            ),
            d AS (SELECT ST_Distance((SELECT g FROM line)::geography,
                  ST_SetSRID(ST_MakePoint(:slng, :slat), 4326)::geography) AS m),
            band AS (SELECT ST_Buffer((SELECT g FROM line)::geography, (SELECT m FROM d))::geometry AS geom),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom)
            SELECT ST_AsGeoJSON(
              CASE WHEN :isCloser THEN ST_Intersection(band.geom, env.geom)
                   ELSE ST_Difference(env.geom, band.geom) END
            ) AS geojson
            FROM band, env
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => $type->value,
            'slng' => $seekerLng,
            'slat' => $seekerLat,
            'isCloser' => $closer ? 1 : 0,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    public function buildMatchingCellGeoJson(
        Game $game,
        FeatureType $type,
        string $chosenFeatureUuid,
        bool $same,
    ): string {
        $env = $this->envelopeCorners($game, new Point(0, 0));
        $conn = $this->getEntityManager()->getConnection();

        if ($type->geometryKind() === 'areal' && $this->featureHasGeometry($chosenFeatureUuid)) {
            return $this->buildMatchingArealCellGeoJson($game, $chosenFeatureUuid, $same, $env);
        }

        $utm = $this->utmSridForEnvelope($env);
        $kept = $same
            ? 'ST_Intersection(cell.geom, env.geom)'
            : 'ST_Difference(env.geom, cell.geom)';
        $sql = <<<SQL
            WITH feats AS (
                SELECT ST_Transform(COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry), :utm::int) AS g
                FROM features WHERE game_id = :gameId AND feature_type = :ftype
            ),
            env AS (
                SELECT ST_Transform(ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326), :utm::int) AS geom
            ),
            chosen AS (
                SELECT ST_Transform(COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry), :utm::int) AS g
                FROM features WHERE uuid = :chosenFeatureId
            ),
            vor AS (
                SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(g), 0.0, (SELECT geom FROM env)))).geom AS geom
                FROM feats
            ),
            cell AS (SELECT v.geom FROM vor v, chosen c WHERE ST_Contains(v.geom, c.g) LIMIT 1)
            SELECT ST_AsGeoJSON(ST_Transform($kept, 4326)) AS geojson
            FROM cell, env
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => $type->value,
            'chosenFeatureId' => $chosenFeatureUuid,
            'utm' => $utm,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ], ['utm' => ParameterType::INTEGER]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    /**
     * @param array{swLng: float, swLat: float, neLng: float, neLat: float} $env
     */
    private function buildMatchingArealCellGeoJson(
        Game $game,
        string $chosenFeatureUuid,
        bool $same,
        array $env,
    ): string {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            WITH chosen AS (
                SELECT ST_SetSRID(geometry::geometry, 4326) AS g FROM features
                WHERE uuid = :chosenFeatureId AND game_id = :gameId
            ),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom)
            SELECT ST_AsGeoJSON(
                CASE WHEN :isSame THEN ST_Intersection(chosen.g, env.geom)
                     ELSE ST_Difference(env.geom, chosen.g) END
            ) AS geojson
            FROM chosen, env
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'chosenFeatureId' => $chosenFeatureUuid,
            'gameId' => $game->getId(),
            'isSame' => $same ? 1 : 0,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    public function buildTentaclesCellGeoJson(
        Game $game,
        FeatureType $type,
        string $chosenFeatureUuid,
        float $withinMeters,
        float $seekerLat,
        float $seekerLng,
    ): string {
        $env = $this->envelopeCorners($game, new Point($seekerLng, $seekerLat));
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            WITH feats AS (
                SELECT COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry) AS g
                FROM features WHERE game_id = :gameId AND feature_type = :ftype
            ),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom),
            chosen AS (
                SELECT COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry) AS g
                FROM features WHERE uuid = :chosenFeatureId
            ),
            vor AS (
                SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(g), 0.0, (SELECT geom FROM env)))).geom AS geom
                FROM feats
            ),
            cell AS (SELECT v.geom FROM vor v, chosen c WHERE ST_Contains(v.geom, c.g) LIMIT 1),
            disk AS (
                SELECT ST_Buffer(ST_SetSRID(ST_MakePoint(:slng, :slat), 4326)::geography, :range)::geometry AS geom
            )
            SELECT ST_AsGeoJSON(ST_Intersection(cell.geom, disk.geom)) AS geojson
            FROM cell, disk
        SQL;

        $result = $conn->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => $type->value,
            'chosenFeatureId' => $chosenFeatureUuid,
            'slng' => $seekerLng,
            'slat' => $seekerLat,
            'range' => $withinMeters,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ]);

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertDiskConstraint(
        Round $round,
        Point $point,
        float $radiusMeters,
        bool $inside,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $game = $round->getGame();
        $geoJson = $this->buildDiskGeoJson(
            $game,
            $point->getLatitude(),
            $point->getLongitude(),
            $radiusMeters,
            $inside,
        );

        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    private function boundaryEnvelopeExpression(Game $game): string
    {
        if ($game->getBoundarySwLat() !== null) {
            return 'ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326)';
        }

        return 'ST_Buffer(ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :fallbackRadius)::geometry';
    }

    /**
     * @return array<string, string|int|float>
     */
    private function diskComplementParams(
        Game $game,
        float $centerLng,
        float $centerLat,
        float $radiusMeters,
    ): array {
        $params = [
            'lng' => $centerLng,
            'lat' => $centerLat,
            'radius' => $radiusMeters,
        ];

        if ($game->getBoundarySwLat() !== null) {
            $params['swLng'] = (float) $game->getBoundarySwLng();
            $params['swLat'] = (float) $game->getBoundarySwLat();
            $params['neLng'] = (float) $game->getBoundaryNeLng();
            $params['neLat'] = (float) $game->getBoundaryNeLat();

            return $params;
        }

        $params['fallbackRadius'] = self::COMPLEMENT_FALLBACK_RADIUS_M;

        return $params;
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertHalfPlaneConstraint(
        Round $round,
        Point $start,
        Point $end,
        bool $isHotter,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $geoJson = $this->buildHalfPlaneGeoJson(
            $round->getGame(),
            $start->getLatitude(),
            $start->getLongitude(),
            $end->getLatitude(),
            $end->getLongitude(),
            $isHotter,
        );

        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertMeasuringConstraint(
        Round $round,
        FeatureType $type,
        Point $seeker,
        bool $isCloser,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $geoJson = $this->buildMeasuringPointGeoJson(
            $round->getGame(),
            $type,
            $seeker->getLatitude(),
            $seeker->getLongitude(),
            $isCloser,
        );

        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertMatchingConstraint(
        Round $round,
        FeatureType $type,
        Point $seeker,
        bool $isSame,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $game = $round->getGame();
        $nearestUuid = $this->featureRepository->findNearestFeatureUuid($game, $type, $seeker);
        if ($nearestUuid === null) {
            return;
        }

        $geoJson = $this->buildMatchingCellGeoJson($game, $type, $nearestUuid, $isSame);
        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertStationNameLengthConstraint(
        Round $round,
        FeatureType $type,
        Point $seeker,
        bool $isSame,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $game = $round->getGame();
        $seekerName = ($this->featureRepository->findNearestWithin($game, $type, $seeker, 1)[0] ?? null)?->getName();
        if ($seekerName === null) {
            return;
        }

        $uuids = $this->uuidsWithNameLength($game, $type, mb_strlen(trim($seekerName)));
        $geoJson = $uuids === [] ? '' : $this->buildStationNameLengthUnionGeoJson($game, $type, $uuids, $isSame);
        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    /**
     * @return list<string>
     */
    private function uuidsWithNameLength(Game $game, FeatureType $type, int $length): array
    {
        $matching = [];
        foreach ($this->featureRepository->findNamedUuidsByType($game, $type) as $row) {
            if (mb_strlen(trim($row['name'])) === $length) {
                $matching[] = $row['uuid'];
            }
        }

        return $matching;
    }

    /**
     * @param list<string> $uuids
     */
    private function buildStationNameLengthUnionGeoJson(
        Game $game,
        FeatureType $type,
        array $uuids,
        bool $isSame,
    ): string {
        $env = $this->envelopeCorners($game, new Point(0, 0));
        $utm = $this->utmSridForEnvelope($env);
        $kept = $isSame
            ? "ST_Intersection(COALESCE((SELECT geom FROM uni), "
                . "ST_SetSRID(ST_GeomFromText('POLYGON EMPTY'), 4326)), (SELECT geom FROM env))"
            : "ST_Difference((SELECT geom FROM env), COALESCE((SELECT geom FROM uni), "
                . "ST_SetSRID(ST_GeomFromText('POLYGON EMPTY'), 4326)))";
        $sql = <<<SQL
            WITH feats AS (
                SELECT uuid, ST_Transform(COALESCE(ST_SetSRID(geometry::geometry, 4326), point::geometry), :utm::int) AS g
                FROM features WHERE game_id = :gameId AND feature_type = :ftype
            ),
            env AS (
                SELECT ST_Transform(ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326), :utm::int) AS geom
            ),
            vor AS (
                SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(g), 0.0, (SELECT geom FROM env)))).geom AS geom
                FROM feats
            ),
            matched AS (
                SELECT v.geom FROM vor v JOIN feats f ON ST_Contains(v.geom, f.g)
                WHERE f.uuid IN (:uuids)
            ),
            uni AS (SELECT ST_Union(geom) AS geom FROM matched)
            SELECT ST_AsGeoJSON(ST_Transform($kept, 4326)) AS geojson
        SQL;

        $result = $this->getEntityManager()->getConnection()->fetchAssociative(
            $sql,
            [
                'gameId' => $game->getId(),
                'ftype' => $type->value,
                'utm' => $utm,
                'swLng' => $env['swLng'],
                'swLat' => $env['swLat'],
                'neLng' => $env['neLng'],
                'neLat' => $env['neLat'],
                'uuids' => $uuids,
            ],
            ['utm' => ParameterType::INTEGER, 'uuids' => ArrayParameterType::STRING],
        );

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertTransitLineConstraint(
        Round $round,
        string $ref,
        bool $serves,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $geoJson = $this->buildTransitLineUnionGeoJson($round->getGame(), $ref, $serves);
        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    private function buildTransitLineUnionGeoJson(Game $game, string $ref, bool $serves): string
    {
        $env = $this->envelopeCorners($game, new Point(0, 0));
        $kept = $serves
            ? 'ST_Intersection((SELECT geom FROM uni), (SELECT geom FROM env))'
            : "ST_Difference((SELECT geom FROM env), COALESCE((SELECT geom FROM uni), "
                . "ST_SetSRID(ST_GeomFromText('POLYGON EMPTY'), 4326)))";
        $sql = <<<SQL
            WITH feats AS (
                SELECT point::geometry AS g, line_refs
                FROM game_transit_stations WHERE game_id = :gameId
            ),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom),
            vor AS (
                SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(g), 0.0, (SELECT geom FROM env)))).geom AS geom
                FROM feats
            ),
            matched AS (
                SELECT v.geom FROM vor v JOIN feats f ON ST_Contains(v.geom, f.g)
                WHERE f.line_refs @> to_jsonb(:ref::text)
            ),
            uni AS (SELECT ST_Union(geom) AS geom FROM matched)
            SELECT ST_AsGeoJSON($kept) AS geojson
        SQL;

        $result = $this->getEntityManager()->getConnection()->fetchAssociative(
            $sql,
            [
                'gameId' => $game->getId(),
                'swLng' => $env['swLng'],
                'swLat' => $env['swLat'],
                'neLng' => $env['neLng'],
                'neLat' => $env['neLat'],
                'ref' => $ref,
            ],
        );

        $geojson = $result['geojson'] ?? null;

        return is_string($geojson) && $geojson !== '' ? $geojson : '';
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertTentaclesCellConstraint(
        Round $round,
        FeatureType $type,
        Point $hider,
        Point $seeker,
        float $rangeMeters,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $game = $round->getGame();
        $nearestUuid = $this->featureRepository->findNearestFeatureUuid($game, $type, $hider);
        if ($nearestUuid === null) {
            return;
        }

        $geoJson = $this->buildTentaclesCellGeoJson(
            $game,
            $type,
            $nearestUuid,
            $rangeMeters,
            $seeker->getLatitude(),
            $seeker->getLongitude(),
        );
        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }

    private function featureHasGeometry(string $uuid): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT geometry IS NOT NULL AS has_geom FROM features WHERE uuid = :uuid';
        $result = $conn->fetchAssociative($sql, ['uuid' => $uuid]);

        return (bool) ($result['has_geom'] ?? false);
    }

    /**
     * @return array{swLng: float, swLat: float, neLng: float, neLat: float}
     */
    private function envelopeCorners(Game $game, Point $center): array
    {
        if ($game->getBoundarySwLat() !== null) {
            return [
                'swLng' => (float) $game->getBoundarySwLng(),
                'swLat' => (float) $game->getBoundarySwLat(),
                'neLng' => (float) $game->getBoundaryNeLng(),
                'neLat' => (float) $game->getBoundaryNeLat(),
            ];
        }

        return [
            'swLng' => $center->getLongitude() - self::FALLBACK_ENVELOPE_DEG,
            'swLat' => $center->getLatitude() - self::FALLBACK_ENVELOPE_DEG,
            'neLng' => $center->getLongitude() + self::FALLBACK_ENVELOPE_DEG,
            'neLat' => $center->getLatitude() + self::FALLBACK_ENVELOPE_DEG,
        ];
    }

    /**
     * @param array{swLng: float, swLat: float, neLng: float, neLat: float} $env
     */
    private function utmSridForEnvelope(array $env): int
    {
        $centerLng = ($env['swLng'] + $env['neLng']) / 2.0;
        $centerLat = ($env['swLat'] + $env['neLat']) / 2.0;
        $zone = max(1, min(60, (int) floor(($centerLng + 180.0) / 6.0) + 1));

        return ($centerLat >= 0.0 ? 32600 : 32700) + $zone;
    }

    /**
     * Matching, areal: polygon containment; keep the feature polygon that contains
     * the seeker (same) or its complement within the envelope (different).
     *
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertMatchingArealConstraint(
        Round $round,
        FeatureType $type,
        Point $seeker,
        bool $isSame,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $env = $this->envelopeCorners($round->getGame(), $seeker);
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            WITH feats AS (
                SELECT ST_SetSRID(geometry::geometry, 4326) AS g FROM features
                WHERE game_id = :gameId AND feature_type = :ftype AND geometry IS NOT NULL
            ),
            cell AS (
                SELECT g FROM feats
                WHERE ST_Contains(g, ST_SetSRID(ST_MakePoint(:slng, :slat), 4326)) LIMIT 1
            ),
            env AS (SELECT ST_SetSRID(ST_MakeEnvelope(:swLng, :swLat, :neLng, :neLat), 4326) AS geom)
            INSERT INTO possible_area_constraints (uuid, round_id, geometry, label, label_key, label_args, created_at)
            SELECT :uuid, :roundId,
              (CASE WHEN :isSame THEN ST_Intersection(cell.g, env.geom)
                    ELSE ST_Difference(env.geom, cell.g) END)::geography,
              :label, :labelKey, :labelArgs, NOW()
            FROM cell, env
        SQL;

        $conn->executeStatement($sql, [
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'roundId' => $round->getId(),
            'gameId' => $round->getGame()->getId(),
            'ftype' => $type->value,
            'slng' => $seeker->getLongitude(),
            'slat' => $seeker->getLatitude(),
            'isSame' => $isSame ? 1 : 0,
            'label' => $label,
            'labelKey' => $labelKey,
            'labelArgs' => $labelArgs !== null ? json_encode($labelArgs) : null,
            'swLng' => $env['swLng'],
            'swLat' => $env['swLat'],
            'neLng' => $env['neLng'],
            'neLat' => $env['neLat'],
        ]);
    }

    /**
     * Measuring, linear/level: band within the seeker's distance of the line feature.
     * Closer = inside the buffer band; further = complement within the envelope.
     *
     * @param array<string, string|int>|null $labelArgs
     */
    public function insertMeasuringLinearConstraint(
        Round $round,
        FeatureType $type,
        Point $seeker,
        bool $isCloser,
        string $label,
        ?string $labelKey = null,
        ?array $labelArgs = null,
    ): void {
        $geoJson = $this->buildMeasuringLinearGeoJson(
            $round->getGame(),
            $type,
            $seeker->getLatitude(),
            $seeker->getLongitude(),
            $isCloser,
        );

        if ($geoJson === '') {
            return;
        }

        $this->insertConstraintFromGeoJson($round, $geoJson, $label, $labelKey, $labelArgs);
    }
}
