<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;

final class TransitDepartureEntryResource
{
    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public int $at;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public int $inSeconds;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public bool $realtime;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public int $delaySeconds;

    #[Groups([Group::TRANSIT_DEPARTURES_READ])]
    public string $headsign;

    /**
     * @param array{
     *     at: int,
     *     inSeconds: int,
     *     realtime: bool,
     *     delaySeconds: int,
     *     headsign: string
     * } $entry
     */
    public static function fromArray(array $entry): self
    {
        $self = new self();
        $self->at = $entry['at'];
        $self->inSeconds = $entry['inSeconds'];
        $self->realtime = $entry['realtime'];
        $self->delaySeconds = $entry['delaySeconds'];
        $self->headsign = $entry['headsign'];

        return $self;
    }
}
