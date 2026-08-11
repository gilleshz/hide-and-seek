<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\ChatMessageResource;
use App\Dto\ChatMessageInput;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\RateLimits;

/**
 * @implements ProcessorInterface<ChatMessageInput, ChatMessageResource>
 */
final readonly class ChatMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private GameRepository $games,
        private ChatService $chatService,
        private IdentityResolver $identity,
        private RateLimits $rateLimits,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ChatMessageResource {
        $this->validator->validate($data);

        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->identity->requirePlayer();
        $this->rateLimits->chatSend($player->getUuid());

        if ($player->getGame()->getUuid() !== $game->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this game.',
                errorKey: 'chat.player_not_in_game',
            );
        }

        $message = $this->chatService->postText($game, $player, $data->body, $data->replyToUuid);

        return ChatMessageResource::fromEntity($message);
    }
}
