<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\ManualConstraintDraft;
use App\Entity\Feature;
use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ConstraintMode;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Repository\PossibleAreaConstraintRepository;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PossibleAreaConstraintRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PossibleAreaConstraintRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(PossibleAreaConstraintRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(PossibleAreaConstraintRepository::class, $repository);
        $this->em = $em;
        $this->repository = $repository;
    }

    #[Test]
    public function aYesRadarDiskAndAnEmptyRoundBehaveCorrectly(): void
    {
        $round = $this->persistRound();

        self::assertNull($this->repository->computePossibleArea($round));

        $this->repository->insertDiskConstraint($round, new Point(13.405, 52.52), 1000.0, true, 'Radar yes');

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertStringContainsString('Polygon', $geojson);
    }

    #[Test]
    public function aNoRadarKeepsTheComplementSoTheSeekerPointStaysExcluded(): void
    {
        $round = $this->persistRound();
        $seeker = new Point(13.405, 52.52);

        $this->repository->insertDiskConstraint($round, $seeker, 1000.0, false, 'Radar no');

        self::assertFalse($this->pointIsInsidePossibleArea($round, $seeker));
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.5, 52.6)));
    }

    #[Test]
    public function twoDisjointYesDisksNeverCollapseToAnEmptyArea(): void
    {
        $round = $this->persistRound();
        // Two "yes" disks the hider cannot both satisfy: their raw intersection is EMPTY.
        $this->repository->insertDiskConstraint($round, new Point(13.0, 52.0), 500.0, true, 'A');
        $this->repository->insertDiskConstraint($round, new Point(20.0, 40.0), 500.0, true, 'B');

        $geojson = $this->repository->computePossibleArea($round);

        self::assertNotSame('', (string) $geojson);
        if ($geojson !== null) {
            self::assertGreaterThan(0.0, $this->areaOfGeoJson($geojson));
        }
    }

    #[Test]
    public function intersectingAYesDiskWithAThermometerHalfPlaneFolds(): void
    {
        $round = $this->persistRound();

        $this->repository->insertDiskConstraint($round, new Point(0.0, 0.0), 50000.0, true, 'Radar yes');
        $this->repository->insertHalfPlaneConstraint(
            $round,
            new Point(0.0, 0.0),
            new Point(0.1, 0.0),
            true,
            'Thermometer hotter',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
    }

    #[Test]
    public function matchingDifferentKeepsTheHiderOutsideTheSeekersCell(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        // Seeker's nearest park is west, hider's is east: a "different" answer.
        $this->persistFeature($game, FeatureType::Park, 'West Park', 13.3600, 52.5145);
        $this->persistFeature($game, FeatureType::Park, 'East Park', 13.4100, 52.5230);

        $this->repository->insertMatchingConstraint($round, FeatureType::Park, new Point(13.3777, 52.5163), false, 'diff');

        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.4132, 52.5216)));
    }

    #[Test]
    public function matchingSameKeepsTheHiderWhoseGeodesicNearestMatchesAtMidLatitude(): void
    {
        // At ~48.85N a planar Voronoi cell is skewed: the hider is planar-nearest
        // to East but geodesic-nearest to West, so only a metric Voronoi retains him.
        $round = $this->persistParisBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Park, 'West Park', 2.30, 48.80);
        $this->persistFeature($game, FeatureType::Park, 'East Park', 2.40, 48.90);

        $hider = new Point(2.40, 48.815);
        self::assertSame(
            $this->findNearestFeatureUuidGeodesic($game, FeatureType::Park, $hider),
            $this->findNearestFeatureUuidGeodesic($game, FeatureType::Park, new Point(2.30, 48.80)),
        );

        $this->repository->insertMatchingConstraint($round, FeatureType::Park, new Point(2.30, 48.80), true, 'same');

        self::assertTrue($this->pointIsInsidePossibleArea($round, $hider));
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(2.40, 48.90)));
    }

    #[Test]
    public function stationNameLengthSameRetainsAMidLatitudeHiderOnTheMetricSide(): void
    {
        $round = $this->persistParisBoundedRound();
        $game = $round->getGame();
        // Gamma (5 chars) is the seeker's nearest; Zoo (3 chars) has a different length.
        $this->persistFeature($game, FeatureType::TransitStation, 'Gamma', 2.30, 48.80);
        $this->persistFeature($game, FeatureType::TransitStation, 'Zoo', 2.40, 48.90);

        $this->repository->insertStationNameLengthConstraint(
            $round,
            FeatureType::TransitStation,
            new Point(2.30, 48.80),
            true,
            'same',
        );

        // Hider is planar-nearest to Zoo (3) but geodesic-nearest to Gamma (5, the
        // seeker's length): the metric Voronoi keeps Gamma's cell around the hider.
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(2.40, 48.815)));
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(2.40, 48.90)));
    }

    #[Test]
    public function stationNameLengthSameKeepsAHiderNearAnEqualLengthStationInside(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::TransitStation, 'Alpha', 13.30, 52.50);
        $this->persistFeature($game, FeatureType::TransitStation, 'Gamma', 13.50, 52.55);
        $this->persistFeature($game, FeatureType::TransitStation, 'Zoo', 13.25, 52.62);

        // Seeker's nearest is Alpha (5 chars); a "same" answer keeps every 5-char station's cell.
        $this->repository->insertStationNameLengthConstraint(
            $round,
            FeatureType::TransitStation,
            new Point(13.305, 52.501),
            true,
            'same',
        );

        // Hider's nearest is Gamma (also 5 chars), so it stays inside.
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.499, 52.549)));
        // A point whose nearest is Zoo (3 chars) must be excluded.
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.251, 52.62)));
    }

    #[Test]
    public function stationNameLengthDifferentKeepsAHiderNearADifferentLengthStationInside(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::TransitStation, 'Alpha', 13.30, 52.50);
        $this->persistFeature($game, FeatureType::TransitStation, 'Gamma', 13.50, 52.55);
        $this->persistFeature($game, FeatureType::TransitStation, 'Zoo', 13.25, 52.62);

        // Seeker's nearest is Alpha (5 chars); a "different" answer keeps the complement.
        $this->repository->insertStationNameLengthConstraint(
            $round,
            FeatureType::TransitStation,
            new Point(13.305, 52.501),
            false,
            'different',
        );

        // Hider's nearest is Zoo (3 chars, different length), so it stays inside the complement.
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.251, 52.62)));
        // Hider's nearest is Gamma (5 chars, same length), so it is excluded.
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.499, 52.549)));
    }

    #[Test]
    public function transitLineServesKeepsAHiderNearAServingStationInside(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistTransitStation($game, 'Alpha', 13.30, 52.50, ['S1']);
        $this->persistTransitStation($game, 'Gamma', 13.50, 52.55, ['S1']);
        $this->persistTransitStation($game, 'Zoo', 13.25, 52.62, ['S9']);

        // The ridden line S1 stops at the hider's nearest station; keep the union of S1's cells.
        $this->repository->insertTransitLineConstraint($round, 'S1', true, 'stops');

        // Hider's nearest is Gamma (served by S1), so it stays inside.
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.499, 52.549)));
        // A point whose nearest is Zoo (not served by S1) is excluded.
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.251, 52.62)));
    }

    #[Test]
    public function transitLineDoesNotStopKeepsAHiderNearANonServingStationInside(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistTransitStation($game, 'Alpha', 13.30, 52.50, ['S1']);
        $this->persistTransitStation($game, 'Gamma', 13.50, 52.55, ['S1']);
        $this->persistTransitStation($game, 'Zoo', 13.25, 52.62, ['S9']);

        // The ridden line S1 does not stop at the hider's nearest station; keep the complement.
        $this->repository->insertTransitLineConstraint($round, 'S1', false, 'does not stop');

        // Hider's nearest is Zoo (not served by S1), so it stays inside the complement.
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.251, 52.62)));
        // Hider's nearest is Gamma (served by S1) is excluded.
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.499, 52.549)));
    }

    #[Test]
    public function tentaclesCellKeepsTheHiderInsideEvenWithFewFeatures(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Altes Museum', 13.3980, 52.5190);
        $this->persistFeature($game, FeatureType::Museum, 'Bode Museum', 13.3947, 52.5217);

        $hider = new Point(13.4132, 52.5216);
        $this->repository->insertTentaclesCellConstraint(
            $round,
            FeatureType::Museum,
            $hider,
            new Point(13.3777, 52.5163),
            5000.0,
            'Tentacles',
        );

        self::assertTrue($this->pointIsInsidePossibleArea($round, $hider));
    }

    #[Test]
    public function measuringCloserWithDisjointFeaturesPersistsAsMultiPolygon(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $seeker = new Point(13.35, 52.50);
        $this->repository->insertMeasuringConstraint(
            $round,
            FeatureType::Museum,
            $seeker,
            true,
            'closer',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertStringContainsString('MultiPolygon', $geojson);
    }

    #[Test]
    public function measuringFurtherKeepsTheHiderInTheComplementOfTheSeekersDisk(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Hospital, 'Charite', 13.3760, 52.5170);
        $this->persistFeature($game, FeatureType::Hospital, 'Vivantes', 13.4200, 52.5250);

        // Seeker is metres from Charite; the hider, far from any hospital, is "further".
        $this->repository->insertMeasuringConstraint(
            $round,
            FeatureType::Hospital,
            new Point(13.3777, 52.5163),
            false,
            'further',
        );

        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.4132, 52.5216)));
    }

    #[Test]
    public function matchingDifferentWithDisjointFeaturesPersistsConstraint(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $seeker = new Point(13.35, 52.50);
        $nearestUuid = $this->findNearestFeatureUuid($game, FeatureType::Museum, $seeker);
        self::assertNotNull($nearestUuid);

        $this->repository->insertMatchingConstraint(
            $round,
            FeatureType::Museum,
            $seeker,
            false,
            'different',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertNotEmpty($geojson);
    }

    #[Test]
    public function matchingSameWithDisjointFeaturesPersistsAsPolygon(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $seeker = new Point(13.35, 52.50);
        $nearestUuid = $this->findNearestFeatureUuid($game, FeatureType::Museum, $seeker);
        self::assertNotNull($nearestUuid);

        $this->repository->insertMatchingConstraint(
            $round,
            FeatureType::Museum,
            $seeker,
            true,
            'same',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertStringContainsString('Polygon', $geojson);
    }

    #[Test]
    public function tentaclesCellWithDisjointFeaturesPersistsAsMultiPolygon(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $hider = new Point(13.41, 52.52);
        $this->repository->insertTentaclesCellConstraint(
            $round,
            FeatureType::Museum,
            $hider,
            new Point(13.35, 52.50),
            5000.0,
            'Tentacles',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertNotEmpty($geojson);
    }

    #[Test]
    public function matchingWithArealFeaturePersistsCorrectly(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeatureWithGeometry(
            $game,
            FeatureType::AdminBoundary1st,
            'Region A',
            13.35,
            52.50,
            'POLYGON((13.30 52.45,13.40 52.45,13.40 52.55,13.30 52.55,13.30 52.45))',
        );

        $this->repository->insertMatchingArealConstraint(
            $round,
            FeatureType::AdminBoundary1st,
            new Point(13.35, 52.50),
            true,
            'areal same',
        );

        $geojson = $this->repository->computePossibleArea($round);
        self::assertIsString($geojson);
        self::assertStringContainsString('Polygon', $geojson);
    }

    private const string MANUAL_RING = '{"type":"Polygon","coordinates":'
        . '[[[13.38,52.50],[13.42,52.50],[13.42,52.54],[13.38,52.54],[13.38,52.50]]]}';

    #[Test]
    public function anIncludeManualConstraintNarrowsThePossibleAreaToTheDrawnRing(): void
    {
        $round = $this->persistBoundedRound();
        $creator = $this->persistCreator($round);

        $this->repository->insertManualConstraint(
            $round,
            $creator,
            new ManualConstraintDraft(self::MANUAL_RING, ConstraintMode::Include, 'Include'),
        );

        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.40, 52.52)));
        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.30, 52.45)));
    }

    #[Test]
    public function anExcludeManualConstraintRemovesTheDrawnRegion(): void
    {
        $round = $this->persistBoundedRound();
        $creator = $this->persistCreator($round);

        $this->repository->insertManualConstraint(
            $round,
            $creator,
            new ManualConstraintDraft(self::MANUAL_RING, ConstraintMode::Exclude, 'Exclude'),
        );

        self::assertFalse($this->pointIsInsidePossibleArea($round, new Point(13.40, 52.52)));
        self::assertTrue($this->pointIsInsidePossibleArea($round, new Point(13.30, 52.45)));
    }

    #[Test]
    public function removingAManualConstraintRevertsTheFold(): void
    {
        $round = $this->persistBoundedRound();
        $creator = $this->persistCreator($round);
        $hole = new Point(13.40, 52.52);

        // A wide baseline disk keeps the whole play area possible before the exclude is applied.
        $this->repository->insertDiskConstraint($round, $hole, 30000.0, true, 'base');
        $this->repository->insertManualConstraint(
            $round,
            $creator,
            new ManualConstraintDraft(self::MANUAL_RING, ConstraintMode::Exclude, 'Exclude'),
        );
        self::assertFalse($this->pointIsInsidePossibleArea($round, $hole));

        $manual = $this->repository->findManualByRound($round);
        self::assertCount(1, $manual);

        $this->repository->remove($manual[0]);

        self::assertTrue($this->pointIsInsidePossibleArea($round, $hole));
    }

    #[Test]
    public function aSelfIntersectingRingIsRejected(): void
    {
        $round = $this->persistBoundedRound();
        $creator = $this->persistCreator($round);
        $bowtie = '{"type":"Polygon","coordinates":[[[13.38,52.50],[13.42,52.54],[13.42,52.50],[13.38,52.54],[13.38,52.50]]]}';

        $this->expectException(FunctionalException::class);
        $this->repository->insertManualConstraint(
            $round,
            $creator,
            new ManualConstraintDraft($bowtie, ConstraintMode::Include, 'Bad'),
        );
    }

    #[Test]
    public function aNonPolygonGeometryIsRejected(): void
    {
        $round = $this->persistBoundedRound();
        $creator = $this->persistCreator($round);

        $this->expectException(FunctionalException::class);
        $this->repository->insertManualConstraint(
            $round,
            $creator,
            new ManualConstraintDraft('{"type":"Point","coordinates":[13.40,52.52]}', ConstraintMode::Include, 'Bad'),
        );
    }

    private function persistCreator(Round $round): RoundMembership
    {
        $account = AccountFactory::create('Seeker ' . bin2hex(random_bytes(4)), 'test-password');
        $player = new Player($round->getGame(), $account);
        $membership = new RoundMembership($round, $player, Side::Seeker);
        $this->em->persist($account);
        $this->em->persist($player);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    /**
     * @return array{constraintGeoJson: ?string, currentAreaKm2: float, projectedAreaKm2: float, currentPossibleAreaGeoJson: ?string, projectedPossibleAreaGeoJson: ?string}
     */
    private function previewMatching(Round $round, string $featureType, string $featureId, bool $same): array
    {
        return $this->repository->computePreviewArea(
            $round,
            'matching',
            52.52,
            13.405,
            null,
            null,
            null,
            $featureType,
            $same ? 'same' : 'different',
            $featureId,
            null,
        );
    }

    #[Test]
    public function matchingPreviewSameReturnsCellForChosenFeature(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $nearestUuid = $this->findNearestFeatureUuid($game, FeatureType::Museum, new Point(13.21, 52.60));
        self::assertNotNull($nearestUuid);

        $result = $this->previewMatching($round, 'museum', $nearestUuid, true);
        self::assertIsString($result['constraintGeoJson']);
        self::assertNotEmpty($result['constraintGeoJson']);
        self::assertGreaterThan(0.0, $result['currentAreaKm2']);
    }

    #[Test]
    public function matchingPreviewDifferentProducesComplement(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $nearestUuid = $this->findNearestFeatureUuid($game, FeatureType::Museum, new Point(13.21, 52.60));
        self::assertNotNull($nearestUuid);

        $result = $this->previewMatching($round, 'museum', $nearestUuid, false);
        self::assertIsString($result['constraintGeoJson']);
        self::assertNotEmpty($result['constraintGeoJson']);
        self::assertGreaterThan(0.0, $result['projectedAreaKm2']);
    }

    #[Test]
    public function tentaclesPreviewNoneReturnsDiskComplement(): void
    {
        $round = $this->persistBoundedRound();

        $result = $this->repository->computePreviewArea(
            $round,
            'tentacles',
            52.50,
            13.35,
            null,
            null,
            null,
            'museum',
            'none',
            null,
            2000,
        );

        self::assertIsString($result['constraintGeoJson']);
        self::assertNotEmpty($result['constraintGeoJson']);
        self::assertGreaterThan(0.0, $result['currentAreaKm2']);
        self::assertGreaterThan(0.0, $result['projectedAreaKm2']);
        self::assertNotNull($result['projectedPossibleAreaGeoJson']);
    }

    #[Test]
    public function tentaclesPreviewWithFeatureReturnsClippedCell(): void
    {
        $round = $this->persistBoundedRound();
        $game = $round->getGame();
        $this->persistFeature($game, FeatureType::Museum, 'Museum A', 13.20, 52.60);
        $this->persistFeature($game, FeatureType::Museum, 'Museum B', 13.50, 52.40);

        $nearestUuid = $this->findNearestFeatureUuid($game, FeatureType::Museum, new Point(13.35, 52.50));
        self::assertNotNull($nearestUuid);

        $result = $this->repository->computePreviewArea(
            $round,
            'tentacles',
            52.50,
            13.35,
            null,
            null,
            null,
            'museum',
            'nearest',
            $nearestUuid,
            5000,
        );

        self::assertIsString($result['constraintGeoJson']);
        self::assertNotEmpty($result['constraintGeoJson']);
    }

    private function findNearestFeatureUuid(Game $game, FeatureType $type, Point $point): ?string
    {
        $conn = $this->em->getConnection();
        $sql = <<<'SQL'
            SELECT f.uuid FROM features f
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

    private function findNearestFeatureUuidGeodesic(Game $game, FeatureType $type, Point $point): ?string
    {
        $conn = $this->em->getConnection();
        $sql = <<<'SQL'
            SELECT f.uuid FROM features f
            WHERE f.game_id = :gameId AND f.feature_type = :featureType
            ORDER BY ST_Distance(
                COALESCE(ST_GeomFromText(f.geometry::text, 4326)::geography, f.point::geography),
                ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            )
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

    private function persistRound(): Round
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->flush();

        return $round;
    }

    private function persistBoundedRound(): Round
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $game->setBoundary(52.40, 13.20, 52.65, 13.60);
        $round = new Round($game);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->flush();

        return $round;
    }

    private function persistParisBoundedRound(): Round
    {
        $game = new Game('Paris', GameSize::Large, Edition::Metric);
        $game->setBoundary(48.75, 2.20, 48.95, 2.50);
        $round = new Round($game);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->flush();

        return $round;
    }

    private function persistFeature(Game $game, FeatureType $type, string $name, float $lng, float $lat): void
    {
        $this->em->persist(new Feature($game, $type, $name, new Point($lng, $lat)));
        $this->em->flush();
    }

    /** @param list<string> $lineRefs */
    private function persistTransitStation(Game $game, string $name, float $lng, float $lat, array $lineRefs): void
    {
        $this->em->persist(new GameTransitStation($game, $name, $name, new Point($lng, $lat), $lineRefs));
        $this->em->flush();
    }

    private function persistFeatureWithGeometry(
        Game $game,
        FeatureType $type,
        string $name,
        float $lng,
        float $lat,
        string $geometry,
    ): void {
        $this->em->persist(new Feature($game, $type, $name, new Point($lng, $lat), $geometry));
        $this->em->flush();
    }

    private function areaOfGeoJson(string $geojson): float
    {
        $conn = $this->em->getConnection();
        $result = $conn->fetchAssociative(
            'SELECT ST_Area(ST_GeomFromGeoJSON(:g)) AS a',
            ['g' => $geojson],
        );

        $area = $result['a'] ?? null;

        return is_numeric($area) ? (float) $area : 0.0;
    }

    private function pointIsInsidePossibleArea(Round $round, Point $point): bool
    {
        $geojson = $this->repository->computePossibleArea($round);
        if ($geojson === null) {
            return false;
        }

        $conn = $this->em->getConnection();
        $sql = 'SELECT ST_Contains(ST_GeomFromGeoJSON(:g), ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)) AS c';
        $result = $conn->fetchAssociative($sql, [
            'g' => $geojson,
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
        ]);

        return (bool) ($result['c'] ?? false);
    }
}
