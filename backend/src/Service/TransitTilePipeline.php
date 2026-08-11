<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

readonly class TransitTilePipeline
{
    // Must stay under php's max_execution_time so an overrun surfaces as a tile failure, not a fatal.
    private const int TIMEOUT_SECONDS = 360;
    // transitmap only emits correct web-mercator XYZ tiles at zoom 14; a zoom range misprojects them.
    private const int NATIVE_ZOOM = 14;
    private const int LINE_WIDTH = 1;
    private const int LINE_SPACING = 0;
    // Below this loom's default optimiser is instant and within one crossing of greedy; above it, minutes.
    private const int LOOM_GREEDY_ABOVE_BYTES = 4_000_000;
    private const string LOOM_GREEDY_METHOD = 'greedy-lookahead';
    // topo can splice two disconnected fragments of a route into one edge; see the script's docstring.
    private const int MAX_EDGE_SEGMENT_METRES = 20000;
    private const string JUMP_FILTER_SCRIPT = '/app/bin/drop_graph_jumps.py';
    private const string CLIP_SUFFIX = '.clip.geojson';
    private const string OSM2GTFS_BIN = '/opt/osm2gtfs/bin/osm2gtfs';
    private const string GTFSMERGE_BIN = 'gtfsmerge';
    private const string PYTHON_BIN = '/opt/osm2gtfs/bin/python';
    private const string DECODE_SCRIPT = '/app/bin/mvt_to_geojson.py';
    public const string OVERLAY_FILE = 'overlay.geojson';
    // Teed out before transitmap: carries exact station coords + line membership the tile round-trip loses.
    public const string GRAPH_FILE = 'line-graph.json';

    public function __construct(
        #[Autowire('%app.overpass_mirrors%')]
        private string $overpassMirrors,
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
    ) {
    }

    /**
     * @param int[]                                            $relationIds  OSM relation IDs
     * @param list<array{path: string, selectedRefs: list<string>}> $gtfsSources  GTFS source specs
     * @param list<string>                                     $osmRefs      Ref values from selected OSM transit lines
     */
    public function build(
        string $gameUuid,
        array $relationIds,
        array $gtfsSources = [],
        array $osmRefs = [],
        ?string $boundaryGeoJson = null,
    ): int {
        if ($relationIds === [] && $gtfsSources === []) {
            return 0;
        }

        $tilesDir = $this->tilesBaseDir . '/' . $gameUuid;
        if (!is_dir($tilesDir) && !mkdir($tilesDir, 0o755, true) && !is_dir($tilesDir)) {
            throw new \RuntimeException("Failed to create tiles directory: {$tilesDir}");
        }

        $gtfsDir = sys_get_temp_dir() . '/osm2gtfs-' . $gameUuid;
        $mergedDir = sys_get_temp_dir() . '/merged-' . $gameUuid;
        $clipFile = $gtfsDir . self::CLIP_SUFFIX;
        if ($boundaryGeoJson !== null && $boundaryGeoJson !== '') {
            file_put_contents($clipFile, $boundaryGeoJson);
        }
        // Refs reach the shell only as argv "$1", never as script text.
        $routesCsv = implode(',', array_values(array_unique(array_merge(
            $osmRefs,
            ...array_map(static fn(array $s): array => $s['selectedRefs'], $gtfsSources),
        ))));
        $shFile = tempnam(sys_get_temp_dir(), 'transit-') . '.sh';
        file_put_contents(
            $shFile,
            $this->pipelineScript($relationIds, $gtfsSources, $gtfsDir, $mergedDir, $tilesDir, $routesCsv),
        );
        chmod($shFile, 0o755);

        $process = new Process(
            [$shFile, $routesCsv],
            null,
            ['OVERPASS_MIRRORS' => $this->overpassMirrors],
            null,
            self::TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } finally {
            @unlink($shFile);
            @unlink($clipFile);
            $this->removeDir($gtfsDir);
            $this->removeDir($mergedDir);
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Transit tile pipeline failed: ' . $process->getErrorOutput());
        }

        return $this->countTiles($tilesDir);
    }

    /**
     * @param int[]                                            $relationIds
     * @param list<array{path: string, selectedRefs: list<string>}> $gtfsSources
     */
    private function pipelineScript(
        array $relationIds,
        array $gtfsSources,
        string $gtfsDir,
        string $mergedDir,
        string $tilesDir,
        string $routesCsv,
    ): string {
        $zoom = (string) self::NATIVE_ZOOM;
        $width = (string) self::LINE_WIDTH;
        $spacing = (string) self::LINE_SPACING;
        $overlay = $tilesDir . '/' . self::OVERLAY_FILE;
        $graph = $tilesDir . '/' . self::GRAPH_FILE;

        // Produce the exact same script as before when no GTFS sources are provided.
        if ($gtfsSources === []) {
            $ids = implode(',', $relationIds);

            return "#!/bin/sh\nset -e\n"
                . $this->clipGuard($gtfsDir)
                . self::OSM2GTFS_BIN . " --relation-ids {$ids} \${CLIP} --output {$gtfsDir}\n"
                . $this->renderStage($gtfsDir, $tilesDir, $graph)
                . self::PYTHON_BIN . ' ' . self::DECODE_SCRIPT . " {$tilesDir} {$overlay} {$graph}\n";
        }

        $script = "#!/bin/sh\nset -e\n\n" . $this->clipGuard($gtfsDir);

        if ($relationIds !== []) {
            $ids = implode(',', $relationIds);
            $script .= self::OSM2GTFS_BIN . " --relation-ids {$ids} \${CLIP} --output {$gtfsDir}\n";
            $script .= "OSM_SOURCE=\"--source osm={$gtfsDir}\"\n";
        } else {
            $script .= "OSM_SOURCE=\"\"\n";
        }

        $gtfsSourceArgs = '';
        $srcIdx = 1;
        foreach ($gtfsSources as $source) {
            $gtfsSourceArgs .= ' ' . escapeshellarg('--source')
                . ' ' . escapeshellarg("src{$srcIdx}={$source['path']}");
            ++$srcIdx;
        }

        $script .= "ROUTES_CSV=\"\${1:-}\"\n";
        $script .= self::GTFSMERGE_BIN . " --output {$mergedDir}"
            . ' ${OSM_SOURCE}'
            . $gtfsSourceArgs
            . ' --routes "$ROUTES_CSV"'
            . " --stop-merge-radius 200\n";

        $script .= $this->renderStage($mergedDir, $tilesDir, $graph);

        $script .= self::PYTHON_BIN . ' ' . self::DECODE_SCRIPT . " {$tilesDir} {$overlay} {$graph}\n";

        return $script;
    }

    /**
     * Set CLIP when build() wrote a boundary, so the same script works with and without one.
     */
    private function clipGuard(string $gtfsDir): string
    {
        $clipFile = $gtfsDir . self::CLIP_SUFFIX;

        return "CLIP=\"\"\n"
            . "if [ -f {$clipFile} ]; then\n"
            . "    CLIP=\"--clip {$clipFile}\"\n"
            . "fi\n";
    }

    /**
     * Stage topo's output so its size can pick loom's optimisation method, then render the tiles.
     */
    private function renderStage(string $gtfsDir, string $tilesDir, string $graph): string
    {
        $zoom = (string) self::NATIVE_ZOOM;
        $width = (string) self::LINE_WIDTH;
        $spacing = (string) self::LINE_SPACING;

        return 'TOPO=$(mktemp /tmp/transit-topo-XXXXXX.json)' . "\n"
            . 'trap \'rm -f "$TOPO" "$TOPO.clean"\' EXIT' . "\n"
            . "gtfs2graph {$gtfsDir} | topo > " . '"$TOPO"' . "\n"
            . self::PYTHON_BIN . ' ' . self::JUMP_FILTER_SCRIPT . ' ' . self::MAX_EDGE_SEGMENT_METRES
            . ' < "$TOPO" > "$TOPO.clean"' . "\n"
            . 'mv "$TOPO.clean" "$TOPO"' . "\n"
            . 'if [ "$(wc -c < "$TOPO")" -gt ' . self::LOOM_GREEDY_ABOVE_BYTES . ' ]; then' . "\n"
            . '    LOOM_METHOD="-m ' . self::LOOM_GREEDY_METHOD . '"' . "\n"
            . 'else' . "\n"
            . '    LOOM_METHOD=""' . "\n"
            . 'fi' . "\n"
            . 'loom ${LOOM_METHOD} < "$TOPO" | tee ' . $graph . ' | '
            . "transitmap --render-engine=mvt --zoom={$zoom} "
            . "--line-width {$width} --line-spacing {$spacing} --tight-stations "
            . "--mvt-path {$tilesDir}\n";
    }

    private function countTiles(string $dir): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'mvt') {
                ++$count;
            }
        }

        return $count;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
