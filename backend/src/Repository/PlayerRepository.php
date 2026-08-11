<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Game;
use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Player>
 */
class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * Deliberately finds players who have left too: this is the lookup that reinstates them.
     */
    public function findOneByGameAndAccount(Game $game, Account $account): ?Player
    {
        return $this->findOneBy(['game' => $game, 'account' => $account]);
    }

    public function findOneByUuid(string $uuid): ?Player
    {
        return $this->findOneBy(['uuid' => $uuid, 'leftAt' => null]);
    }

    /**
     * Identity resolution must tell "player left" (401 identity.player_left) apart from "no such
     * player" (401 identity.player_not_found), so it looks through the soft-delete filter.
     */
    public function findOneByUuidIncludingLeft(string $uuid): ?Player
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<Player>
     */
    public function findByGameOrdered(Game $game): array
    {
        // createdAt has second precision, and roster[0] is the host: the id keeps that deterministic.
        // The account join keeps the derived name getter free of lazy-loading.
        /** @var list<Player> $players */
        $players = $this->createQueryBuilder('p')
            ->addSelect('acct')
            ->innerJoin('p.account', 'acct')
            ->where('p.game = :game')
            ->andWhere('p.leftAt IS NULL')
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setParameter('game', $game)
            ->getQuery()
            ->getResult();

        return $players;
    }

    public function save(Player $player, bool $flush = true): void
    {
        $this->getEntityManager()->persist($player);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Player $player): void
    {
        $this->getEntityManager()->remove($player);
        $this->getEntityManager()->flush();
    }
}
