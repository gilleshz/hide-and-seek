<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

final readonly class BoundaryGeometryRepository
{
    private const float SIMPLIFY_DEGREES = 0.0001;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function roundedConvexHull(string $geoJson, float $bufferMeters, float $roundMeters): ?string
    {
        $conn = $this->entityManager->getConnection();
        $sql = <<<'SQL'
            SELECT ST_AsGeoJSON(
                ST_SimplifyPreserveTopology(
                    ST_Multi(
                        ST_Buffer(
                            ST_Buffer(
                                ST_Buffer(ST_ConvexHull(ST_GeomFromGeoJSON(:geoJson))::geography, :bufferMeters),
                                :negativeRoundMeters
                            ),
                            :roundMeters
                        )::geometry
                    ),
                    :simplifyDegrees
                )
            )
        SQL;
        $result = $conn->fetchOne($sql, [
            'geoJson' => $geoJson,
            'bufferMeters' => $bufferMeters,
            'negativeRoundMeters' => -$roundMeters,
            'roundMeters' => $roundMeters,
            'simplifyDegrees' => self::SIMPLIFY_DEGREES,
        ]);

        return is_string($result) && $result !== '' ? $result : null;
    }
}
