<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdentityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IdentityRepository::class)]
#[ORM\Table(name: 'identities')]
#[ORM\UniqueConstraint(name: 'uniq_identities_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_identities_name', columns: ['name'])]
class Identity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 80)]
    private string $name;

    #[ORM\Column(name: 'credential_hash', type: 'string', length: 255)]
    private string $credentialHash;

    #[ORM\Column(name: 'credential_reset_requested', type: 'boolean', options: ['default' => false])]
    private bool $credentialResetRequested = false;

    #[ORM\Column(name: 'avatar_ref', type: 'string', length: 64, nullable: true)]
    private ?string $avatarRef = null;

    #[ORM\Column(name: 'token_epoch', type: 'integer', options: ['default' => 1])]
    private int $tokenEpoch = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $deviceKey)
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->name = $name;
        $this->credentialHash = password_hash($deviceKey, PASSWORD_ARGON2ID);
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function credentialMatches(string $deviceKey): bool
    {
        return password_verify($deviceKey, $this->credentialHash);
    }

    public function isCredentialResetRequested(): bool
    {
        return $this->credentialResetRequested;
    }

    public function getAvatarRef(): ?string
    {
        return $this->avatarRef;
    }

    public function replaceAvatar(?string $avatarRef): self
    {
        $this->avatarRef = $avatarRef;

        return $this;
    }

    public function getTokenEpoch(): int
    {
        return $this->tokenEpoch;
    }

    public function replaceCredential(string $deviceKey): self
    {
        $this->credentialHash = password_hash($deviceKey, PASSWORD_ARGON2ID);
        $this->credentialResetRequested = false;
        ++$this->tokenEpoch;

        return $this;
    }

    public function requestCredentialReset(): self
    {
        $this->credentialResetRequested = true;

        return $this;
    }
}
