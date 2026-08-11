<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TransitTilePipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitTilePipelineTest extends TestCase
{
    /** @param list<array{path: string, selectedRefs: list<string>}> $gtfsSources */
    private function scriptFor(array $gtfsSources, string $routesCsv = 'A'): string
    {
        $pipeline = new TransitTilePipeline('http://overpass.test/api/interpreter', '/app/var/tiles');
        $method = new \ReflectionMethod($pipeline, 'pipelineScript');
        $script = $method->invoke(
            $pipeline,
            [123, 456],
            $gtfsSources,
            '/tmp/gtfs',
            '/tmp/merged',
            '/tmp/tiles/game',
            $routesCsv,
        );
        self::assertIsString($script);

        return $script;
    }

    #[Test]
    public function itStagesTopoOutputSoLoomsMethodCanDependOnGraphSize(): void
    {
        $script = $this->scriptFor([]);

        self::assertStringContainsString('topo > "$TOPO"', $script);
        self::assertStringContainsString('wc -c < "$TOPO"', $script);
        self::assertStringContainsString('-m greedy-lookahead', $script);
        self::assertStringContainsString('loom ${LOOM_METHOD} < "$TOPO"', $script);
    }

    #[Test]
    public function itRemovesTheStagedGraphEvenWhenTheBuildFails(): void
    {
        self::assertStringContainsString('trap \'rm -f "$TOPO" "$TOPO.clean"\' EXIT', $this->scriptFor([]));
    }

    #[Test]
    public function itFiltersTopoJumpsAsItsOwnStepSoAFailureIsNotMaskedByAPipe(): void
    {
        $script = $this->scriptFor([]);

        self::assertStringContainsString('drop_graph_jumps.py 20000 < "$TOPO" > "$TOPO.clean"', $script);
        self::assertStringContainsString('mv "$TOPO.clean" "$TOPO"', $script);
        self::assertStringNotContainsString('topo | ' . 'drop_graph_jumps', $script);
    }

    #[Test]
    public function itPicksLoomsMethodTheSameWayWhenGtfsSourcesAreMerged(): void
    {
        $script = $this->scriptFor([['path' => '/tmp/feed.zip', 'selectedRefs' => ['S1']]]);

        self::assertStringContainsString('gtfs2graph /tmp/merged | topo > "$TOPO"', $script);
        self::assertStringContainsString('-m greedy-lookahead', $script);
    }

    #[Test]
    public function itPassesTheBoundaryToOsm2gtfsOnlyWhenBuildWroteOne(): void
    {
        $script = $this->scriptFor([]);

        self::assertStringContainsString('if [ -f /tmp/gtfs.clip.geojson ]; then', $script);
        self::assertStringContainsString('CLIP="--clip /tmp/gtfs.clip.geojson"', $script);
        self::assertStringContainsString('--relation-ids 123,456 ${CLIP} --output /tmp/gtfs', $script);
    }

    #[Test]
    public function itGuardsTheClipForMergedGtfsSourcesToo(): void
    {
        $script = $this->scriptFor([['path' => '/tmp/feed.zip', 'selectedRefs' => ['S1']]]);

        self::assertStringContainsString('if [ -f /tmp/gtfs.clip.geojson ]; then', $script);
        self::assertStringContainsString('--relation-ids 123,456 ${CLIP} --output /tmp/gtfs', $script);
    }

    #[Test]
    public function itPassesRefsOnlyThroughTheQuotedCsvVariableSoShellMetacharactersStayData(): void
    {
        $script = $this->scriptFor([['path' => '/tmp/feed.zip', 'selectedRefs' => ['S1']]], "x'; touch /tmp/pwn; echo '");

        self::assertStringContainsString('ROUTES_CSV="${1:-}"', $script);
        self::assertStringContainsString('--routes "$ROUTES_CSV"', $script);
        self::assertStringNotContainsString("touch /tmp/pwn", $script);
        self::assertStringNotContainsString('x\';', $script);
    }

    #[Test]
    public function itLeavesTheRoutesArgumentQuotedEvenWhenTheCsvIsEmpty(): void
    {
        $script = $this->scriptFor([['path' => '/tmp/feed.zip', 'selectedRefs' => []]], '');

        self::assertStringContainsString('ROUTES_CSV="${1:-}"', $script);
        self::assertStringContainsString('--routes "$ROUTES_CSV"', $script);
    }

    #[Test]
    public function itLeavesLoomOnItsDefaultMethodBelowTheThreshold(): void
    {
        $script = $this->scriptFor([]);

        self::assertMatchesRegularExpression('/LOOM_METHOD=""/', $script);
        self::assertStringNotContainsString('loom -m greedy-lookahead <', $script);
    }
}
