<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ChatImageUploadResource;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\RateLimits;
use App\Service\UploadedImageReader;
use App\Storage\ImageStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<mixed, ChatImageUploadResource>
 */
final readonly class ChatImageUploadProcessor implements ProcessorInterface
{
    private const int MAX_CAPTION_LENGTH = 2000;

    public function __construct(
        private GameRepository $games,
        private ChatService $chatService,
        private ImageStorageInterface $storage,
        private RequestStack $requestStack,
        private UploadedImageReader $upload,
        private IdentityResolver $identity,
        private RateLimits $rateLimits,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ChatImageUploadResource {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $uploadedFile = $this->upload->image();
        $player = $this->identity->requirePlayer();
        $this->rateLimits->chatSend($player->getUuid());

        if ($player->getGame()->getUuid() !== $game->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this game.',
                errorKey: 'chat.player_not_in_game',
            );
        }

        $request = $this->requestStack->getCurrentRequest();
        $caption = $request?->request->get('caption');
        $caption = is_string($caption) && $caption !== '' ? $caption : null;
        if ($caption !== null && mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            throw new FunctionalException(
                message: 'Caption exceeds maximum length of 2000 characters.',
                errorKey: 'chat_image.caption_too_long',
            );
        }

        $replyToUuid = $request?->request->get('replyToUuid');

        $imageRef = $this->storage->store($game->getUuid(), $uploadedFile);
        $message = $this->chatService->postImage(
            $game,
            $player,
            $imageRef,
            $caption,
            is_string($replyToUuid) && $replyToUuid !== '' ? $replyToUuid : null,
        );

        return ChatImageUploadResource::fromMessage($message);
    }
}
