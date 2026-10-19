<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Identity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Identity>
 */
class IdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Identity::class);
    }

    public function findByName(string $name): ?Identity
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Identity names are unique server-wide: two concurrent joins with the same name would both
     * pass findByName() and race on the unique index. The xact lock serialises them for the whole
     * transaction and releases at commit/rollback.
     */
    public function lockName(string $name): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:name)::bigint)',
            ['name' => $name],
        );
    }

    public function save(Identity $identity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($identity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
