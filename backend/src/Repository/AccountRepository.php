<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findByName(string $name): ?Account
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * The account name is unique server-wide, not per game: two concurrent joins with the same name
     * would both pass findByName() and race on the unique index. The xact lock serialises them for
     * the whole transaction and releases at commit/rollback.
     */
    public function lockName(string $name): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:name)::bigint)',
            ['name' => $name],
        );
    }

    public function save(Account $account, bool $flush = true): void
    {
        $this->getEntityManager()->persist($account);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Account $account): void
    {
        $this->getEntityManager()->remove($account);
        $this->getEntityManager()->flush();
    }
}
