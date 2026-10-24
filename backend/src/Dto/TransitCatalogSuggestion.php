<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class TransitCatalogSuggestion
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        public string $municipality,
        public string $countryCode,
        public ?string $licenseUrl,
        public string $downloadUrl,
        public ?string $fallbackUrl,
        public ?string $realtimeUrl,
        public bool $live,
        public string $scope,
    ) {
    }
}
