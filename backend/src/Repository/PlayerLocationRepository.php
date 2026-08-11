<?php

declare(strict_types=1);

namespace App\Repository;

use App\DateFormat;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\RoundStatus;
use App\Enum\Side;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerLocation>
 */
class PlayerLocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerLocation::class);
    }

    public function findLatestByRoundAndPlayer(Round $round, Player $player): ?PlayerLocation
    {
        // recordedAt has second precision; without the id tie-break "latest" can jump between pings.
        return $this->findOneBy(
            ['round' => $round, 'player' => $player],
            ['recordedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /**
     * The far end of the segment a trip test runs against. Ordered on the id rather than recordedAt
     * because at second precision a shared ping interval routinely ties.
     */
    public function findPreviousByRoundAndPlayer(
        Round $round,
        Player $player,
        PlayerLocation $latest,
    ): ?PlayerLocation {
        /** @var PlayerLocation|null $previous */
        $previous = $this->createQueryBuilder('l')
            ->andWhere('l.round = :round')
            ->andWhere('l.player = :player')
            ->andWhere('l.id < :latestId')
            ->orderBy('l.id', 'DESC')
            ->setParameter('round', $round)
            ->setParameter('player', $player)
            ->setParameter('latestId', $latest->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $previous;
    }

    /**
     * The hiding team stays together, so any hider's ping can answer a question, but a phone that
     * fell asleep must not answer from where it used to be: freshest ping wins, id breaks the tie.
     * A hider who left is excluded; one who never pinged this round has no row to join to.
     */
    public function findFreshestHiderLocationByRound(Round $round): ?PlayerLocation
    {
        /** @var PlayerLocation|null $location */
        $location = $this->createQueryBuilder('l')
            ->innerJoin('l.player', 'p')
            ->innerJoin(
                RoundMembership::class,
                'm',
                Join::WITH,
                'm.player = l.player AND m.round = l.round',
            )
            ->andWhere('l.round = :round')
            ->andWhere('m.side = :side')
            ->andWhere('p.leftAt IS NULL')
            ->orderBy('l.recordedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setParameter('round', $round)
            ->setParameter('side', Side::Hider)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $location;
    }

    public function save(PlayerLocation $location, bool $flush = true): void
    {
        $this->getEntityManager()->persist($location);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Only ended rounds' locations are safe to drop: every consumer reads locations while the
     * round is Hiding/Seeking and never after it ends.
     */
    public function deleteStale(\DateTimeImmutable $before): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->executeStatement(
            'DELETE FROM player_locations l
             USING rounds r
             WHERE r.id = l.round_id AND r.status = :status AND l.recorded_at < :before',
            [
                'status' => RoundStatus::Ended->value,
                'before' => $before->format(DateFormat::ISO8601_UTC),
            ],
        );
    }
}
