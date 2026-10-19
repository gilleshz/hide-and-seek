<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Identity::class)]
final class IdentityTest extends TestCase
{
    #[Test]
    public function itCreatesAUuidNameAndHashedDeviceCredential(): void
    {
        $identity = new Identity('Alice', 'device-key');

        self::assertNull($identity->getId());
        self::assertSame(36, \strlen($identity->getUuid()));
        self::assertSame('Alice', $identity->getName());
        self::assertTrue($identity->credentialMatches('device-key'));
        self::assertFalse($identity->credentialMatches('other-device-key'));
        self::assertFalse($identity->isCredentialResetRequested());
        self::assertSame(1, $identity->getTokenEpoch());
    }

    #[Test]
    public function itReplacesTheCredentialAndClearsTheResetFlag(): void
    {
        $identity = new Identity('Alice', 'device-key');
        $identity->requestCredentialReset();

        self::assertTrue($identity->isCredentialResetRequested());

        $identity->replaceCredential('new-device-key');

        self::assertTrue($identity->credentialMatches('new-device-key'));
        self::assertFalse($identity->credentialMatches('device-key'));
        self::assertFalse($identity->isCredentialResetRequested());
        self::assertSame(2, $identity->getTokenEpoch());
    }

    #[Test]
    public function requestingAResetIsIdempotent(): void
    {
        $identity = new Identity('Alice', 'device-key');

        $identity->requestCredentialReset();
        $identity->requestCredentialReset();

        self::assertTrue($identity->isCredentialResetRequested());
    }
}
