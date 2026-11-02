<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Reuses the deterministic GTFS-only tile artifacts across games with the same selection.
 *
 * Games with OSM lines are never cached: their boundary clip and OSM merge are not covered here.
 */
final readonly class TransitTileCache
{
    // Bump when the pipeline script or its constants change: older entries no longer match.
    private const string CACHE_VERSION = 'v1';
    private const int MAX_ENTRIES = 8;
    private const string EXCLUDED_DIR = 'timetable';

    public function __construct(
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<array{uuid: string, routeIds: list<string>, refs: list<string>}> $sources
     */
    public function key(array $sources, string $variantFingerprint): string
    {
        usort($sources, static fn (array $a, array $b): int => $a['uuid'] <=> $b['uuid']);

        $canonical = [self::CACHE_VERSION, $variantFingerprint];
        foreach ($sources as $source) {
            $routeIds = array_values(array_unique(array_map(trim(...), $source['routeIds'])));
            $refs = array_values(array_unique(array_map(trim(...), $source['refs'])));
            sort($routeIds);
            sort($refs);
            $canonical[] = $source['uuid'] . "\n"
                . implode("\n", $routeIds) . "\n" . implode("\n", $refs);
        }

        return hash('sha256', implode("\n---\n", $canonical));
    }

    public function has(string $key): bool
    {
        $dir = $this->entryDir($key);

        return is_file($dir . '/overlay.geojson') && is_file($dir . '/line-graph.json');
    }

    public function linkInto(string $key, string $gameTilesDir): void
    {
        $this->linkTree($this->entryDir($key), $gameTilesDir);
    }

    public function store(string $key, string $gameTilesDir): void
    {
        $target = $this->entryDir($key);
        if (is_dir($target)) {
            if ($this->has($key)) {
                return;
            }
            // A crashed build left a partial entry; it must not poison every later run.
            $this->filesystem->remove($target);
        }

        $staging = $this->cacheDir() . '/.staging-' . bin2hex(random_bytes(6));
        try {
            $this->filesystem->mkdir($staging);
            foreach (scandir($gameTilesDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === self::EXCLUDED_DIR) {
                    continue;
                }
                $this->linkTree($gameTilesDir . '/' . $entry, $staging . '/' . $entry);
            }
            $this->filesystem->rename($staging, $target);
        } finally {
            if (is_dir($staging)) {
                $this->filesystem->remove($staging);
            }
        }

        $this->evict();
    }

    private function linkTree(string $source, string $target): void
    {
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
                throw new \RuntimeException("Failed to create directory: {$target}");
            }
            foreach (scandir($source) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->linkTree($source . '/' . $entry, $target . '/' . $entry);
            }

            return;
        }

        if (!@link($source, $target) && !copy($source, $target)) {
            throw new \RuntimeException("Failed to cache {$source}.");
        }
    }

    private function evict(): void
    {
        $entries = [];
        foreach (scandir($this->cacheDir()) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->cacheDir() . '/' . $entry;
            if (is_dir($path)) {
                $entries[$path] = filemtime($path) ?: 0;
            }
        }
        arsort($entries);

        $kept = 0;
        foreach (array_keys($entries) as $path) {
            if (++$kept > self::MAX_ENTRIES) {
                $this->filesystem->remove($path);
            }
        }
    }

    private function entryDir(string $key): string
    {
        return $this->cacheDir() . '/' . $key;
    }

    private function cacheDir(): string
    {
        return $this->tilesBaseDir . '/cache';
    }
}
