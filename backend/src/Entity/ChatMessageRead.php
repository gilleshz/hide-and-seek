<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatMessageReadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatMessageReadRepository::class)]
#[ORM\Table(name: 'chat_message_reads')]
#[ORM\UniqueConstraint(name: 'uniq_chat_read_message_player', columns: ['message_id', 'player_id'])]
#[ORM\Index(name: 'idx_chat_read_player', columns: ['player_id'])]
class ChatMessageRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private ChatMessage $message;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'player_id', nullable: false, onDelete: 'CASCADE')]
    private Player $reader;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $readAt;

    public function __construct(ChatMessage $message, Player $reader, ?\DateTimeImmutable $readAt = null)
    {
        $this->message = $message;
        $this->reader = $reader;
        $this->readAt = $readAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    public function getReader(): Player
    {
        return $this->reader;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
