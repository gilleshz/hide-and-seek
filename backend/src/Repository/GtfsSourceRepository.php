<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GtfsSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GtfsSource>
 */
class GtfsSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GtfsSource::class);
    }

    public function save(GtfsSource $source, bool $flush = true): void
    {
        $this->getEntityManager()->persist($source);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByUuid(string $uuid): ?GtfsSource
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /** @return list<GtfsSource> */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game]);
    }

    public function deleteByGame(Game $game): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM gtfs_source WHERE game_id = :gameId', ['gameId' => $game->getId()]);
    }

    /** @return list<GtfsSource> */
    public function findOrphansCreatedBefore(\DateTimeImmutable $before): array
    {
        $query = $this->createQueryBuilder('s')
            ->where('s.game IS NULL')
            ->andWhere('s.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery();

        /** @var list<GtfsSource> $result */
        $result = $query->getResult();

        return $result;
    }

    public function remove(GtfsSource $source, bool $flush = true): void
    {
        $this->getEntityManager()->remove($source);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
