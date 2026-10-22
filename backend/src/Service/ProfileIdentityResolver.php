<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Identity;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\IdentityRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Profile calls have no player token outside a game: the name plus the device credential
 * the identity was joined with are the whole proof of ownership.
 */
final readonly class ProfileIdentityResolver
{
    public const string HEADER = 'X-Device-Key';

    public function __construct(
        private IdentityRepository $identities,
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(): Identity
    {
        $request = $this->requestStack->getCurrentRequest();
        $name = $request?->query->get('name');
        $name = is_string($name) ? trim($name) : '';
        $deviceKey = $request?->headers->get(self::HEADER) ?? '';

        if ($name === '' || $deviceKey === '') {
            throw $this->invalidCredential();
        }

        $identity = $this->identities->findByName($name);
        if ($identity === null || !$identity->credentialMatches($deviceKey)) {
            throw $this->invalidCredential();
        }

        return $identity;
    }

    private function invalidCredential(): FunctionalException
    {
        return new FunctionalException(
            message: 'Invalid name or device credential.',
            errorKey: ErrorKey::PROFILE_CREDENTIAL_INVALID,
        );
    }
}
