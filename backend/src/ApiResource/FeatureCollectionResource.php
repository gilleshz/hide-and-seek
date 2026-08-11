<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Round;
use App\Serializer\Group;
use App\State\FeatureCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'FeatureCollection',
    operations: [
        new GetCollection(
            uriTemplate: '/rounds/{roundUuid}/features',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: FeatureCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::FEATURE_READ]],
)]
final class FeatureCollectionResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::FEATURE_READ])]
    public string $uuid;

    #[Groups([Group::FEATURE_READ])]
    public ?string $name = null;

    #[Groups([Group::FEATURE_READ])]
    public float $lat;

    #[Groups([Group::FEATURE_READ])]
    public float $lng;

    public static function fromFeature(string $uuid, ?string $name, float $lat, float $lng): self
    {
        $self = new self();
        $self->uuid = $uuid;
        $self->name = $name;
        $self->lat = $lat;
        $self->lng = $lng;

        return $self;
    }
}
