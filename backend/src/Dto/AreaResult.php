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
    ) {
    }
}
