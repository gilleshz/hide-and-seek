<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChatMessageType;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_messages')]
#[ORM\Index(name: 'idx_chat_question_uuid', columns: ['question_uuid'])]
#[ORM\Index(name: 'idx_chat_reply_to_uuid', columns: ['reply_to_uuid'])]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private Game $game;

    /**
     * Set null rather than cascade: no live path deletes a Player any more (leaving marks them),
     * but a stray hard delete must not take the game log with it.
     */
    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'sender_id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $sender;

    #[ORM\Column(type: 'string', length: 16, enumType: ChatMessageType::class)]
    private ChatMessageType $type;

    #[ORM\Column(type: 'string', length: 2000, nullable: true)]
    private ?string $body;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $bodyKey;

    /** @var array<string, string|int>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $bodyArgs;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageRef = null;

    #[ORM\Column(name: 'question_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $questionUuid = null;

    #[ORM\Column(name: 'reply_to_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $replyToUuid = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function __construct(
        Game $game,
        ?Player $sender,
        ChatMessageType $type,
        ?string $body = null,
        ?string $bodyKey = null,
        ?array $bodyArgs = null,
    ) {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->sender = $sender;
        $this->type = $type;
        $this->body = $body;
        $this->bodyKey = $bodyKey;
        $this->bodyArgs = $bodyArgs;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getSender(): ?Player
    {
        return $this->sender;
    }

    public function getSenderUuid(): ?string
    {
        return $this->sender?->getUuid();
    }

    public function getSenderName(): ?string
    {
        return $this->sender?->getDisplayName();
    }

    public function getType(): ChatMessageType
    {
        return $this->type;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getBodyKey(): ?string
    {
        return $this->bodyKey;
    }

    public function setBodyKey(?string $bodyKey): self
    {
        $this->bodyKey = $bodyKey;

        return $this;
    }

    /**
     * @return array<string, string|int>|null
     */
    public function getBodyArgs(): ?array
    {
        return $this->bodyArgs;
    }

    /**
     * @param array<string, string|int>|null $bodyArgs
     */
    public function setBodyArgs(?array $bodyArgs): self
    {
        $this->bodyArgs = $bodyArgs;

        return $this;
    }

    public function getImageRef(): ?string
    {
        return $this->imageRef;
    }

    public function setImageRef(?string $imageRef): self
    {
        $this->imageRef = $imageRef;

        return $this;
    }

    public function getQuestionUuid(): ?string
    {
        return $this->questionUuid;
    }

    public function setQuestionUuid(?string $questionUuid): self
    {
        $this->questionUuid = $questionUuid;

        return $this;
    }

    public function getReplyToUuid(): ?string
    {
        return $this->replyToUuid;
    }

    public function setReplyToUuid(?string $replyToUuid): self
    {
        $this->replyToUuid = $replyToUuid;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Drops the content itself, not just a flag: a retracted message must not survive
     * in the database for a later history fetch to hand back.
     */
    public function markDeleted(\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        $this->body = null;
        $this->bodyKey = null;
        $this->bodyArgs = null;
        $this->imageRef = null;

        return $this;
    }
}
