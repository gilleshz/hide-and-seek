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

    public function findByLookupHash(string $lookupHash): ?Identity
    {
        return $this->findOneBy(['lookupHash' => $lookupHash]);
    }

    /**
     * Device keys are unique server-wide: two concurrent bootstraps would both pass findByLookupHash()
     * and race on the unique index. The xact lock serialises them for the whole transaction and
     * releases at commit/rollback.
     */
    public function lockLookup(string $lookupHash): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:hash, 0))',
            ['hash' => $lookupHash],
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
