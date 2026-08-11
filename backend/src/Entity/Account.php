<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $name;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $password)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function passwordMatches(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function resetPassword(string $password): static
    {
        $this->passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        return $this;
    }
}
