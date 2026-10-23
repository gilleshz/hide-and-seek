<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use App\Service\IdentityLookupHasher;
use App\Service\IdentityService;
use App\Tests\Support\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentityService::class)]
final class IdentityServiceTest extends TestCase
{
    #[Test]
    public function itCreatesAnIdentityForKeyedByTheDeviceLookupHash(): void
    {
        $created = null;

        $identities = $this->createMock(IdentityRepository::class);
        $identities->expects(self::once())
            ->method('lockLookup')
            ->with(IdentityFactory::lookupHash('device-key'));
        $identities->method('findByLookupHash')->willReturn(null);
        $identities->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (Identity $identity) use (&$created): void {
                $created = $identity;
            });

        $identity = $this->service($identities)->resolveOrCreate('device-key', '  Alice  ');

        self::assertInstanceOf(Identity::class, $created);
        self::assertSame($created, $identity);
        self::assertSame('Alice', $identity->getDisplayName());
        self::assertSame(IdentityFactory::lookupHash('device-key'), $identity->getLookupHash());
        self::assertTrue($identity->credentialMatches('device-key'));
    }

    #[Test]
    public function itReturnsTheExistingIdentityAndIgnoresTheIncomingDisplayName(): void
    {
        $existing = IdentityFactory::create('Alice');

        $identities = $this->createMock(IdentityRepository::class);
        $identities->method('lockLookup');
        $identities->method('findByLookupHash')->willReturn($existing);
        $identities->expects(self::never())->method('save');

        $identity = $this->service($identities)->resolveOrCreate(IdentityFactory::deviceKey('Alice'), 'Alicia');

        self::assertSame($existing, $identity);
        self::assertSame('Alice', $identity->getDisplayName());
    }

    #[Test]
    public function aDifferentDeviceKeyLooksUpADifferentIdentityEvenWithTheSameDisplayName(): void
    {
        $seen = [];

        $identities = $this->createStub(IdentityRepository::class);
        $identities->method('lockLookup')->willReturnCallback(
            static function (string $lookupHash) use (&$seen): void {
                $seen[] = $lookupHash;
            },
        );
        $identities->method('findByLookupHash')->willReturn(null);

        $service = $this->service($identities);
        $first = $service->resolveOrCreate('first-device', 'Alice');
        $second = $service->resolveOrCreate('second-device', 'Alice');

        self::assertSame('Alice', $first->getDisplayName());
        self::assertSame('Alice', $second->getDisplayName());
        self::assertCount(2, $seen);
        self::assertNotSame($seen[0], $seen[1]);
        self::assertNotSame($first, $second);
    }

    private function service(IdentityRepository $identities): IdentityService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return new IdentityService($identities, new IdentityLookupHasher(IdentityFactory::LOOKUP_KEY), $entityManager);
    }
}
