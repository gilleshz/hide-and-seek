<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Game;
use App\State\IngestFeaturesProcessor;

#[ApiResource(
    shortName: 'IngestFeatures',
    operations: [
        new Post(
            uriTemplate: '/games/{gameUuid}/ingest-features',
            uriVariables: [
                'gameUuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            input: false,
            processor: IngestFeaturesProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => []],
    denormalizationContext: ['groups' => []],
)]
final class IngestFeaturesResource
{
    public int $featuresIngested;

    public static function result(int $count): self
    {
        $self = new self();
        $self->featuresIngested = $count;

        return $self;
    }
}
