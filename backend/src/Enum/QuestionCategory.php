<?php

declare(strict_types=1);

namespace App\Enum;

enum QuestionCategory: string
{
    case Radar = 'radar';
    case Thermometer = 'thermometer';
    case Matching = 'matching';
    case Measuring = 'measuring';
    case Tentacles = 'tentacles';
    case Photos = 'photos';

    public function requiresFeature(): bool
    {
        return match ($this) {
            self::Matching, self::Measuring, self::Tentacles => true,
            self::Radar, self::Thermometer, self::Photos => false,
        };
    }
}
