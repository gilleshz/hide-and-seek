<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

readonly class HeavyWorkLockRepository
{
    private const int GLOBAL_LOCK_KEY = 0x6A65746C6167; // 'jetlag' as hex, one fixed key: one heavy job at a time.

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function tryAcquireGlobal(): bool
    {
        $row = $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:key)',
            ['key' => self::GLOBAL_LOCK_KEY],
        );

        return $row === 't' || $row === true || $row === 1;
    }

    public function releaseGlobal(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_unlock(:key)',
            ['key' => self::GLOBAL_LOCK_KEY],
        );
    }
}
