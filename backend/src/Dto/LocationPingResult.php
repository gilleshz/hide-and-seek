<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PlayerLocation;

final readonly class LocationPingResult
{
    public function __construct(
        public PlayerLocation $location,
        public bool $endgameTriggered,
    ) {
    }
}
