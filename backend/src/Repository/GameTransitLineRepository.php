<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GameTransitLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameTransitLine>
 */
class GameTransitLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameTransitLine::class);
    }

    public function save(GameTransitLine $line, bool $flush = true): void
    {
        $this->getEntityManager()->persist($line);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<GameTransitLine> */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game]);
    }

    public function findOneByGameAndOsm(Game $game, string $osmType, int $osmId): ?GameTransitLine
    {
        return $this->findOneBy(['game' => $game, 'osmType' => $osmType, 'osmId' => $osmId]);
    }

    public function findOneByGameAndUuid(Game $game, string $uuid): ?GameTransitLine
    {
        return $this->findOneBy(['game' => $game, 'uuid' => $uuid]);
    }

    public function deleteByGame(Game $game): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM game_transit_lines WHERE game_id = :gameId', ['gameId' => $game->getId()]);
    }
}
