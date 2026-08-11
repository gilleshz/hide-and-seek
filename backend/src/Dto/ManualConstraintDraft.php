<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ConstraintMode;

final readonly class ManualConstraintDraft
{
    public function __construct(
        public string $ringGeoJson,
        public ConstraintMode $mode,
        public string $label,
    ) {
    }
}
