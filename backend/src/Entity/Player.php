<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\Table(name: 'players')]
#[ORM\UniqueConstraint(name: 'uniq_player_game_account', columns: ['game_id', 'account_id'])]
class Player
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

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Soft-leave: keeps the player's messages, read receipts and side, and
     * coming back under the same account reinstates the same player.
     */
    #[ORM\Column(name: 'left_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(Game $game, Account $account)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->game = $game;
        $this->account = $account;
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

    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * The name lives on the account; the getter stays so the ~15 call sites keep working.
     */
    public function getDisplayName(): string
    {
        return $this->account->getName();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function hasLeft(): bool
    {
        return $this->leftAt !== null;
    }

    public function markLeft(\DateTimeImmutable $leftAt): self
    {
        $this->leftAt = $leftAt;

        return $this;
    }

    public function markReturned(): self
    {
        $this->leftAt = null;

        return $this;
    }
}
