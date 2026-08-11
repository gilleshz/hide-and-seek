<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Account;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Account::class)]
final class AccountTest extends TestCase
{
    #[Test]
    public function itExposesItsNameAndUuid(): void
    {
        $account = new Account('alice', 'test-password');

        self::assertNull($account->getId());
        self::assertSame('alice', $account->getName());
        self::assertSame(36, \strlen($account->getUuid()));
        self::assertTrue($account->getCreatedAt() <= new \DateTimeImmutable());
    }

    #[Test]
    public function itHashesThePasswordAtConstruction(): void
    {
        $account = new Account('alice', 'correct-horse');

        $hash = (new \ReflectionProperty(Account::class, 'passwordHash'))->getValue($account);
        self::assertIsString($hash);
        self::assertNotSame('correct-horse', $hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($account->passwordMatches('correct-horse'));
        self::assertFalse($account->passwordMatches('wrong-password'));
    }

    #[Test]
    public function resetPasswordReplacesTheHash(): void
    {
        $account = new Account('alice', 'old-password');

        $returned = $account->resetPassword('new-password');

        self::assertSame($account, $returned);
        self::assertTrue($account->passwordMatches('new-password'));
        self::assertFalse($account->passwordMatches('old-password'));

        $hash = (new \ReflectionProperty(Account::class, 'passwordHash'))->getValue($account);
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
    }
}
