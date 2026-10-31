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

    #[Groups(['possible-area:read'])]
    public ?string $stationAreaGeoJson;

    #[Groups(['possible-area:read'])]
    public ?string $stationBandGeoJson;

    /**
     * @param array{
     *     searchArea: ?string,
     *     exclusion: ?string,
     *     stationArea: ?string,
     *     stationBand: ?string,
     * } $areas
     */
    public static function fromRound(string $roundUuid, array $areas): self
    {
        $self = new self();
        $self->roundUuid = $roundUuid;
        $self->geoJson = $areas['searchArea'];
        $self->exclusionGeoJson = $areas['exclusion'];
        $self->stationAreaGeoJson = $areas['stationArea'];
        $self->stationBandGeoJson = $areas['stationBand'];

        return $self;
    }
}
