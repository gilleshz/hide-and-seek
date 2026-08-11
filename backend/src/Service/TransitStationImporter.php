<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GameTransitStation;
use App\Repository\GameTransitStationRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

/** Persists LOOM transit stations parsed from the overlay.geojson the tile pipeline wrote to disk. */
final readonly class TransitStationImporter
{
    public function __construct(
        private TransitTileService $tiles,
        private GameTransitStationRepository $stations,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function importForGame(Game $game): int
    {
        $features = $this->readStationFeatures($game);
        $count = 0;
        foreach ($features as $feature) {
            if ($this->persistStation($game, $feature)) {
                $count++;
            }
        }
        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /** @return list<mixed> */
    private function readStationFeatures(Game $game): array
    {
        $path = $this->tiles->resolveOverlayPath($game->getUuid());
        $raw = $path !== null ? file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $features = is_array($data) ? ($data['features'] ?? null) : null;

        return is_array($features) ? array_values($features) : [];
    }

    private function persistStation(Game $game, mixed $feature): bool
    {
        $props = is_array($feature) ? ($feature['properties'] ?? null) : null;
        if (!is_array($props)) {
            return false;
        }
        $stationId = $props['stationId'] ?? null;
        $point = $this->resolvePoint($props, $feature['geometry'] ?? null);
        if (!is_string($stationId) || $stationId === '' || $point === null) {
            return false;
        }

        $station = new GameTransitStation(
            $game,
            $stationId,
            $this->resolveName($props),
            $point,
            $this->resolveLineRefs($props),
        );
        $this->stations->save($station, false);

        return true;
    }

    /** @param array<array-key, mixed> $props */
    private function resolvePoint(array $props, mixed $geometry): ?Point
    {
        $lng = $props['stationLng'] ?? null;
        $lat = $props['stationLat'] ?? null;
        if (is_numeric($lng) && is_numeric($lat)) {
            return new Point((float) $lng, (float) $lat);
        }

        return $this->centroid($geometry);
    }

    private function centroid(mixed $geometry): ?Point
    {
        $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
        $ring = is_array($coordinates) ? ($coordinates[0] ?? null) : null;
        if (!is_array($ring)) {
            return null;
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        $n = 0;
        foreach ($ring as $vertex) {
            if (is_array($vertex) && is_numeric($vertex[0] ?? null) && is_numeric($vertex[1] ?? null)) {
                $sumLng += (float) $vertex[0];
                $sumLat += (float) $vertex[1];
                $n++;
            }
        }

        return $n > 0 ? new Point($sumLng / $n, $sumLat / $n) : null;
    }

    /** @param array<array-key, mixed> $props */
    private function resolveName(array $props): ?string
    {
        $label = $props['stationLabel'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * @param array<array-key, mixed> $props
     * @return list<string>
     */
    private function resolveLineRefs(array $props): array
    {
        $lines = $props['lines'] ?? null;
        if (!is_array($lines)) {
            return [];
        }

        $refs = [];
        foreach ($lines as $line) {
            $ref = is_array($line) ? ($line['ref'] ?? null) : null;
            if (is_string($ref) && $ref !== '') {
                $refs[$ref] = true;
            }
        }

        return array_keys($refs);
    }
}
