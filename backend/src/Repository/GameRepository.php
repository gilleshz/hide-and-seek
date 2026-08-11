<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    public function findOneByUuid(string $uuid): ?Game
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(Game $game, bool $flush = true): void
    {
        $this->getEntityManager()->persist($game);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Game $game): void
    {
        $this->getEntityManager()->remove($game);
        $this->getEntityManager()->flush();
    }

    /** @return list<Game> */
    public function findAllCreatedBefore(?\DateTimeImmutable $createdBefore): array
    {
        $qb = $this->createQueryBuilder('g')->orderBy('g.id', 'ASC');
        if ($createdBefore !== null) {
            $qb->andWhere('g.createdAt < :before')->setParameter('before', $createdBefore);
        }

        /** @var list<Game> $games */
        $games = $qb->getQuery()->getResult();

        return $games;
    }

    public function countCreatedBefore(?\DateTimeImmutable $createdBefore): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)');
        if ($createdBefore !== null) {
            $qb->andWhere('g.createdAt < :before')->setParameter('before', $createdBefore);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function updateBoundaryFromGeoJson(Game $game, string $geoJson): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            UPDATE games g SET
                boundary_sw_lat = ST_YMin(env),
                boundary_sw_lng = ST_XMin(env),
                boundary_ne_lat = ST_YMax(env),
                boundary_ne_lng = ST_XMax(env)
            FROM (SELECT ST_Envelope(ST_GeomFromGeoJSON(:geoJson)) AS env) u
            WHERE g.id = :gameId
        SQL;
        $conn->executeStatement($sql, ['gameId' => $game->getId(), 'geoJson' => $geoJson]);
    }

    /** @return array{float, float}|null */
    public function getBoundarySpan(Game $game): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row = $conn->fetchAssociative(<<<'SQL'
            SELECT ST_XMax(env)-ST_XMin(env) AS dlng, ST_YMax(env)-ST_YMin(env) AS dlat
            FROM (SELECT ST_Envelope(ST_GeomFromGeoJSON(boundary_geo_json)) AS env
                  FROM games WHERE id = :gameId AND boundary_geo_json IS NOT NULL) u
        SQL, ['gameId' => $game->getId()]);

        if ($row === false || !isset($row['dlng'], $row['dlat'])) {
            return null;
        }

        assert(is_numeric($row['dlng']) && is_numeric($row['dlat']));

        return [(float) $row['dlng'], (float) $row['dlat']];
    }
}
