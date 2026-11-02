<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TransitTileCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TransitTileCacheTest extends TestCase
{
    private string $root;
    private TransitTileCache $cache;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/transit-cache-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->cache = new TransitTileCache($this->root, new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    public function itKeysTheSameSelectionStablyRegardlessOfOrder(): void
    {
        $sources = [
            ['uuid' => 'b', 'routeIds' => ['R2', ' R1 '], 'refs' => ['2', '1']],
            ['uuid' => 'a', 'routeIds' => ['X'], 'refs' => ['x']],
        ];

        $key = $this->cache->key($sources, '0.030000');

        self::assertSame($key, $this->cache->key(array_reverse($sources), '0.030000'));
    }

    #[Test]
    public function itSensitisesTheKeyToEveryInput(): void
    {
        $base = ['uuid' => 'a', 'routeIds' => ['R1'], 'refs' => ['1']];
        $key = $this->cache->key([$base], '0.030000');

        self::assertNotSame($key, $this->cache->key([$base], '0.500000'));
        self::assertNotSame($key, $this->cache->key([['uuid' => 'b'] + $base], '0.030000'));
        self::assertNotSame($key, $this->cache->key([['routeIds' => ['R2']] + $base], '0.030000'));
        self::assertNotSame($key, $this->cache->key([['refs' => ['2']] + $base], '0.030000'));
    }

    #[Test]
    public function itStoresAndHardlinksTheArtifactsWithoutTheTimetable(): void
    {
        $game = $this->root . '/game';
        mkdir($game . '/14/0', 0o755, true);
        file_put_contents($game . '/14/0/0.mvt', 'tile');
        file_put_contents($game . '/overlay.geojson', '{"features":[]}');
        file_put_contents($game . '/line-graph.json', '{}');
        mkdir($game . '/timetable');
        file_put_contents($game . '/timetable/meta.json', '{}');

        $this->cache->store('key', $game);

        self::assertTrue($this->cache->has('key'));
        self::assertDirectoryDoesNotExist($this->root . '/cache/key/timetable');
        self::assertSame('tile', file_get_contents($this->root . '/cache/key/14/0/0.mvt'));

        $game2 = $this->root . '/game2';
        mkdir($game2);
        $this->cache->linkInto('key', $game2);

        self::assertSame('tile', file_get_contents($game2 . '/14/0/0.mvt'));
        self::assertSame(
            fileinode($this->root . '/cache/key/overlay.geojson'),
            fileinode($game2 . '/overlay.geojson'),
        );
    }

    #[Test]
    public function itReplacesAPartialEntryOnStore(): void
    {
        $entry = $this->root . '/cache/key';
        mkdir($entry, 0o755, true);
        file_put_contents($entry . '/overlay.geojson', 'stale');

        $game = $this->root . '/game';
        mkdir($game);
        file_put_contents($game . '/overlay.geojson', '{"fresh":true}');
        file_put_contents($game . '/line-graph.json', '{}');

        $this->cache->store('key', $game);

        self::assertTrue($this->cache->has('key'));
        self::assertSame('{"fresh":true}', file_get_contents($entry . '/overlay.geojson'));
    }

    #[Test]
    public function itOnlyReportsACacheHitWhenTheArtifactsAreComplete(): void
    {
        $entry = $this->root . '/cache/key';
        mkdir($entry, 0o755, true);
        file_put_contents($entry . '/overlay.geojson', '{}');

        self::assertFalse($this->cache->has('key'));

        file_put_contents($entry . '/line-graph.json', '{}');
        self::assertTrue($this->cache->has('key'));
    }

    #[Test]
    public function itKeepsOnlyTheNewestEightEntries(): void
    {
        $game = $this->root . '/game';
        mkdir($game, 0o755, true);
        file_put_contents($game . '/overlay.geojson', '{}');
        file_put_contents($game . '/line-graph.json', '{}');

        for ($i = 0; $i < 8; ++$i) {
            $this->cache->store('k' . $i, $game);
            touch($this->root . '/cache/k' . $i, 1000 + $i);
        }

        $this->cache->store('k8', $game);

        self::assertDirectoryDoesNotExist($this->root . '/cache/k0');
        for ($i = 1; $i <= 8; ++$i) {
            self::assertDirectoryExists($this->root . '/cache/k' . $i);
        }
    }
}
