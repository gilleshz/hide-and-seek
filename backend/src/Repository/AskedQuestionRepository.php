<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AskedQuestion;
use App\Entity\Round;
use App\Enum\QuestionCategory;
use App\Enum\QuestionStatus;
use App\Enum\RoundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AskedQuestion>
 */
class AskedQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AskedQuestion::class);
    }

    public function findOneByUuid(string $uuid): ?AskedQuestion
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOutstandingByRound(Round $round): ?AskedQuestion
    {
        // An asker who left can never answer or complete theirs, so it must not block the round.
        /** @var AskedQuestion|null $question */
        $question = $this->createQueryBuilder('q')
            ->innerJoin('q.askerPlayer', 'p')
            ->andWhere('q.round = :round')
            ->andWhere('q.revealedAt IS NULL')
            ->andWhere('q.status = :status')
            ->andWhere('p.leftAt IS NULL')
            ->setParameter('round', $round)
            ->setParameter('status', QuestionStatus::Open)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $question;
    }

    /**
     * @param list<RoundStatus> $roundStatuses
     *
     * @return list<AskedQuestion>
     */
    public function findPastRevealDeadline(array $roundStatuses, \DateTimeImmutable $at): array
    {
        $qb = $this->createQueryBuilder('q')
            ->innerJoin('q.round', 'r')
            ->where('q.revealedAt IS NULL')
            ->andWhere('q.status = :open')
            ->andWhere('q.revealDeadlineAt IS NOT NULL')
            ->andWhere('q.revealDeadlineAt <= :at')
            ->andWhere('r.status IN (:roundStatuses)')
            ->setParameter('open', QuestionStatus::Open)
            ->setParameter('at', $at)
            ->setParameter('roundStatuses', $roundStatuses)
            ->orderBy('q.askedAt', 'ASC')
            ->getQuery();

        /** @var list<AskedQuestion> */
        return $qb->getResult();
    }

    /**
     * Randomized-away questions were replaced, never answered, so they don't count.
     *
     * @return list<AskedQuestion>
     */
    public function findAnsweredOrVetoedByRound(Round $round): array
    {
        $qb = $this->createQueryBuilder('q')
            ->where('q.round = :round')
            ->andWhere('q.status != :randomized')
            ->setParameter('round', $round)
            ->setParameter('randomized', QuestionStatus::Randomized)
            ->orderBy('q.askedAt', 'ASC')
            ->getQuery();

        /** @var list<AskedQuestion> */
        return $qb->getResult();
    }

    /**
     * @return list<AskedQuestion>
     */
    public function findByRoundAndCategory(Round $round, QuestionCategory $category): array
    {
        return $this->findBy(['round' => $round, 'category' => $category], ['askedAt' => 'ASC']);
    }

    /**
     * @return list<AskedQuestion>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['askedAt' => 'ASC']);
    }

    public function findByReplacedByUuid(string $replacedByUuid): ?AskedQuestion
    {
        return $this->findOneBy(['replacedByUuid' => $replacedByUuid]);
    }

    /**
     * Hiding is a team side, so several callers can race on the same open question.
     * The row decides: only the caller whose UPDATE matched mutates it, both for
     * replacement and for reveal (teammate taps and deadline auto-reveal race).
     */
    public function claimUnrevealed(AskedQuestion $question, \DateTimeImmutable $at): bool
    {
        $updated = $this->createQueryBuilder('q')
            ->update()
            ->set('q.revealedAt', ':at')
            ->andWhere('q.id = :id')
            ->andWhere('q.revealedAt IS NULL')
            ->andWhere('q.status = :open')
            ->setParameter('at', $at)
            ->setParameter('id', $question->getId())
            ->setParameter('open', QuestionStatus::Open)
            ->getQuery()
            ->execute();

        return $updated === 1;
    }

    public function claimOpen(AskedQuestion $question, QuestionStatus $to): bool
    {
        $updated = $this->createQueryBuilder('q')
            ->update()
            ->set('q.status', ':to')
            ->andWhere('q.id = :id')
            ->andWhere('q.status = :open')
            ->andWhere('q.revealedAt IS NULL')
            ->setParameter('to', $to)
            ->setParameter('id', $question->getId())
            ->setParameter('open', QuestionStatus::Open)
            ->getQuery()
            ->execute();

        return $updated === 1;
    }

    public function remove(AskedQuestion $question, bool $flush = true): void
    {
        $this->getEntityManager()->remove($question);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function save(AskedQuestion $question, bool $flush = true): void
    {
        $this->getEntityManager()->persist($question);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
