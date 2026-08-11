<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GameGtfsLine;
use App\Entity\GameTransitLine;
use App\Exception\FunctionalException;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\GtfsSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class GameTransitBuilder
{
    public function __construct(
        private GameTransitLineRepository $gameTransitLines,
        private GameGtfsLineRepository $gameGtfsLines,
        private GtfsSourceRepository $gtfsSources,
        private TransitTilePipeline $transitTilePipeline,
        private TransitStationImporter $transitStationImporter,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $osmLines
     * @param list<array<string, mixed>> $gtfsLines
     */
    public function buildForGame(Game $game, array $osmLines, array $gtfsLines = []): void
    {
        if ($osmLines !== []) {
            $this->persistSelectedLines($game, $osmLines);
        }
        if ($gtfsLines !== []) {
            $this->persistSelectedGtfsLines($game, $gtfsLines);
        }
        $this->buildTransitTiles($game, $osmLines, $gtfsLines);
    }

    /**
     * @param list<array<string, mixed>> $osmLines
     * @param list<array<string, mixed>> $gtfsLines
     */
    private function buildTransitTiles(Game $game, array $osmLines, array $gtfsLines = []): void
    {
        $relationIds = $this->relationIds($osmLines);
        $osmRefs = $this->refsOf($osmLines);

        $gtfsSourceSpecs = [];
        foreach ($gtfsLines as $line) {
            $sourceUuid = $line['gtfsSourceUuid'] ?? '';
            if (!is_string($sourceUuid) || $sourceUuid === '') {
                continue;
            }
            $source = $this->gtfsSources->findByUuid($sourceUuid);
            if ($source === null) {
                continue;
            }
            $ref = $line['ref'] ?? '';
            if (!is_string($ref) || $ref === '') {
                continue;
            }
            if (!isset($gtfsSourceSpecs[$sourceUuid])) {
                $gtfsSourceSpecs[$sourceUuid] = ['path' => $source->getFilePath(), 'selectedRefs' => []];
            }
            $gtfsSourceSpecs[$sourceUuid]['selectedRefs'][] = $ref;
        }

        try {
            $built = $this->transitTilePipeline->build(
                $game->getUuid(),
                $relationIds,
                array_values($gtfsSourceSpecs),
                $osmRefs,
                $game->getBoundaryGeoJson(),
            );
        } catch (\RuntimeException $e) {
            $this->failTileBuild($game, $e);
        }

        if ($built === 0) {
            $this->failTileBuild($game);
        }

        $game->setTransitTilesPath($this->tilesBaseDir . '/' . $game->getUuid());
        $this->transitStationImporter->importForGame($game);
    }

    private function failTileBuild(Game $game, ?\Throwable $previous = null): never
    {
        // The rolled-back transaction takes the rows with it; half-written tiles would outlive them.
        $this->filesystem->remove($this->tilesBaseDir . '/' . $game->getUuid());

        throw new FunctionalException(
            message: 'Could not generate the transit map for the selected lines.',
            errorKey: 'game.transit_tiles_failed',
            previous: $previous,
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<string>
     */
    private function refsOf(array $lines): array
    {
        $refs = [];
        foreach ($lines as $line) {
            $ref = $line['ref'] ?? '';
            if (is_string($ref) && $ref !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return int[]
     */
    private function relationIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $osmType = isset($line['osmType']) && is_string($line['osmType']) ? $line['osmType'] : '';
            $osmId = isset($line['osmId']) && (is_int($line['osmId']) || is_string($line['osmId']))
                ? (int) $line['osmId'] : 0;
            if ($osmType === 'relation' && $osmId > 0) {
                $ids[] = $osmId;
            }
        }

        return $ids;
    }

    /**
     * OSM tags are free-form and can exceed the column widths, so every value is clamped on the way in.
     *
     * @param array<string, mixed> $line
     */
    private static function lineText(array $line, string $key, int $max, string $default = ''): string
    {
        $value = $line[$key] ?? null;

        return mb_substr(is_string($value) && $value !== '' ? $value : $default, 0, $max);
    }

    /** @param list<array<string, mixed>> $lines */
    private function persistSelectedLines(Game $game, array $lines): void
    {
        foreach ($lines as $line) {
            $osmType = self::lineText($line, 'osmType', 16, 'relation');
            $osmId = isset($line['osmId']) && (is_int($line['osmId']) || is_string($line['osmId']))
                ? (int) $line['osmId'] : 0;
            $ref = self::lineText($line, 'ref', 50);
            $name = self::lineText($line, 'name', 200);
            $routeType = self::lineText($line, 'routeType', 30);
            $network = self::lineText($line, 'network', 255);
            $colour = self::lineText($line, 'colour', 20) ?: null;
            $operator = self::lineText($line, 'operator', 255) ?: null;

            $entity = new GameTransitLine(
                $game,
                $osmType,
                $osmId,
                $ref,
                $name,
                $routeType,
                $network,
                $colour,
                $operator,
            );
            $this->gameTransitLines->save($entity, false);
        }
        $this->entityManager->flush();
    }

    /** @param list<array<string, mixed>> $lines */
    private function persistSelectedGtfsLines(Game $game, array $lines): void
    {
        foreach ($lines as $line) {
            $sourceUuid = isset($line['gtfsSourceUuid']) && is_string($line['gtfsSourceUuid'])
                ? $line['gtfsSourceUuid'] : '';
            $source = $this->gtfsSources->findByUuid($sourceUuid);
            if ($source === null) {
                continue;
            }
            if ($source->getGame() === null) {
                $source->setGame($game);
                $this->gtfsSources->save($source, false);
            }

            $routeId = self::lineText($line, 'routeId', 200);
            $ref = self::lineText($line, 'ref', 50);
            $name = self::lineText($line, 'name', 200);
            $routeType = self::lineText($line, 'routeType', 30);
            $network = self::lineText($line, 'network', 255);
            $colour = self::lineText($line, 'colour', 20) ?: null;
            $operator = self::lineText($line, 'operator', 255) ?: null;

            $entity = new GameGtfsLine($game, $source, $routeId, $ref, $name, $routeType, $network, $colour, $operator);
            $this->gameGtfsLines->save($entity, false);
        }
        $this->entityManager->flush();
    }
}
