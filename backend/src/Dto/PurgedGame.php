<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PurgedGame
{
    /** @param list<string> $removedPaths */
    public function __construct(
        public string $uuid,
        public string $name,
        public array $removedPaths,
    ) {
    }
}
