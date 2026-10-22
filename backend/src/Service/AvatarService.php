<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use App\Storage\ImageStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class AvatarService
{
    public const string SCOPE = 'avatars';

    private const int SQUARE_SIZE = 256;

    public function __construct(
        private ImageStorageInterface $storage,
        private IdentityRepository $identities,
    ) {
    }

    public function replace(Identity $identity, UploadedFile $file): string
    {
        $previous = $identity->getAvatarRef();
        $ref = $this->storage->store(self::SCOPE, $file, self::SQUARE_SIZE);

        try {
            $this->identities->save($identity->replaceAvatar($ref));
        } catch (\Throwable $exception) {
            $this->storage->delete(self::SCOPE, $ref);

            throw $exception;
        }

        if ($previous !== null) {
            $this->storage->delete(self::SCOPE, $previous);
        }

        return $ref;
    }

    public function remove(Identity $identity): void
    {
        $previous = $identity->getAvatarRef();
        $this->identities->save($identity->replaceAvatar(null));

        if ($previous !== null) {
            $this->storage->delete(self::SCOPE, $previous);
        }
    }
}
