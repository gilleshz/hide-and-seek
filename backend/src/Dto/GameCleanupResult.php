<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class GameCleanupResult
{
    /** @param list<PurgedGame> $purged */
    public function __construct(
        public array $purged,
        public int $skippedInProgress,
    ) {
    }

    public function deletedCount(): int
    {
        return count($this->purged);
    }
}
