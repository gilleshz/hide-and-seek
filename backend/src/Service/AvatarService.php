<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use App\Repository\PlayerRepository;
use App\Storage\ImageStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class AvatarService
{
    public const string SCOPE = 'avatars';

    private const int SQUARE_SIZE = 256;

    public function __construct(
        private ImageStorageInterface $storage,
        private IdentityRepository $identities,
        private PlayerRepository $players,
        private RosterNotifier $roster,
        private LoggerInterface $logger,
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

        $this->publishRosterChanged($identity);

        if ($previous !== null) {
            $this->storage->delete(self::SCOPE, $previous);
        }

        return $ref;
    }

    public function remove(Identity $identity): void
    {
        $previous = $identity->getAvatarRef();
        if ($previous === null) {
            return;
        }

        $this->identities->save($identity->replaceAvatar(null));
        $this->publishRosterChanged($identity);
        $this->storage->delete(self::SCOPE, $previous);
    }

    private function publishRosterChanged(Identity $identity): void
    {
        try {
            foreach ($this->players->findActiveGamesByIdentity($identity) as $game) {
                $this->roster->publishChanged($game);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Avatar roster publish failed.', [
                'identity' => $identity->getUuid(),
                'exception' => $exception,
            ]);
        }
    }
}
