<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DropGraphJumpsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../bin/drop_graph_jumps.py';

    /**
     * @param list<mixed> $features
     *
     * @return array{edges: int, nodes: array<string, string>}
     */
    private function filter(array $features, string $threshold = '20000'): array
    {
        $input = json_encode(['type' => 'FeatureCollection', 'features' => $features]);
        self::assertIsString($input);

        $process = new Process(['python3', self::SCRIPT, $threshold]);
        $process->setInput($input);
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true);
        self::assertIsArray($decoded);
        $out = $decoded['features'] ?? null;
        self::assertIsArray($out);

        $edges = 0;
        $nodes = [];
        foreach ($out as $feature) {
            self::assertIsArray($feature);
            $geometry = $feature['geometry'] ?? null;
            $props = $feature['properties'] ?? null;
            self::assertIsArray($geometry);
            self::assertIsArray($props);
            if ($geometry['type'] === 'LineString') {
                ++$edges;
                continue;
            }
            $id = $props['id'] ?? null;
            $deg = $props['deg'] ?? null;
            self::assertIsString($id);
            self::assertIsString($deg);
            $nodes[$id] = $deg;
        }

        return ['edges' => $edges, 'nodes' => $nodes];
    }

    /**
     * @param list<array{float, float}> $coords
     *
     * @return array<string, mixed>
     */
    private function edge(array $coords, string $from, string $to): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['id' => "e{$from}{$to}", 'from' => $from, 'to' => $to, 'lines' => []],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
        ];
    }

    /** @return array<string, mixed> */
    private function node(string $id): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['id' => $id, 'deg' => '1', 'station_id' => "S{$id}"],
            'geometry' => ['type' => 'Point', 'coordinates' => [7.0, 47.0]],
        ];
    }

    #[Test]
    public function itDropsAnEdgeSplicedAcrossADistantFragment(): void
    {
        $out = $this->filter([
            $this->node('a'),
            $this->node('b'),
            $this->edge([[7.0, 47.0], [7.01, 47.0], [8.0, 47.0]], 'a', 'b'),
        ]);

        self::assertSame(0, $out['edges']);
    }

    #[Test]
    public function itKeepsALongButContiguousEdgeLikeABaseTunnel(): void
    {
        $coords = [];
        for ($i = 0; $i <= 40; ++$i) {
            $coords[] = [7.0 + $i * 0.005, 47.0];
        }
        $out = $this->filter([$this->node('a'), $this->node('b'), $this->edge($coords, 'a', 'b')]);

        self::assertSame(1, $out['edges']);
    }

    #[Test]
    public function itDropsNodesLeftWithNoEdgesRatherThanRenderingThemAsOrphanStations(): void
    {
        $out = $this->filter([
            $this->node('a'),
            $this->node('b'),
            $this->edge([[7.0, 47.0], [8.5, 47.0]], 'a', 'b'),
        ]);

        self::assertSame(0, $out['edges']);
        self::assertSame([], $out['nodes']);
    }

    #[Test]
    public function itRecomputesDegreeFromTheSurvivingEdges(): void
    {
        $out = $this->filter([
            $this->node('a'),
            $this->node('b'),
            $this->node('c'),
            $this->edge([[7.0, 47.0], [7.01, 47.0]], 'a', 'b'),
            $this->edge([[7.01, 47.0], [7.02, 47.0]], 'b', 'c'),
        ]);

        self::assertSame(['a' => '1', 'b' => '2', 'c' => '1'], $out['nodes']);
    }
}
