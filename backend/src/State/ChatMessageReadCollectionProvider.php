<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ChatMessageReadResource;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageReadRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\GameRepository;

/**
 * @implements ProviderInterface<ChatMessageReadResource>
 */
final readonly class ChatMessageReadCollectionProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private ChatMessageRepository $messages,
        private ChatMessageReadRepository $reads,
    ) {
    }

    /**
     * @return list<ChatMessageReadResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $messageUuid = $uriVariables['messageUuid'] ?? null;
        $message = is_string($messageUuid) ? $this->messages->findOneByUuid($messageUuid) : null;
        if ($message === null || $message->getGame()->getUuid() !== $game->getUuid()) {
            throw new EntityNotFoundException(
                message: 'Chat message not found in this game.',
                errorKey: 'chat.message_not_found',
            );
        }

        return array_map(
            static fn (array $reader): ChatMessageReadResource => ChatMessageReadResource::create(
                $reader['playerUuid'],
                $reader['playerName'],
                $reader['readAt'],
            ),
            $this->reads->findReadersByMessage($message),
        );
    }
}
