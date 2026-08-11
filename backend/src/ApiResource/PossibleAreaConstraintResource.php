<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\ManualConstraintDraft;
use App\Dto\PossibleAreaConstraintInput;
use App\Entity\Round;
use App\Enum\ConstraintSource;
use App\Serializer\Group;
use App\State\PossibleAreaConstraintDeleteProcessor;
use App\State\PossibleAreaConstraintProcessor;
use App\State\PossibleAreaConstraintProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'PossibleAreaConstraint',
    operations: [
        new GetCollection(
            uriTemplate: '/rounds/{roundUuid}/possible-area-constraints',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            provider: PossibleAreaConstraintProvider::class,
        ),
        new Post(
            uriTemplate: '/rounds/{roundUuid}/possible-area-constraints',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
            ],
            input: PossibleAreaConstraintInput::class,
            processor: PossibleAreaConstraintProcessor::class,
        ),
        new Delete(
            uriTemplate: '/rounds/{roundUuid}/possible-area-constraints/{uuid}',
            uriVariables: [
                'roundUuid' => new Link(fromClass: Round::class, identifiers: ['uuid']),
                'uuid' => new Link(identifiers: ['uuid']),
            ],
            read: false,
            processor: PossibleAreaConstraintDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::POSSIBLE_AREA_CONSTRAINT_READ]],
    denormalizationContext: ['groups' => [Group::POSSIBLE_AREA_CONSTRAINT_WRITE]],
)]
final class PossibleAreaConstraintResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public string $uuid;

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public string $mode;

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public string $source;

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public string $geoJson;

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public string $label;

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_READ])]
    public ?string $createdByName;

    public static function fromDraft(ManualConstraintDraft $draft, string $uuid, ?string $createdByName): self
    {
        $self = new self();
        $self->uuid = $uuid;
        $self->mode = $draft->mode->value;
        $self->source = ConstraintSource::Manual->value;
        $self->geoJson = $draft->ringGeoJson;
        $self->label = $draft->label;
        $self->createdByName = $createdByName;

        return $self;
    }

    /**
     * @param array{
     *     uuid: string,
     *     mode: string,
     *     source: string,
     *     label: string,
     *     geoJson: ?string,
     *     createdByName: ?string,
     * } $row
     */
    public static function fromRow(array $row): self
    {
        $self = new self();
        $self->uuid = $row['uuid'];
        $self->mode = $row['mode'];
        $self->source = $row['source'];
        $self->geoJson = $row['geoJson'] ?? '';
        $self->label = $row['label'];
        $self->createdByName = $row['createdByName'];

        return $self;
    }
}
