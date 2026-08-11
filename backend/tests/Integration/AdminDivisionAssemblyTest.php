<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Repository\FeatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminDivisionAssemblyTest extends KernelTestCase
{
    private const string RING_MLS = 'MULTILINESTRING((0 0, 0 0.01), (0 0.01, 0.01 0.01), '
        . '(0.01 0.01, 0.01 0), (0.01 0, 0 0))';

    private EntityManagerInterface $em;
    private FeatureRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(FeatureRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(FeatureRepository::class, $repository);
        $this->em = $em;
        $this->repository = $repository;
    }

    #[Test]
    public function itAssemblesOuterMemberWaysIntoAContainingPolygon(): void
    {
        $game = $this->persistBoundedGame();

        $inserted = $this->repository->insertAssembledAdminDivision(
            $game,
            FeatureType::AdminBoundary3rd,
            'Testville',
            self::RING_MLS,
        );
        self::assertTrue($inserted);

        self::assertSame(1, $this->repository->countByGameAndType($game, FeatureType::AdminBoundary3rd));
        self::assertTrue($this->storedGeometryIsPolygon($game));
        self::assertTrue($this->polygonContains($game, new Point(0.005, 0.005)));

        $nearest = $this->repository->findNearestWithin(
            $game,
            FeatureType::AdminBoundary3rd,
            new Point(0.005, 0.005),
            1,
        );
        self::assertCount(1, $nearest);
        self::assertSame('Testville', $nearest[0]->getName());
    }

    private function polygonContains(Game $game, Point $point): bool
    {
        $sql = <<<'SQL'
            SELECT ST_Contains(ST_GeomFromText(geometry, 4326), ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)) AS c
            FROM features WHERE game_id = :gameId AND feature_type = :ftype
        SQL;

        $result = $this->em->getConnection()->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => FeatureType::AdminBoundary3rd->value,
            'lng' => $point->getLongitude(),
            'lat' => $point->getLatitude(),
        ]);

        return (bool) ($result['c'] ?? false);
    }

    private function storedGeometryIsPolygon(Game $game): bool
    {
        $sql = <<<'SQL'
            SELECT GeometryType(ST_GeomFromText(geometry, 4326)) AS gtype
            FROM features WHERE game_id = :gameId AND feature_type = :ftype
        SQL;

        $result = $this->em->getConnection()->fetchAssociative($sql, [
            'gameId' => $game->getId(),
            'ftype' => FeatureType::AdminBoundary3rd->value,
        ]);

        $gtype = $result['gtype'] ?? null;

        return is_string($gtype) && str_contains($gtype, 'POLYGON');
    }

    private function persistBoundedGame(): Game
    {
        $game = new Game('Testville', GameSize::Medium, Edition::Metric);
        $game->setBoundary(-0.1, -0.1, 0.1, 0.1);
        $round = new Round($game);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->flush();

        return $game;
    }
}
