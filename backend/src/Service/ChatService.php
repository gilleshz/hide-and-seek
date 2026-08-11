<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Enum\ChatMessageType;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\ChatMessageRepository;
use App\Storage\ImageStorageInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class ChatService
{
    public function __construct(
        private ChatMessageRepository $messages,
        private MercureJwtService $mercure,
        private HubInterface $hub,
        private ImageStorageInterface $storage,
    ) {
    }

    public function postText(Game $game, Player $sender, string $body, ?string $replyToUuid = null): ChatMessage
    {
        $message = new ChatMessage($game, $sender, ChatMessageType::Text, $body);
        $message->setReplyToUuid($this->resolveReplyTarget($game, $replyToUuid));

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postSystem(Game $game, string $body, ?string $bodyKey = null, ?array $bodyArgs = null): ChatMessage
    {
        return $this->post(new ChatMessage(
            $game,
            null,
            ChatMessageType::System,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        ));
    }

    /**
     * A zone card is physical, so the play carries its photo in the same message as the effect,
     * rather than a photo plus a separate note.
     *
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postCardPlay(
        Game $game,
        Player $sender,
        string $imageRef,
        string $body,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ): ChatMessage {
        $message = new ChatMessage(
            $game,
            $sender,
            ChatMessageType::Text,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setImageRef($imageRef);

        return $this->post($message);
    }

    public function postImage(
        Game $game,
        Player $sender,
        string $imageRef,
        ?string $caption,
        ?string $replyToUuid = null,
    ): ChatMessage {
        $message = new ChatMessage($game, $sender, ChatMessageType::Image, $caption);
        $message->setImageRef($imageRef)
            ->setReplyToUuid($this->resolveReplyTarget($game, $replyToUuid));

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postQuestion(
        Game $game,
        Player $sender,
        string $body,
        string $questionUuid,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ): ChatMessage {
        $message = new ChatMessage(
            $game,
            $sender,
            ChatMessageType::Question,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setQuestionUuid($questionUuid);

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postQuestionInfo(
        Game $game,
        Player $sender,
        string $body,
        string $questionUuid,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ): ChatMessage {
        $message = new ChatMessage(
            $game,
            $sender,
            ChatMessageType::QuestionInfo,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setQuestionUuid($questionUuid);

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postAnswer(
        Game $game,
        Player $sender,
        string $body,
        string $questionUuid,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ): ChatMessage {
        $askMessage = $this->messages->findOneByQuestionUuidAndType($questionUuid, ChatMessageType::Question);
        $message = new ChatMessage(
            $game,
            $sender,
            ChatMessageType::Answer,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setQuestionUuid($questionUuid)->setReplyToUuid($askMessage?->getUuid());

        return $this->post($message);
    }

    public function postPhotoAnswer(
        Game $game,
        Player $sender,
        string $imageRef,
        string $questionUuid,
    ): ChatMessage {
        $askMessage = $this->messages->findOneByQuestionUuidAndType($questionUuid, ChatMessageType::Question);
        $message = new ChatMessage($game, $sender, ChatMessageType::Answer, null);
        $message->setImageRef($imageRef)
            ->setQuestionUuid($questionUuid)
            ->setReplyToUuid($askMessage?->getUuid());

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postQuestionSystemNotice(
        Game $game,
        string $body,
        string $questionUuid,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ): ChatMessage {
        $latestMessage = $this->messages->findLatestByQuestionUuid($questionUuid);
        $message = new ChatMessage(
            $game,
            null,
            ChatMessageType::System,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setQuestionUuid($questionUuid)
            ->setReplyToUuid($latestMessage?->getUuid());

        return $this->post($message);
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function postQuestionCancelled(
        Game $game,
        Player $sender,
        string $body,
        string $questionUuid,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
        ?string $imageRef = null,
    ): ChatMessage {
        $latestMessage = $this->messages->findLatestByQuestionUuid($questionUuid);
        $message = new ChatMessage(
            $game,
            $sender,
            ChatMessageType::Text,
            $body,
            bodyKey: $bodyKey,
            bodyArgs: $bodyArgs,
        );
        $message->setQuestionUuid($questionUuid)
            ->setReplyToUuid($latestMessage?->getUuid())
            ->setImageRef($imageRef);

        return $this->post($message);
    }

    public function deleteMessage(Game $game, Player $sender, string $messageUuid): ChatMessage
    {
        $message = $this->messages->findOneByUuid($messageUuid);
        if ($message === null || $message->getGame()->getUuid() !== $game->getUuid()) {
            throw new EntityNotFoundException(
                message: 'Chat message not found in this game.',
                errorKey: 'chat.message_not_found',
            );
        }

        if ($message->getSenderUuid() !== $sender->getUuid()) {
            throw new FunctionalException(
                message: 'Only the sender of a message can delete it.',
                errorKey: 'chat.not_your_message',
            );
        }

        if (!$message->getType()->isRetractable()) {
            throw new FunctionalException(
                message: 'This kind of message cannot be deleted.',
                errorKey: 'chat.message_not_retractable',
            );
        }

        if (!$message->isDeleted()) {
            $this->retract($game, $message);
        }

        return $message;
    }

    private function retract(Game $game, ChatMessage $message): void
    {
        $imageRef = $message->getImageRef();
        $this->messages->save($message->markDeleted(new \DateTimeImmutable()));

        if ($imageRef !== null) {
            $this->storage->delete($game->getUuid(), $imageRef);
        }

        $this->publishDeleted($message);
    }

    private function publishDeleted(ChatMessage $message): void
    {
        $payload = json_encode([
            'type' => 'chat-deleted',
            'uuid' => $message->getUuid(),
            'senderUuid' => $message->getSenderUuid(),
            'deletedAt' => $message->getDeletedAt()?->format(DateFormat::ISO8601_UTC),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->chatTopic($message->getGame()), $payload));
    }

    private function resolveReplyTarget(Game $game, ?string $replyToUuid): ?string
    {
        if ($replyToUuid === null) {
            return null;
        }

        $referenced = $this->messages->findOneByUuid($replyToUuid);
        if ($referenced === null || $referenced->getGame()->getUuid() !== $game->getUuid()) {
            return null;
        }

        return $replyToUuid;
    }

    private function post(ChatMessage $message): ChatMessage
    {
        $this->messages->save($message);

        $this->publish($message);

        return $message;
    }

    private function publish(ChatMessage $message): void
    {
        $payload = json_encode([
            'type' => 'chat',
            'uuid' => $message->getUuid(),
            'senderUuid' => $message->getSenderUuid(),
            'senderName' => $message->getSenderName(),
            'messageType' => $message->getType()->value,
            'body' => $message->getBody(),
            'bodyKey' => $message->getBodyKey(),
            'bodyArgs' => $message->getBodyArgs(),
            'imageRef' => $message->getImageRef(),
            'questionUuid' => $message->getQuestionUuid(),
            'replyToUuid' => $message->getReplyToUuid(),
            'createdAt' => $message->getCreatedAt()->format(DateFormat::ISO8601_UTC),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->chatTopic($message->getGame()), $payload));
    }
}
