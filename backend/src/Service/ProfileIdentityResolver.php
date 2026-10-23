<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\IdentityRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Profile calls have no player token outside a game: the device key header the identity was
 * created with is the whole proof of ownership.
 */
final readonly class ProfileIdentityResolver
{
    public const string HEADER = 'X-Device-Key';

    public function __construct(
        private IdentityRepository $identities,
        private IdentityLookupHasher $hasher,
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(): Identity
    {
        $deviceKey = $this->deviceKey();
        $identity = $this->identities->findByLookupHash($this->hasher->lookup($deviceKey));
        if ($identity === null || !$identity->credentialMatches($deviceKey)) {
            throw $this->invalidCredential();
        }

        return $identity;
    }

    public function deviceKey(): string
    {
        $deviceKey = $this->requestStack->getCurrentRequest()?->headers->get(self::HEADER) ?? '';
        if ($deviceKey === '') {
            throw $this->invalidCredential();
        }

        return $deviceKey;
    }

    private function invalidCredential(): FunctionalException
    {
        return new FunctionalException(
            message: 'Invalid device credential.',
            errorKey: ErrorKey::PROFILE_CREDENTIAL_INVALID,
        );
    }
}
