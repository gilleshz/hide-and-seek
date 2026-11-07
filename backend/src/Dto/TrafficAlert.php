<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AlertSeverity;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One disruption alert normalized from either a GTFS-RT service-alerts feed or a SIRI Lite
 * general-message feed. Pure operator data: no player identifiers, no locations.
 */
final class TrafficAlert
{
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $id;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $channel;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public AlertSeverity $severity;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $title;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $body;

    /** @var list<string> operator line names (SIRI ImpactedLineRef) */
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public array $lineRefs = [];

    /** @var list<string> GTFS route ids (GTFS-RT informed_entity route_id) */
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public array $routeIds = [];

    /** @var list<string> GTFS stop ids (GTFS-RT informed_entity stop_id) */
    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public array $stopIds = [];

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public ?int $validFrom = null;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public ?int $validUntil = null;

    #[Groups([Group::TRAFFIC_ALERTS_READ])]
    public string $source;

    /**
     * @param list<string> $lineRefs
     * @param list<string> $routeIds
     * @param list<string> $stopIds
     */
    public function __construct(
        string $id,
        string $channel,
        AlertSeverity $severity,
        string $title,
        string $body,
        array $lineRefs,
        array $routeIds,
        array $stopIds,
        ?int $validFrom,
        ?int $validUntil,
        string $source,
    ) {
        $this->id = $id;
        $this->channel = $channel;
        $this->severity = $severity;
        $this->title = $title;
        $this->body = $body;
        $this->lineRefs = $lineRefs;
        $this->routeIds = $routeIds;
        $this->stopIds = $stopIds;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->source = $source;
    }
}
