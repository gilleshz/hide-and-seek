<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HidingZone;
use App\Entity\Round;
use App\Entity\RoundStreetNetwork;
use App\Enum\GameSize;
use App\Enum\OverpassEmptyPolicy;
use App\Enum\RoundStatus;
use App\Enum\StreetNetworkStatus;
use App\Exception\EntityNotFoundException;
use App\GeoDistance;
use App\Repository\HidingZoneRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundStreetNetworkRepository;
use App\StreetNetworkRules;
use App\StreetNetworkTrim;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Psr\Log\LoggerInterface;

final readonly class StreetNetworkService
{
    public function __construct(
        private RoundRepository $rounds,
        private HidingZoneRepository $zones,
        private RoundStreetNetworkRepository $networks,
        private OverpassHttpClient $overpassHttpClient,
        private HiderGuard $hiderGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enqueue only, no I/O beyond the database: the zone is placed inside a request, and an Overpass
     * fetch would hold a worker for tens of seconds. `app:street-network:warm` picks the row up.
     */
    public function enqueueForZone(Round $round, HidingZone $zone): void
    {
        if ($round->getGame()->getSize() === GameSize::Small || !self::isActive($round)) {
            return;
        }

        $existing = $this->networks->findOneByRound($round);
        if ($existing === null) {
            $this->networks->insertPendingForRound($round, $zone->getStationPoint(), $zone->getRadiusMeters());

            return;
        }

        if (self::matchesZone($existing, $zone)) {
            return;
        }

        $this->networks->save(
            $existing
                ->setCenter($zone->getStationPoint())
                ->setRadiusMeters($zone->getRadiusMeters())
                ->setStatus(StreetNetworkStatus::Pending)
                ->setPayload(null)
                ->setWayCount(0)
                ->setAttempts(0)
                ->setFetchedAt(null),
        );
    }

    /** Database only, so the scheduler tick that spawns the fetches never waits on one. */
    public function retireIfFinished(RoundStreetNetwork $network): bool
    {
        if (self::isActive($network->getRound())) {
            return false;
        }

        $this->networks->save($network->setStatus(StreetNetworkStatus::Unavailable));

        return true;
    }

    public function fill(string $networkUuid): void
    {
        $network = $this->networks->findOneByUuid($networkUuid);
        if ($network !== null) {
            $this->warm($network);
        }
    }

    public function warm(RoundStreetNetwork $network): void
    {
        $round = $network->getRound();
        if (!$this->networks->acquireWarmLock($round)) {
            return;
        }

        try {
            $this->warmUnderLock($network, $round);
        } catch (\RuntimeException | \JsonException $e) {
            $this->recordFailure($network, $e);
        } catch (\Throwable $e) {
            $this->logFailure($network, $e);
        } finally {
            $this->networks->releaseWarmLock($round);
        }
    }

    /**
     * Hider-only: the payload is centred on the zone, so a seeker holding it holds the zone.
     */
    public function forSubscriber(string $roundUuid): ?RoundStreetNetwork
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $this->hiderGuard->assertHider($round, 'street_network.not_hider');

        $zone = $this->zones->findOneByRound($round);
        if ($zone === null) {
            return null;
        }

        $network = $this->networks->findOneByRound($round);
        if ($network === null || !self::matchesZone($network, $zone)) {
            $this->enqueueForZone($round, $zone);

            return null;
        }

        return $network;
    }

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    private function warmUnderLock(RoundStreetNetwork $network, Round $round): void
    {
        $this->networks->refresh($network);
        if ($network->getStatus() !== StreetNetworkStatus::Pending) {
            return;
        }

        if (!self::isActive($round)) {
            $this->networks->save($network->setStatus(StreetNetworkStatus::Unavailable));

            return;
        }

        $this->fetchAndStore($network);
    }

    /**
     * Both rejections are on: caching an empty answer as ready would silently disable snapping for the
     * whole round, and an overloaded mirror says so in a remark while one whose extract misses the zone
     * does not.
     *
     * @throws \JsonException
     * @throws \RuntimeException
     */
    private function fetchAndStore(RoundStreetNetwork $network): void
    {
        $center = $network->getCenter();
        $radiusMeters = $network->getRadiusMeters();

        $json = $this->overpassHttpClient->fetch(
            self::buildQuery($center, $radiusMeters),
            StreetNetworkRules::OVERPASS_TIMEOUT_SECONDS,
            StreetNetworkRules::MAX_RESPONSE_BYTES,
            OverpassEmptyPolicy::RejectAny,
        );

        $ways = StreetNetworkTrim::ways($json, $center, $radiusMeters);
        if ($ways === []) {
            throw new \RuntimeException('The Overpass answer held no street geometry inside the zone.');
        }

        if (strlen(json_encode($ways, JSON_THROW_ON_ERROR)) > StreetNetworkRules::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException('The trimmed street network is larger than the payload cap.');
        }

        $this->storeIfStillWanted($network, $center, $radiusMeters, $ways);
    }

    /**
     * The fetch runs for tens of seconds, and a zone moved in that window has already reset the row:
     * saving this result would leave the new centre next to the old geometry forever after.
     *
     * @param list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }> $ways
     */
    private function storeIfStillWanted(
        RoundStreetNetwork $network,
        Point $center,
        float $radiusMeters,
        array $ways,
    ): void {
        $this->networks->refresh($network);
        if (!self::matchesTarget($network, $center, $radiusMeters)) {
            return;
        }

        $this->networks->save(
            $network
                ->setPayload($ways)
                ->setWayCount(count($ways))
                ->setFetchedAt(new \DateTimeImmutable())
                ->setStatus(StreetNetworkStatus::Ready),
        );
    }

    private function recordFailure(RoundStreetNetwork $network, \Throwable $failure): void
    {
        $attempts = $network->getAttempts() + 1;
        $network->setAttempts($attempts);
        if ($attempts >= StreetNetworkRules::MAX_WARM_ATTEMPTS) {
            $network->setStatus(StreetNetworkStatus::Unavailable);
        }
        $this->networks->save($network);

        $this->logFailure($network, $failure);
    }

    private function logFailure(RoundStreetNetwork $network, \Throwable $failure): void
    {
        $this->logger->warning('Street-network warm failed for round {round}: {reason}', [
            'round' => $network->getRound()->getUuid(),
            'reason' => $failure->getMessage(),
            'exception' => $failure,
        ]);
    }

    private static function buildQuery(Point $center, float $radiusMeters): string
    {
        $bbox = StreetNetworkTrim::bbox($center, $radiusMeters);
        $timeout = StreetNetworkRules::OVERPASS_TIMEOUT_SECONDS;
        $excluded = '^(proposed|construction|raceway|bus_guideway|escape)$';

        return <<<QL
            [out:json][timeout:{$timeout}];
            way["highway"]["highway"!~"{$excluded}"]({$bbox});
            out geom;
        QL;
    }

    private static function matchesZone(RoundStreetNetwork $network, HidingZone $zone): bool
    {
        return self::matchesTarget($network, $zone->getStationPoint(), $zone->getRadiusMeters());
    }

    private static function matchesTarget(
        RoundStreetNetwork $network,
        Point $center,
        float $radiusMeters,
    ): bool {
        return $network->getRadiusMeters() === $radiusMeters
            && GeoDistance::metersBetween($network->getCenter(), $center)
                <= StreetNetworkRules::CENTER_TOLERANCE_METERS;
    }

    private static function isActive(Round $round): bool
    {
        return in_array($round->getStatus(), [RoundStatus::Hiding, RoundStatus::Seeking], true);
    }
}
