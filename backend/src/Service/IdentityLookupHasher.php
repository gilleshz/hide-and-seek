<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Device keys never reach the database in plaintext: the HMAC gives a deterministic, secret-keyed
 * lookup column while the argon2 credential stays the actual proof of ownership.
 */
final readonly class IdentityLookupHasher
{
    public function __construct(
        #[Autowire('%env(IDENTITY_LOOKUP_KEY)%')]
        private string $secret,
    ) {
    }

    public function lookup(string $deviceKey): string
    {
        return hash_hmac('sha256', $deviceKey, $this->secret);
    }
}
