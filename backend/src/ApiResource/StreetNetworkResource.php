<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Entity\Round;
use App\Entity\RoundStreetNetwork;
use App\Enum\StreetNetworkStatus;
use App\Serializer\Group;
use App\State\StreetNetworkProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'StreetNetwork',
    operations: [
        // Gated on the subscriber token: the payload is centred on the zone, so it is the zone.
        new Get(
            uriTemplate: '/rounds/{roundUuid}/street-network',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: StreetNetworkProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::STREET_NETWORK_READ]],
)]
final class StreetNetworkResource
{
    #[Groups([Group::STREET_NETWORK_READ])]
    public string $roundUuid;

    #[Groups([Group::STREET_NETWORK_READ])]
    public string $status;

    #[Groups([Group::STREET_NETWORK_READ])]
    public ?\DateTimeImmutable $fetchedAt = null;

    #[Groups([Group::STREET_NETWORK_READ])]
    public int $wayCount = 0;

    /**
     * @var list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>
     */
    #[Groups([Group::STREET_NETWORK_READ])]
    public array $ways = [];

    public static function pending(string $roundUuid): self
    {
        $self = new self();
        $self->roundUuid = $roundUuid;
        $self->status = StreetNetworkStatus::Pending->value;

        return $self;
    }

    public static function fromEntity(RoundStreetNetwork $network): self
    {
        $ready = $network->getStatus() === StreetNetworkStatus::Ready;

        $self = new self();
        $self->roundUuid = $network->getRound()->getUuid();
        $self->status = $network->getStatus()->value;
        $self->fetchedAt = $network->getFetchedAt();
        $self->wayCount = $network->getWayCount();
        $self->ways = $ready ? ($network->getPayload() ?? []) : [];

        return $self;
    }
}
