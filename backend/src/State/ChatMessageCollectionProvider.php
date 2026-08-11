<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ChatMessageResource;
use App\Entity\ChatMessage;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;

/**
 * @implements ProviderInterface<ChatMessageResource>
 */
final readonly class ChatMessageCollectionProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private ChatMessageRepository $messages,
    ) {
    }

    /**
     * @return list<ChatMessageResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return array_map(
            static fn (ChatMessage $message): ChatMessageResource => ChatMessageResource::fromEntity($message),
            $this->messages->findByGame($game),
        );
    }
}
