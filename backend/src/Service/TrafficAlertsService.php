<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TrafficAlert;
use App\Entity\GtfsSource;
use App\Enum\RealtimeProvider;
use App\Exception\EntityNotFoundException;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GameRepository;
use Psr\Log\LoggerInterface;

/**
 * Serves the active disruption alerts of a game's GTFS sources (MAP-12/MAP-13): a GTFS-RT
 * service-alerts feed when the source carries one, or the operator's SIRI Lite general-message
 * feed when its profile has an alerts endpoint. Alerts are filtered to the game's selected
 * lines, dropped outside their validity window, deduped by id and sorted by severity then start.
 * Operator data only: no player data is ever included.
 */
final readonly class TrafficAlertsService
{
    public function __construct(
        private GameRepository $games,
        private GameGtfsLineRepository $gtfsLines,
        private TransitRealtimeService $realtime,
        private SiriAlertsClient $siriAlerts,
        private SiriProfileRegistry $profiles,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{serverNow: int, alerts: list<TrafficAlert>}
     */
    public function forGame(string $gameUuid, ?int $now = null): array
    {
        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }
        $now ??= time();

        $sources = [];
        $routeIds = [];
        $refs = [];
        foreach ($this->gtfsLines->findByGame($game) as $line) {
            $source = $line->getGtfsSource();
            $sources[$source->getUuid()] = $source;
            $routeIds[$line->getRouteId()] = true;
            $ref = trim($line->getRef());
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }

        $alerts = [];
        foreach ($sources as $source) {
            foreach ($this->sourceAlerts($source) as $alert) {
                $alerts[] = $alert;
            }
        }

        return [
            'serverNow' => $now,
            'alerts' => $this->filter($alerts, array_keys($routeIds), array_keys($refs), $now),
        ];
    }

    /**
     * Keeps alerts whose route ids or line refs intersect the game's selected lines, plus
     * line-less network-wide alerts; drops alerts outside their validity window; dedupes by id
     * and sorts by severity rank then validity start.
     *
     * @param list<TrafficAlert> $alerts
     * @param list<string> $selectedRouteIds
     * @param list<string> $selectedRefs
     *
     * @return list<TrafficAlert>
     */
    public function filter(array $alerts, array $selectedRouteIds, array $selectedRefs, int $now): array
    {
        $routeIdSet = array_fill_keys($selectedRouteIds, true);
        $refSet = [];
        foreach ($selectedRefs as $ref) {
            $folded = SiriRef::normalizedFolded((string) $ref);
            if ($folded !== '') {
                $refSet[$folded] = true;
            }
        }

        $kept = [];
        $seen = [];
        foreach ($alerts as $alert) {
            $relevant = !$this->coversNow($alert, $now) || !$this->matchesLines($alert, $routeIdSet, $refSet);
            if (isset($seen[$alert->id]) || $relevant) {
                continue;
            }
            $seen[$alert->id] = true;
            $kept[] = $alert;
        }

        usort(
            $kept,
            static fn (TrafficAlert $a, TrafficAlert $b): int => $b->severity->rank() <=> $a->severity->rank()
                ?: ($a->validFrom ?? 0) <=> ($b->validFrom ?? 0)
                ?: strcmp($a->id, $b->id),
        );

        return $kept;
    }

    /**
     * @return list<TrafficAlert>
     */
    private function sourceAlerts(GtfsSource $source): array
    {
        try {
            return $this->fetchSourceAlerts($source);
        } catch (\Throwable $e) {
            $this->logger->warning('Traffic alerts unavailable for source {source}: {error}', [
                'source' => $source->getUuid(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<TrafficAlert>
     */
    private function fetchSourceAlerts(GtfsSource $source): array
    {
        $alertsUrl = $source->getRealtimeAlertsUrl();
        $isGtfsRt = $source->getRealtimeProvider() === RealtimeProvider::GtfsRtUrl;
        if ($isGtfsRt && is_string($alertsUrl) && $alertsUrl !== '') {
            return $this->realtime->serviceAlerts($source->getUuid(), $alertsUrl);
        }
        $profile = $source->getRealtimeProvider() === RealtimeProvider::SiriLite
            ? $this->profiles->find((string) $source->getRealtimeProfile())
            : null;
        if ($profile !== null && $profile->alertsEndpoint !== null) {
            return $this->siriAlerts->alerts($profile);
        }

        return [];
    }

    private function coversNow(TrafficAlert $alert, int $now): bool
    {
        return !($alert->validUntil !== null && $alert->validUntil < $now)
            && !($alert->validFrom !== null && $alert->validFrom > $now);
    }

    /**
     * @param array<string, true> $routeIdSet
     * @param array<string, true> $refSet
     */
    private function matchesLines(TrafficAlert $alert, array $routeIdSet, array $refSet): bool
    {
        if ($alert->routeIds === [] && $alert->lineRefs === []) {
            return true;
        }
        foreach ($alert->routeIds as $routeId) {
            if (isset($routeIdSet[$routeId])) {
                return true;
            }
        }
        foreach ($alert->lineRefs as $lineRef) {
            if (isset($refSet[SiriRef::normalizedFolded($lineRef)])) {
                return true;
            }
        }

        return false;
    }
}
