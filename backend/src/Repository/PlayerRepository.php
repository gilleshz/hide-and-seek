<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Identity;
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
    public function findOneByGameAndIdentity(Game $game, Identity $identity): ?Player
    {
        return $this->findOneBy(['game' => $game, 'identity' => $identity]);
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
        // The identity join keeps the derived name getter free of lazy-loading.
        /** @var list<Player> $players */
        $players = $this->createQueryBuilder('p')
            ->addSelect('ident')
            ->innerJoin('p.identity', 'ident')
            ->where('p.game = :game')
            ->andWhere('p.leftAt IS NULL')
            ->orderBy('p.createdAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->setParameter('game', $game)
            ->getQuery()
            ->getResult();

        return $players;
    }

    /**
     * @return list<Game>
     */
    public function findActiveGamesByIdentity(Identity $identity): array
    {
        // uniq_player_game_identity already yields one row per game, and Game.admin_levels
        // is json, which Postgres cannot DISTINCT over.
        /** @var list<Player> $players */
        $players = $this->createQueryBuilder('p')
            ->addSelect('g')
            ->innerJoin('p.game', 'g')
            ->where('p.identity = :identity')
            ->andWhere('p.leftAt IS NULL')
            ->setParameter('identity', $identity)
            ->getQuery()
            ->getResult();

        return array_map(static fn (Player $player): Game => $player->getGame(), $players);
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
