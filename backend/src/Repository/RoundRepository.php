<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\RoundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Round>
 */
class RoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Round::class);
    }

    public function findOneByUuid(string $uuid): ?Round
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function hasRunningRound(Game $game): bool
    {
        return $this->count(['game' => $game, 'status' => [RoundStatus::Hiding, RoundStatus::Seeking]]) > 0;
    }

    public function findActiveByGame(Game $game): ?Round
    {
        return $this->findOneBy(
            ['game' => $game, 'status' => [RoundStatus::Lobby, RoundStatus::Hiding, RoundStatus::Seeking]],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function findLatestByGame(Game $game): ?Round
    {
        // createdAt has second precision; the id tie-break keeps "latest" deterministic.
        return $this->findOneBy(['game' => $game], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Round> */
    public function findCaughtByGame(Game $game): array
    {
        /** @var list<Round> $rounds */
        $rounds = $this->createQueryBuilder('r')
            ->andWhere('r.game = :game')
            ->andWhere('r.caught = true')
            ->andWhere('r.seekingEndedAt IS NOT NULL')
            ->setParameter('game', $game)
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rounds;
    }

    /** @param list<RoundStatus> $statuses
     *  @return list<Round> */
    public function findByStatuses(array $statuses): array
    {
        return $this->findBy(['status' => $statuses]);
    }

    /**
     * Incremented in the database, not read-modify-written: two traps confirmed at once would otherwise
     * lose one another's minutes. The in-memory Round is deliberately left untouched, so no later flush
     * can write a stale total back over this.
     */
    public function creditTrapBonusSeconds(Round $round, int $seconds): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.trapBonusSeconds', 'r.trapBonusSeconds + :seconds')
            ->andWhere('r.id = :id')
            ->setParameter('seconds', $seconds)
            ->setParameter('id', $round->getId())
            ->getQuery()
            ->execute();
    }

    public function save(Round $round, bool $flush = true): void
    {
        $this->getEntityManager()->persist($round);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
