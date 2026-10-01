<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ResolvedTransitLine;
use App\Entity\Game;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameTransitLineRepository;

/** A game's lines live in two tables: routes picked through OSM discovery, and routes picked from an uploaded GTFS feed. */
readonly class TransitLineResolver
{
    public function __construct(
        private GameTransitLineRepository $osmLines,
        private GameGtfsLineRepository $gtfsLines,
    ) {
    }

    public function resolveByUuid(Game $game, string $uuid): ?ResolvedTransitLine
    {
        $osm = $this->osmLines->findOneByGameAndUuid($game, $uuid);
        if ($osm !== null) {
            return $this->describe($osm->getUuid(), $osm->getRef(), $osm->getName());
        }

        $gtfs = $this->gtfsLines->findOneByGameAndUuid($game, $uuid);

        return $gtfs !== null ? $this->describe($gtfs->getUuid(), $gtfs->getRef(), $gtfs->getName()) : null;
    }

    public function resolveByOsm(Game $game, string $osmType, int $osmId): ?ResolvedTransitLine
    {
        $line = $this->osmLines->findOneByGameAndOsm($game, $osmType, $osmId);

        return $line !== null ? $this->describe($line->getUuid(), $line->getRef(), $line->getName()) : null;
    }

    private function describe(string $uuid, string $ref, string $name): ResolvedTransitLine
    {
        $ref = trim($ref);
        $name = trim($name);

        return new ResolvedTransitLine($uuid, $ref, match (true) {
            $ref !== '' && $name !== '' => sprintf('%s: %s', $ref, $name),
            $ref !== '' => $ref,
            default => $name,
        });
    }
}
