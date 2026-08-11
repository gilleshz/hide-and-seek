<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Account;

/**
 * Players no longer own their credential: the account is the server-wide identity, so entity and
 * service tests build one account per player, naming it exactly as the test uses the display name.
 */
final class AccountFactory
{
    public static function create(string $name, string $password = 'test-password'): Account
    {
        return new Account($name, $password);
    }
}
