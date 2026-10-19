<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class TransitDepartureGroupResource
{
    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $routeId;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $lineRef;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $lineName;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $colour;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $headsign;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public int $directionId;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public bool $realtime;

    /** @var list<TransitDepartureEntryResource> */
    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public array $departures = [];

    /**
     * @param array{
     *     routeId: string,
     *     lineRef: string,
     *     lineName: string,
     *     colour: string,
     *     headsign: string,
     *     directionId: int,
     *     realtime: bool,
     *     departures: list<array{
     *         at: int,
     *         inSeconds: int,
     *         realtime: bool,
     *         delaySeconds: int,
     *         headsign: string
     *     }>
     * } $group
     */
    public static function fromArray(array $group): self
    {
        $self = new self();
        $self->routeId = $group['routeId'];
        $self->lineRef = $group['lineRef'];
        $self->lineName = $group['lineName'];
        $self->colour = $group['colour'];
        $self->headsign = $group['headsign'];
        $self->directionId = $group['directionId'];
        $self->realtime = $group['realtime'];
        $self->departures = array_map(
            static fn (array $entry): TransitDepartureEntryResource => TransitDepartureEntryResource::fromArray($entry),
            $group['departures'],
        );

        return $self;
    }
}
