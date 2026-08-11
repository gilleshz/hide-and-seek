<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Entity\Round;
use App\State\PossibleAreaProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'PossibleArea',
    operations: [
        new Get(
            uriTemplate: '/rounds/{roundUuid}/possible-area',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: PossibleAreaProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['possible-area:read']],
)]
final class PossibleAreaResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['possible-area:read'])]
    public string $roundUuid;

    #[Groups(['possible-area:read'])]
    public ?string $geoJson;

    #[Groups(['possible-area:read'])]
    public ?string $exclusionGeoJson;

    public static function fromRound(string $roundUuid, ?string $geoJson, ?string $exclusionGeoJson = null): self
    {
        $self = new self();
        $self->roundUuid = $roundUuid;
        $self->geoJson = $geoJson;
        $self->exclusionGeoJson = $exclusionGeoJson;

        return $self;
    }
}
