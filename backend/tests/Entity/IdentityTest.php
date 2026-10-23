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
    public function itCreatesAUuidDisplayNameLookupHashAndHashedDeviceCredential(): void
    {
        $identity = new Identity('Alice', 'device-key', 'lookup-hash');

        self::assertNull($identity->getId());
        self::assertSame(36, \strlen($identity->getUuid()));
        self::assertSame('Alice', $identity->getDisplayName());
        self::assertSame('lookup-hash', $identity->getLookupHash());
        self::assertTrue($identity->credentialMatches('device-key'));
        self::assertFalse($identity->credentialMatches('other-device-key'));
        self::assertSame(1, $identity->getTokenEpoch());
        self::assertGreaterThan(0, $identity->getCreatedAt()->getTimestamp());
    }

    #[Test]
    public function itRenamesTheDisplayNameAndTrimsIt(): void
    {
        $identity = new Identity('Alice', 'device-key', 'lookup-hash');

        self::assertSame($identity, $identity->rename('  Alicia  '));
        self::assertSame('Alicia', $identity->getDisplayName());
    }

    #[Test]
    public function itReplacesAndClearsTheAvatar(): void
    {
        $identity = new Identity('Alice', 'device-key', 'lookup-hash');

        self::assertSame($identity, $identity->replaceAvatar('avatar.jpg'));
        self::assertSame('avatar.jpg', $identity->getAvatarRef());

        $identity->replaceAvatar(null);
        self::assertNull($identity->getAvatarRef());
    }
}
