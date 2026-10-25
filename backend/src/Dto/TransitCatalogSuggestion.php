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
        public ?int $sizeBytes = null,
    ) {
    }

    public function withSizeBytes(?int $sizeBytes): self
    {
        return new self(
            id: $this->id,
            provider: $this->provider,
            name: $this->name,
            municipality: $this->municipality,
            countryCode: $this->countryCode,
            licenseUrl: $this->licenseUrl,
            downloadUrl: $this->downloadUrl,
            fallbackUrl: $this->fallbackUrl,
            realtimeUrl: $this->realtimeUrl,
            live: $this->live,
            scope: $this->scope,
            sizeBytes: $sizeBytes,
        );
    }
}
