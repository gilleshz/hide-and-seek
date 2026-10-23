<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An identity is device-keyed: the HMAC lookup finds the row, and the argon2 credential proves
 * ownership. The display name is only a label, so an existing device keeps the server-side name
 * and duplicate display names are allowed.
 */
final readonly class IdentityService
{
    public function __construct(
        private IdentityRepository $identities,
        private IdentityLookupHasher $hasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveOrCreate(string $deviceKey, string $displayName): Identity
    {
        $lookupHash = $this->hasher->lookup($deviceKey);

        return $this->entityManager->wrapInTransaction(
            fn (): Identity => $this->resolveOrCreateTransactional($lookupHash, $deviceKey, $displayName),
        );
    }

    private function resolveOrCreateTransactional(string $lookupHash, string $deviceKey, string $displayName): Identity
    {
        // The xact lock plus READ COMMITTED makes a unique-violation retry unnecessary.
        $this->identities->lockLookup($lookupHash);
        $identity = $this->identities->findByLookupHash($lookupHash);
        if ($identity !== null) {
            return $identity;
        }

        $identity = new Identity(trim($displayName), $deviceKey, $lookupHash);
        $this->identities->save($identity);

        return $identity;
    }
}
