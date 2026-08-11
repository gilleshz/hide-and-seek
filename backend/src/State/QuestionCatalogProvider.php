<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\QuestionCatalogResource;
use App\Exception\EntityNotFoundException;
use App\QuestionCatalog\CatalogDefinition;
use App\Repository\GameRepository;

/**
 * @implements ProviderInterface<QuestionCatalogResource>
 */
final readonly class QuestionCatalogProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
    ) {
    }

    /** @return list<QuestionCatalogResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameUuid = $uriVariables['gameUuid'] ?? null;
        if (!is_string($gameUuid)) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $categories = CatalogDefinition::forGame($game->getSize(), $game->getEdition());

        return array_map(
            fn($category) => QuestionCatalogResource::fromCategory($category),
            $categories,
        );
    }
}
