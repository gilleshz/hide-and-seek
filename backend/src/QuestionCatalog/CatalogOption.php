<?php

declare(strict_types=1);

namespace App\QuestionCatalog;

use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\PhotoTarget;

final readonly class CatalogOption
{
    public function __construct(
        public string $label,
        public ?FeatureType $featureType = null,
        public ?float $meters = null,
        public GameSize $minSize = GameSize::Small,
        public ?Edition $edition = null,
        public ?PhotoTarget $photoTarget = null,
        public bool $custom = false,
        public bool $transitLine = false,
        public bool $stationNameLength = false,
        public bool $seaLevel = false,
    ) {
    }

    public function availableInSize(GameSize $size): bool
    {
        return $size->ordinal() >= $this->minSize->ordinal();
    }

    public function availableInEdition(Edition $edition): bool
    {
        return $this->edition === null || $this->edition === $edition;
    }
}
