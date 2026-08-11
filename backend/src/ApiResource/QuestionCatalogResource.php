<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Game;
use App\QuestionCatalog\CatalogCategory;
use App\Serializer\Group;
use App\State\QuestionCatalogProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'QuestionCatalog',
    operations: [
        new GetCollection(
            uriTemplate: '/games/{gameUuid}/question-catalog',
            uriVariables: [
                'gameUuid' => new Link(fromClass: Game::class, identifiers: ['uuid']),
            ],
            provider: QuestionCatalogProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::QUESTION_CATALOG_READ]],
)]
final class QuestionCatalogResource
{
    #[Groups([Group::QUESTION_CATALOG_READ])]
    public string $key;

    /** @var list<QuestionCatalogOptionResource> */
    #[Groups([Group::QUESTION_CATALOG_READ])]
    public array $options = [];

    public static function fromCategory(CatalogCategory $category): self
    {
        $self = new self();
        $self->key = $category->key->value;
        $self->options = array_map(
            fn($opt) => QuestionCatalogOptionResource::fromOption($opt),
            $category->options,
        );

        return $self;
    }
}
