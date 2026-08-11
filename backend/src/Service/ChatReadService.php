<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Entity\Game;
use App\Entity\Player;
use App\Exception\EntityNotFoundException;
use App\Repository\ChatMessageReadRepository;
use App\Repository\ChatMessageRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class ChatReadService
{
    public function __construct(
        private ChatMessageRepository $messages,
        private ChatMessageReadRepository $reads,
        private MercureJwtService $mercure,
        private HubInterface $hub,
    ) {
    }

    /**
     * Records that a player has read the game's chat up to a given message.
     *
     * The cursor comes from the referenced message's own timestamp rather than a client clock, and
     * the watermark is re-read afterwards so an out-of-order report can never move a reader
     * backwards for everyone else.
     */
    public function markReadUpTo(Game $game, Player $reader, string $upToUuid): ?\DateTimeImmutable
    {
        $message = $this->messages->findOneByUuid($upToUuid);
        if ($message === null || $message->getGame()->getUuid() !== $game->getUuid()) {
            throw new EntityNotFoundException(
                message: 'Chat message not found in this game.',
                errorKey: 'chat.message_not_found',
            );
        }

        $marked = $this->reads->markReadUpTo($game, $reader, $message->getCreatedAt(), new \DateTimeImmutable());
        $cursor = $this->reads->findCursorForPlayer($game, $reader);

        if ($marked > 0 && $cursor !== null) {
            $this->publish($game, $reader, $cursor);
        }

        return $cursor;
    }

    private function publish(Game $game, Player $reader, \DateTimeImmutable $cursor): void
    {
        $payload = json_encode([
            'type' => 'chat-read',
            'playerUuid' => $reader->getUuid(),
            'playerName' => $reader->getDisplayName(),
            'readUpTo' => $cursor->format(DateFormat::ISO8601_UTC),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->chatTopic($game), $payload));
    }
}
