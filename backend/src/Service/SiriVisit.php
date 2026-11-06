<?php

declare(strict_types=1);

namespace App\Service;

final readonly class SiriVisit
{
    public function __construct(
        public string $lineCode,
        public string $stopCode,
        public ?string $tripRef,
        public int $aimedAt,
        public ?int $expectedAt,
        public bool $cancelled = false,
        public ?int $stopSequence = null,
        public ?string $destination = null,
    ) {
    }
}
