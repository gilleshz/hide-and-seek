<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\HeavyWorkLockRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PostgreSQL advisory locks stack per session: a second acquire in the same session succeeds (the
 * session holds it twice), while a different session would fail until every hold is released. The
 * app relies on the cross-session exclusion (each request owns its connection); this test pins the
 * stacking + balance semantics on the real database.
 */
final class HeavyWorkLockRepositoryTest extends KernelTestCase
{
    private HeavyWorkLockRepository $locks;

    protected function setUp(): void
    {
        self::bootKernel();
        $locks = self::getContainer()->get(HeavyWorkLockRepository::class);
        self::assertInstanceOf(HeavyWorkLockRepository::class, $locks);
        $this->locks = $locks;
    }

    #[Test]
    public function theGlobalLockStacksPerSessionAndIsReusableAfterRelease(): void
    {
        self::assertTrue($this->locks->tryAcquireGlobal(), 'First acquire must succeed.');

        // Same-session re-acquire succeeds because PG stacks advisory locks per session.
        self::assertTrue($this->locks->tryAcquireGlobal());

        $this->locks->releaseGlobal();
        $this->locks->releaseGlobal();

        self::assertTrue($this->locks->tryAcquireGlobal(), 'After a balanced release the lock is acquirable again.');
        $this->locks->releaseGlobal();
    }
}
