<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GameGtfsLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameGtfsLine>
 */
class GameGtfsLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameGtfsLine::class);
    }

    public function save(GameGtfsLine $line, bool $flush = true): void
    {
        $this->getEntityManager()->persist($line);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<GameGtfsLine> */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game]);
    }

    public function deleteByGame(Game $game): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM game_gtfs_lines WHERE game_id = :gameId', ['gameId' => $game->getId()]);
    }
}
