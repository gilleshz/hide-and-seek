<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\RealtimeProvider;

final readonly class RealtimeSelection
{
    public function __construct(
        public string $provider,
        public ?string $profile = null,
        public ?string $url = null,
    ) {
    }

    /**
     * With no provider, a profile means SIRI Lite and a bare URL keeps the pre-SIRI GTFS-RT
     * contract.
     */
    public static function fromParts(?string $provider, ?string $profile, ?string $url): self
    {
        $provider = trim((string) $provider);
        $profile = trim((string) $profile);
        $url = trim((string) $url);

        if ($provider === '') {
            $provider = match (true) {
                $profile !== '' => RealtimeProvider::SiriLite->value,
                $url !== '' => RealtimeProvider::GtfsRtUrl->value,
                default => RealtimeProvider::None->value,
            };
        }

        return new self(
            $provider,
            $profile === '' ? null : $profile,
            $url === '' ? null : $url,
        );
    }

    public static function none(): self
    {
        return new self(RealtimeProvider::None->value);
    }
}
