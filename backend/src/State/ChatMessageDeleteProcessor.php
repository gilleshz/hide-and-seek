<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ChatMessageDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private GameRepository $games,
        private ChatService $chatService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();

        if ($player->getGame()->getUuid() !== $game->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this game.',
                errorKey: 'chat.player_not_in_game',
            );
        }

        $messageUuid = $uriVariables['uuid'] ?? null;
        $this->chatService->deleteMessage($game, $player, is_string($messageUuid) ? $messageUuid : '');

        return null;
    }
}
