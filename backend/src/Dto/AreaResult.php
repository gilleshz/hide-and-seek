<?php

declare(strict_types=1);

namespace App\Dto;

final class AreaResult
{
    public function __construct(
        public readonly string $osmType,
        public readonly int $osmId,
        public readonly string $displayName,
        public readonly ?int $adminLevel,
        public readonly ?int $population = null,
        public readonly ?float $widthKm = null,
        public readonly ?float $heightKm = null,
    ) {
    }
}
