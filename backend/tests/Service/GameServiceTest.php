<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameInput;
use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\RoundStatus;
use App\Repository\FeatureRepository;
use App\Repository\GameRepository;
use App\Service\BoundaryService;
use App\Service\GameCleanupService;
use App\Service\GameService;
use App\Service\NominatimService;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use App\Service\TransitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GameServiceTest extends KernelTestCase
{
    private const string LOOKUP_RESPONSE = <<<'JSON'
    [{"osm_type":"relation","osm_id":123,"display_name":"Testville",
      "address":{"admin_level":"8"},
      "geojson":{"type":"Polygon","coordinates":[[[6.60,46.50],[6.65,46.50],[6.65,46.55],[6.60,46.55],[6.60,46.50]]]}}]
    JSON;

    #[Test]
    public function createResolvesANonEmptyAdminLevelMapForTheBoundary(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $nominatim = new NominatimService(
            new MockHttpClient([
                new MockResponse(self::LOOKUP_RESPONSE),
                new MockResponse('{"address":{"country_code":"fr"}}'),
            ]),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
        $container->set(NominatimService::class, $nominatim);

        $this->mockOverpassAndTransit($container);

        $service = $container->get(GameService::class);
        self::assertInstanceOf(GameService::class, $service);

        $input = new GameInput();
        $input->name = 'Admin Levels';
        $input->size = GameSize::Medium;
        $input->edition = Edition::Metric;
        $input->areas = [['osmType' => 'relation', 'osmId' => 123]];

        $game = $service->create($input);

        $adminLevels = $game->getAdminLevels();
        self::assertNotNull($adminLevels);
        self::assertNotEmpty($adminLevels);
        self::assertSame([1 => 4, 2 => 6, 3 => 8, 4 => 9], $adminLevels);
    }

    #[Test]
    public function purgeDeletesTheGameAndItsUploadedFilesAndTiles(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var GameRepository $games */
        $games = $container->get(GameRepository::class);
        /** @var GameCleanupService $cleanup */
        $cleanup = $container->get(GameCleanupService::class);

        $game = new Game('Purge Me', GameSize::Medium, Edition::Metric);
        $em->persist($game);
        $em->flush();
        $uuid = $game->getUuid();

        $tilesBaseDir = $container->getParameter('app.tiles_dir');
        self::assertIsString($tilesBaseDir);
        $chatDir = $this->makeFileIn($this->chatImageDir() . '/' . $uuid, 'image.jpg');
        $tilesDir = $this->makeFileIn($tilesBaseDir . '/' . $uuid, 'overlay.pmtiles');

        $purged = $cleanup->purge($game);

        self::assertSame($uuid, $purged->uuid);
        self::assertNull($games->findOneByUuid($uuid));
        self::assertDirectoryDoesNotExist($chatDir);
        self::assertDirectoryDoesNotExist($tilesDir);
        self::assertContains($chatDir, $purged->removedPaths);
        self::assertContains($tilesDir, $purged->removedPaths);
    }

    #[Test]
    public function cleanupSkipsInProgressGamesUnlessIncluded(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var GameRepository $games */
        $games = $container->get(GameRepository::class);
        /** @var GameService $service */
        $service = $container->get(GameService::class);

        $uuid = $this->persistRunningGame($em);

        $skipResult = $service->cleanup(null, false);
        self::assertNotNull($games->findOneByUuid($uuid));
        self::assertGreaterThanOrEqual(1, $skipResult->skippedInProgress);

        $purgeResult = $service->cleanup(null, true);
        self::assertNull($games->findOneByUuid($uuid));
        self::assertGreaterThanOrEqual(1, $purgeResult->deletedCount());
    }

    private function persistRunningGame(EntityManagerInterface $em): string
    {
        $game = new Game('In Progress', GameSize::Medium, Edition::Metric);
        $round = new Round($game)->setStatus(RoundStatus::Hiding);
        $em->persist($game);
        $em->persist($round);
        $em->flush();

        return $game->getUuid();
    }

    private function makeFileIn(string $dir, string $file): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
        file_put_contents("{$dir}/{$file}", 'x');

        return $dir;
    }

    private function chatImageDir(): string
    {
        $dir = $_SERVER['CHAT_IMAGE_DIR'] ?? getenv('CHAT_IMAGE_DIR');

        return is_string($dir) && $dir !== '' ? $dir : '/tmp/jetlag-test-images';
    }

    private static function mockOverpassAndTransit(ContainerInterface $container): void
    {
        if ($container->has(OverpassService::class)) {
            /** @var FeatureRepository $features */
            $features = $container->get(FeatureRepository::class);
            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);
            $overpass = new OverpassService(
                new OverpassHttpClient(new MockHttpClient([new MockResponse('{"elements":[]}')]), 'https://test.example/api/', false),
                $features,
                $em,
            );
            $container->set(OverpassService::class, $overpass);
        }
        if ($container->has(TransitService::class)) {
            /** @var BoundaryService $boundaryService */
            $boundaryService = $container->get(BoundaryService::class);
            $transit = new TransitService(
                new OverpassHttpClient(new MockHttpClient([new MockResponse('{"elements":[]}')]), 'https://test.example/api/', false),
                32_000_000,
                $boundaryService,
            );
            $container->set(TransitService::class, $transit);
        }
    }
}
