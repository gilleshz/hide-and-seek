<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ChatReadCursorResource;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageReadRepository;
use App\Repository\GameRepository;

/**
 * @implements ProviderInterface<ChatReadCursorResource>
 */
final readonly class ChatReadCursorCollectionProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private ChatMessageReadRepository $reads,
    ) {
    }

    /**
     * @return list<ChatReadCursorResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return array_map(
            static fn (array $cursor): ChatReadCursorResource => ChatReadCursorResource::create(
                $cursor['playerUuid'],
                $cursor['playerName'],
                $cursor['readUpTo'],
            ),
            $this->reads->findCursorsByGame($game),
        );
    }
}
