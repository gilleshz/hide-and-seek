<?php

declare(strict_types=1);

namespace App\Dto;

final class ResolvedTransitLine
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $ref,
        public readonly string $label,
    ) {
    }
}
