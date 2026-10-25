<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GtfsSource;
use App\Service\GtfsService;
use App\Service\GtfsSubsetService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Real-feed validation, run only when the standard IDFM feed is staged in the container.
 *
 * Stage it with:
 *   docker compose cp /tmp/paris-audit/paris.zip app:/tmp/paris.zip
 * then run this test with --filter GtfsSubsetLargeFeedTest.
 */
final class GtfsSubsetLargeFeedTest extends KernelTestCase
{
    private ?GtfsSource $source = null;
    private ?string $feedCopy = null;
    private ?string $mergedDir = null;

    #[Test]
    public function itBuildsAndParsesTheParisRerMetroTramSubset(): void
    {
        $feed = '/tmp/paris.zip';
        if (!is_file($feed)) {
            self::markTestSkipped('Paris feed not staged at /tmp/paris.zip');
        }

        self::bootKernel();
        $container = self::getContainer();
        $subsets = $container->get(GtfsSubsetService::class);
        self::assertInstanceOf(GtfsSubsetService::class, $subsets);
        $gtfs = $container->get(GtfsService::class);
        self::assertInstanceOf(GtfsService::class, $gtfs);

        $this->feedCopy = sys_get_temp_dir() . '/paris-large-feed-' . bin2hex(random_bytes(4)) . '.zip';
        copy($feed, $this->feedCopy);
        $this->source = new GtfsSource('Paris IDFM', $this->feedCopy);

        // Metro + tram: the rail/RER subset alone takes over 300 s in gtfs2graph on the 58-route run.
        [$routeIds, $refs] = $this->selectRoutes($this->feedCopy, ['0', '1']);
        self::assertCount(33, $routeIds);

        $started = microtime(true);
        $subsetPath = $subsets->build($this->source, $routeIds);
        $seconds = microtime(true) - $started;

        $gtfs->validate($subsetPath);
        $subsetBytes = (int) filesize($subsetPath);
        fwrite(STDERR, sprintf(
            "\nParis subset: %d routes, %d bytes, built in %.1fs\n",
            count($routeIds),
            $subsetBytes,
            $seconds,
        ));

        self::assertLessThanOrEqual(GtfsService::SUBSET_MAX_TOTAL_BYTES, $subsetBytes);

        $this->mergedDir = sys_get_temp_dir() . '/paris-large-feed-merged-' . bin2hex(random_bytes(4));
        $merge = new Process([
            'gtfsmerge',
            '--output',
            $this->mergedDir,
            '--source',
            'src1=' . $subsetPath,
            '--routes',
            implode(',', $refs),
            '--stop-merge-radius',
            '200',
        ]);
        $merge->setTimeout(300);
        $merge->run();
        self::assertTrue($merge->isSuccessful(), $merge->getErrorOutput());

        $graph = new Process(['gtfs2graph', $this->mergedDir]);
        $graph->setTimeout(300);
        $graph->run();
        self::assertTrue($graph->isSuccessful(), $graph->getErrorOutput());
        self::assertNotSame('', trim($graph->getOutput()));
    }

    protected function tearDown(): void
    {
        if ($this->source !== null) {
            $container = self::getContainer();
            $gtfs = $container->get(GtfsService::class);
            self::assertInstanceOf(GtfsService::class, $gtfs);
            $gtfs->deleteFile($this->source);
        }
        if ($this->mergedDir !== null) {
            (new Filesystem())->remove($this->mergedDir);
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $routeTypes
     *
     * @return array{list<string>, list<string>}
     */
    private function selectRoutes(string $feed, array $routeTypes): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($feed));
        $stream = $zip->getStream('routes.txt');
        self::assertIsResource($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        self::assertIsArray($header);
        $map = array_flip(array_map(static fn (string|null $h): string => (string) $h, $header));
        $routeIds = [];
        $refs = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $type = $row[$map['route_type']] ?? '';
            if (!in_array($type, $routeTypes, true)) {
                continue;
            }
            $routeIds[] = (string) ($row[$map['route_id']] ?? '');
            $refs[] = (string) ($row[$map['route_short_name']] ?? '');
        }
        fclose($stream);
        $zip->close();

        return [
            array_values(array_unique(array_filter($routeIds, static fn (string $id): bool => $id !== ''))),
            array_values(array_unique($refs)),
        ];
    }
}
