<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\SeekerCandidateMarkerInput;
use App\Entity\Round;
use App\Entity\SeekerCandidateMarker;
use App\Serializer\Group;
use App\State\SeekerCandidateMarkerCollectionProvider;
use App\State\SeekerCandidateMarkerDeleteProcessor;
use App\State\SeekerCandidateMarkerProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'SeekerCandidateMarker',
    operations: [
        new GetCollection(
            uriTemplate: '/rounds/{roundUuid}/seeker-candidate-markers',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: SeekerCandidateMarkerCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/seeker-candidate-markers',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: SeekerCandidateMarkerInput::class,
            processor: SeekerCandidateMarkerProcessor::class,
        ),
        new Delete(
            uriTemplate: '/rounds/{roundUuid}/seeker-candidate-markers/{uuid}',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
                'uuid' => new Link(identifiers: ['uuid']),
            ],
            read: false,
            processor: SeekerCandidateMarkerDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::SEEKER_CANDIDATE_READ]],
    denormalizationContext: ['groups' => [Group::SEEKER_CANDIDATE_WRITE]],
)]
final class SeekerCandidateMarkerResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::SEEKER_CANDIDATE_READ])]
    public string $uuid;

    #[Groups([Group::SEEKER_CANDIDATE_READ])]
    public string $playerUuid;

    #[Groups([Group::SEEKER_CANDIDATE_READ])]
    public float $lat;

    #[Groups([Group::SEEKER_CANDIDATE_READ])]
    public float $lng;

    #[Groups([Group::SEEKER_CANDIDATE_READ])]
    public \DateTimeImmutable $createdAt;

    public static function fromEntity(SeekerCandidateMarker $marker): self
    {
        $self = new self();
        $self->uuid = $marker->getUuid();
        $self->playerUuid = $marker->getPlayer()->getUuid();
        $self->lat = $marker->getPoint()->getLatitude();
        $self->lng = $marker->getPoint()->getLongitude();
        $self->createdAt = $marker->getCreatedAt();

        return $self;
    }
}
