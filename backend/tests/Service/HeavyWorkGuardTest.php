<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\HeavyWorkLockRepository;
use App\Service\HeavyWorkGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeavyWorkGuard::class)]
final class HeavyWorkGuardTest extends TestCase
{
    #[Test]
    public function itRunsTheWorkAndReleasesTheLock(): void
    {
        $locks = $this->createMock(HeavyWorkLockRepository::class);
        $locks->expects(self::once())->method('tryAcquireGlobal')->willReturn(true);
        $locks->expects(self::once())->method('releaseGlobal');

        $guard = new HeavyWorkGuard($locks);
        $result = null;
        $guard->run(static function () use (&$result): void {
            $result = 'done';
        });

        self::assertSame('done', $result);
    }

    #[Test]
    public function itRejectsConcurrentWorkWithoutRunningIt(): void
    {
        $locks = $this->createMock(HeavyWorkLockRepository::class);
        $locks->expects(self::once())->method('tryAcquireGlobal')->willReturn(false);
        $locks->expects(self::never())->method('releaseGlobal');

        $guard = new HeavyWorkGuard($locks);
        $ran = false;

        try {
            $guard->run(function () use (&$ran): void {
                $ran = true;
            });
            self::fail('Expected a FunctionalException when the lock is busy.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::HEAVY_WORK_BUSY, $e->getErrorKey());
        }

        self::assertFalse($ran);
    }

    #[Test]
    public function itReleasesTheLockWhenTheWorkThrows(): void
    {
        $locks = $this->createMock(HeavyWorkLockRepository::class);
        $locks->expects(self::once())->method('tryAcquireGlobal')->willReturn(true);
        $locks->expects(self::once())->method('releaseGlobal');

        $guard = new HeavyWorkGuard($locks);

        $this->expectException(\RuntimeException::class);
        $guard->run(static fn (): never => throw new \RuntimeException('boom'));
    }
}
