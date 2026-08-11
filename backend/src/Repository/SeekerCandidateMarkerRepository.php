<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Round;
use App\Entity\SeekerCandidateMarker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeekerCandidateMarker>
 */
class SeekerCandidateMarkerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeekerCandidateMarker::class);
    }

    /**
     * @return list<SeekerCandidateMarker>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['createdAt' => 'ASC']);
    }

    public function findOneByUuid(string $uuid): ?SeekerCandidateMarker
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(SeekerCandidateMarker $marker, bool $flush = true): void
    {
        $this->getEntityManager()->persist($marker);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SeekerCandidateMarker $marker, bool $flush = true): void
    {
        $this->getEntityManager()->remove($marker);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
