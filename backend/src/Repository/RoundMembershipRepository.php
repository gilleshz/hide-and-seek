<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Side;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoundMembership>
 */
class RoundMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoundMembership::class);
    }

    public function findOneByRoundAndPlayer(Round $round, Player $player): ?RoundMembership
    {
        return $this->findOneBy(['round' => $round, 'player' => $player]);
    }

    /**
     * Hiding is a team side: everything addressed to "the hider" has to reach all of them.
     *
     * @return list<RoundMembership>
     */
    public function findHidersByRound(Round $round): array
    {
        /** @var list<RoundMembership> $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->innerJoin('m.player', 'p')
            ->andWhere('m.round = :round')
            ->andWhere('m.side = :side')
            ->andWhere('p.leftAt IS NULL')
            ->setParameter('round', $round)
            ->setParameter('side', Side::Hider)
            ->getQuery()
            ->getResult();

        return $memberships;
    }

    /**
     * Leaving drops the player's sides: a departed hider must not stay the round's hider, nor be
     * seeded into the next round, nor have a side to hand back on a rejoin.
     */
    public function removeByPlayer(Player $player): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.player = :player')
            ->setParameter('player', $player)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<RoundMembership>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round]);
    }

    public function save(RoundMembership $membership, bool $flush = true): void
    {
        $this->getEntityManager()->persist($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
