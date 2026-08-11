<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\ChatReadCursorResource;
use App\Dto\ChatReadInput;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\ChatReadService;
use App\Service\IdentityResolver;

/**
 * @implements ProcessorInterface<ChatReadInput, ChatReadCursorResource>
 */
final readonly class ChatReadProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private GameRepository $games,
        private ChatReadService $chatReadService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ChatReadCursorResource {
        $this->validator->validate($data);

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

        $cursor = $this->chatReadService->markReadUpTo($game, $player, $data->upToUuid);

        return ChatReadCursorResource::create($player->getUuid(), $player->getDisplayName(), $cursor);
    }
}
