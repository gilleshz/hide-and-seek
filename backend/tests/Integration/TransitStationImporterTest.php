<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Service\TransitStationImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransitStationImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TransitStationImporter $importer;
    private string $overlayDir = '';
    private string $tilesBaseDir = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $importer = $container->get(TransitStationImporter::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(TransitStationImporter::class, $importer);
        $this->em = $em;
        $this->importer = $importer;
        $tilesBaseDir = $container->getParameter('app.tiles_dir');
        self::assertIsString($tilesBaseDir);
        $this->tilesBaseDir = $tilesBaseDir;
    }

    protected function tearDown(): void
    {
        if ($this->overlayDir !== '' && is_dir($this->overlayDir)) {
            @unlink($this->overlayDir . '/overlay.geojson');
            @rmdir($this->overlayDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function itPersistsStationsWithCoordsAndServingLineRefs(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $this->em->persist($game);
        $this->em->flush();
        $this->writeOverlay($game->getUuid());

        $count = $this->importer->importForGame($game);
        self::assertSame(2, $count);

        $stations = $this->em->getRepository(GameTransitStation::class)->findBy(['game' => $game]);
        $byName = [];
        foreach ($stations as $station) {
            $byName[$station->getName() ?? ''] = $station;
        }

        self::assertArrayHasKey('Alpha', $byName);
        self::assertSame(['S1', 'S2'], $byName['Alpha']->getLineRefs());
        self::assertEqualsWithDelta(13.40, $byName['Alpha']->getPoint()->getLongitude(), 0.001);

        // Beta has no explicit coords, so the polygon centroid is used.
        self::assertArrayHasKey('Beta', $byName);
        self::assertSame(['U9'], $byName['Beta']->getLineRefs());
        self::assertEqualsWithDelta(13.50, $byName['Beta']->getPoint()->getLongitude(), 0.001);
        self::assertEqualsWithDelta(52.60, $byName['Beta']->getPoint()->getLatitude(), 0.001);
    }

    private function writeOverlay(string $gameUuid): void
    {
        $this->overlayDir = $this->tilesBaseDir . '/' . $gameUuid;
        if (!is_dir($this->overlayDir)) {
            mkdir($this->overlayDir, 0o755, true);
        }

        $overlay = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => [
                        'stationId' => 'node/1',
                        'stationLabel' => 'Alpha',
                        'stationLng' => 13.40,
                        'stationLat' => 52.50,
                        'lines' => [['ref' => 'S1', 'color' => 'f00'], ['ref' => 'S2', 'color' => '0f0']],
                    ],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[13.39, 52.49], [13.41, 52.51]]]],
                ],
                [
                    'type' => 'Feature',
                    'properties' => [
                        'stationId' => 'node/2',
                        'stationLabel' => 'Beta',
                        'lines' => [['ref' => 'U9', 'color' => '00f']],
                    ],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[13.49, 52.59], [13.51, 52.59], [13.51, 52.61], [13.49, 52.61]]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['ref' => 'S1', 'lineColor' => 'f00'],
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[13.39, 52.49], [13.51, 52.61]]],
                ],
            ],
        ];

        file_put_contents($this->overlayDir . '/overlay.geojson', json_encode($overlay));
    }
}
