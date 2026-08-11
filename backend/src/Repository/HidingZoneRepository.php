<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HidingZone;
use App\Entity\Round;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HidingZone>
 */
class HidingZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HidingZone::class);
    }

    public function findOneByRound(Round $round): ?HidingZone
    {
        return $this->findOneBy(['round' => $round]);
    }

    public function save(HidingZone $zone, bool $flush = true): void
    {
        $this->getEntityManager()->persist($zone);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
