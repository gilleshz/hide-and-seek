<?php

declare(strict_types=1);

namespace App\Tests;

use App\StreetNetworkTrim;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreetNetworkTrim::class)]
final class StreetNetworkTrimTest extends TestCase
{
    /**
     * @return array<string, array{tags: array<string, string>, expected: string}>
     */
    public static function classCases(): array
    {
        return [
            'motorway' => ['tags' => ['highway' => 'motorway'], 'expected' => 'motorway'],
            'motorway link' => ['tags' => ['highway' => 'motorway_link'], 'expected' => 'motorway'],
            'trunk' => ['tags' => ['highway' => 'trunk'], 'expected' => 'trunk'],
            'trunk link' => ['tags' => ['highway' => 'trunk_link'], 'expected' => 'trunk'],
            'primary' => ['tags' => ['highway' => 'primary'], 'expected' => 'primary'],
            'primary link' => ['tags' => ['highway' => 'primary_link'], 'expected' => 'primary'],
            'secondary' => ['tags' => ['highway' => 'secondary'], 'expected' => 'secondary'],
            'secondary link' => ['tags' => ['highway' => 'secondary_link'], 'expected' => 'secondary'],
            'tertiary' => ['tags' => ['highway' => 'tertiary'], 'expected' => 'tertiary'],
            'tertiary link' => ['tags' => ['highway' => 'tertiary_link'], 'expected' => 'tertiary'],
            'residential' => ['tags' => ['highway' => 'residential'], 'expected' => 'residential'],
            'living street' => ['tags' => ['highway' => 'living_street'], 'expected' => 'residential'],
            'unclassified' => ['tags' => ['highway' => 'unclassified'], 'expected' => 'residential'],
            'pedestrian' => ['tags' => ['highway' => 'pedestrian'], 'expected' => 'pedestrian'],
            'service' => ['tags' => ['highway' => 'service'], 'expected' => 'service'],
            'track' => ['tags' => ['highway' => 'track'], 'expected' => 'track'],
            'cycleway' => ['tags' => ['highway' => 'cycleway'], 'expected' => 'cycleway'],
            'steps' => ['tags' => ['highway' => 'steps'], 'expected' => 'steps'],
            'path' => ['tags' => ['highway' => 'path'], 'expected' => 'path'],
            'bridleway' => ['tags' => ['highway' => 'bridleway'], 'expected' => 'path'],
            'sidewalk' => ['tags' => ['highway' => 'footway', 'footway' => 'sidewalk'], 'expected' => 'sidewalk'],
            'crossing' => ['tags' => ['highway' => 'footway', 'footway' => 'crossing'], 'expected' => 'crossing'],
            'plain footway' => ['tags' => ['highway' => 'footway'], 'expected' => 'footway'],
            'access aisle footway' => [
                'tags' => ['highway' => 'footway', 'footway' => 'access_aisle'],
                'expected' => 'footway',
            ],
            'busway' => ['tags' => ['highway' => 'busway'], 'expected' => 'other'],
            'no highway tag' => ['tags' => [], 'expected' => 'other'],
        ];
    }

    /**
     * @param array<string, string> $tags
     */
    #[DataProvider('classCases')]
    #[Test]
    public function itMapsTheHighwayTagToTheWireClass(array $tags, string $expected): void
    {
        $ways = self::trim(self::overpass([
            ['tags' => $tags, 'geometry' => [[52.52, 13.405], [52.521, 13.406]]],
        ]));

        self::assertCount(1, $ways);
        self::assertSame($expected, $ways[0]['class']);
    }

    #[Test]
    public function itRoundsEachCoordinateToFiveDecimalsInLongitudeLatitudeOrder(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.5200049, 13.4051234], [52.5204449, 13.4059876]],
            ],
        ]));

        self::assertCount(1, $ways);
        self::assertSame([[13.40512, 52.52], [13.40599, 52.52044]], $ways[0]['coordinates']);
    }

    #[Test]
    public function itDropsAConsecutiveDuplicateLeftByTheRoundingButKeepsALaterRepeat(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [
                    [52.520001, 13.405001],
                    [52.520002, 13.405002],
                    [52.521, 13.406],
                    [52.520001, 13.405001],
                ],
            ],
        ]));

        self::assertCount(1, $ways);
        self::assertSame(
            [[13.405, 52.52], [13.406, 52.521], [13.405, 52.52]],
            $ways[0]['coordinates'],
        );
    }

    #[Test]
    public function itDiscardsAWayLeftWithFewerThanTwoPoints(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.520001, 13.405001], [52.520002, 13.405002], [52.520003, 13.405003]],
            ],
            ['tags' => ['highway' => 'service'], 'geometry' => [[52.53, 13.41]]],
            ['tags' => ['highway' => 'track'], 'geometry' => [[52.54, 13.42], [52.541, 13.421]]],
        ]));

        self::assertCount(1, $ways);
        self::assertSame('track', $ways[0]['class']);
    }

    #[Test]
    public function itIgnoresElementsThatAreNotWaysAndNodesWithNoUsableCoordinates(): void
    {
        $json = json_encode([
            'elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 52.52, 'lon' => 13.405],
                ['type' => 'relation', 'id' => 2, 'tags' => ['highway' => 'residential']],
                [
                    'type' => 'way',
                    'id' => 3,
                    'tags' => ['highway' => 'residential'],
                    'geometry' => [
                        ['lat' => 52.52, 'lon' => 13.405],
                        ['lat' => 'nope', 'lon' => 13.406],
                        ['lat' => 52.521],
                        ['lat' => 52.522, 'lon' => 13.407],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ways = self::trim($json);

        self::assertCount(1, $ways);
        self::assertSame([[13.405, 52.52], [13.407, 52.522]], $ways[0]['coordinates']);
    }

    #[Test]
    public function itMarksAsJunctionsOnlyTheCoordinatesSharedByTwoOrMoreKeptWays(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.52, 13.4], [52.52, 13.401], [52.52, 13.402], [52.52, 13.4]],
            ],
            ['tags' => ['highway' => 'service'], 'geometry' => [[52.52, 13.401], [52.521, 13.401]]],
            ['tags' => ['highway' => 'footway'], 'geometry' => [[52.52, 13.402], [52.52, 13.403]]],
        ]));

        self::assertCount(3, $ways);
        self::assertSame([1, 2], $ways[0]['junctionIndices']);
        self::assertSame([0], $ways[1]['junctionIndices']);
        self::assertSame([0], $ways[2]['junctionIndices']);
    }

    #[Test]
    public function aCoordinateSharedOnlyWithADiscardedWayIsNotAJunction(): void
    {
        $ways = self::trim(self::overpass([
            ['tags' => ['highway' => 'residential'], 'geometry' => [[52.52, 13.4], [52.52, 13.401]]],
            ['tags' => ['highway' => 'service'], 'geometry' => [[52.52, 13.401]]],
        ]));

        self::assertCount(1, $ways);
        self::assertSame([], $ways[0]['junctionIndices']);
    }

    #[Test]
    public function itReturnsNoWaysForAnEmptyOrShapelessResponse(): void
    {
        self::assertSame([], self::trim('{"version":0.6,"elements":[]}'));
        self::assertSame([], self::trim('{"version":0.6}'));
        self::assertSame([], self::trim('[]'));
    }

    #[Test]
    public function itThrowsAJsonExceptionOnAMalformedResponse(): void
    {
        $this->expectException(\JsonException::class);

        self::trim('{"elements": [');
    }

    #[Test]
    public function itBuildsTheBboxFromTheRadiusPlusTheMarginInSouthWestNorthEastOrder(): void
    {
        self::assertSame(
            '52.514161,13.395404,52.525839,13.414596',
            StreetNetworkTrim::bbox(new Point(13.405, 52.52), 500.0),
        );
    }

    #[Test]
    public function itKeepsTheLongitudeSpanFiniteNextToAPole(): void
    {
        self::assertSame(
            '89.994161,12.821098,90.005839,13.988902',
            StreetNetworkTrim::bbox(new Point(13.405, 90.0), 500.0),
        );
    }

    #[Test]
    public function itKeepsAWayThatLiesEntirelyInsideTheBboxUntouched(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.52, 13.405], [52.5205, 13.4055], [52.521, 13.406]],
            ],
        ]));

        self::assertCount(1, $ways);
        self::assertSame([[13.405, 52.52], [13.4055, 52.5205], [13.406, 52.521]], $ways[0]['coordinates']);
    }

    /** Overpass returns each way whole, so the part reaching past the zone must not become a traceable answer. */
    #[Test]
    public function itTruncatesAWayThatCrossesOneBboxEdge(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.52, 13.405], [52.5205, 13.4055], [52.6, 13.406], [52.7, 13.407]],
            ],
        ]), 500.0);

        self::assertCount(1, $ways);
        self::assertSame([[13.405, 52.52], [13.4055, 52.5205]], $ways[0]['coordinates']);
    }

    #[Test]
    public function itSplitsAWayThatLeavesAndReEntersTheBboxIntoTwo(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [
                    [52.52, 13.405],
                    [52.5205, 13.4055],
                    [52.7, 13.406],
                    [52.8, 13.407],
                    [52.521, 13.408],
                    [52.5215, 13.409],
                ],
            ],
        ]), 500.0);

        self::assertCount(2, $ways);
        self::assertSame([[13.405, 52.52], [13.4055, 52.5205]], $ways[0]['coordinates']);
        self::assertSame([[13.408, 52.521], [13.409, 52.5215]], $ways[1]['coordinates']);
        self::assertSame('residential', $ways[1]['class']);
    }

    #[Test]
    public function itDropsAWayThatOnlyClipsACornerWithASinglePointInside(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.7, 13.3], [52.5255, 13.4145], [52.7, 13.5]],
            ],
        ]), 500.0);

        self::assertSame([], $ways);
    }

    /** A junction is derived from the clipped runs, so a shared node cut away by the bbox is not one. */
    #[Test]
    public function itDerivesJunctionsAfterClipping(): void
    {
        $ways = self::trim(self::overpass([
            [
                'tags' => ['highway' => 'residential'],
                'geometry' => [[52.52, 13.405], [52.5205, 13.4055], [52.6, 13.406]],
            ],
            [
                'tags' => ['highway' => 'service'],
                'geometry' => [[52.6, 13.406], [52.5215, 13.409], [52.522, 13.41]],
            ],
        ]), 500.0);

        self::assertCount(2, $ways);
        self::assertSame([], $ways[0]['junctionIndices']);
        self::assertSame([], $ways[1]['junctionIndices']);
    }

    /** number_format(-0.0, 5) is "-0.00000", so without normalising the shared node keys would differ. */
    #[Test]
    public function itMatchesJunctionsAcrossNegativeZeroAtThePrimeMeridian(): void
    {
        $ways = StreetNetworkTrim::ways(
            self::overpass([
                ['tags' => ['highway' => 'residential'], 'geometry' => [[51.5, -0.000001], [51.5, -0.001]]],
                ['tags' => ['highway' => 'service'], 'geometry' => [[51.5, 0.000002], [51.5, 0.001]]],
            ]),
            new Point(0.0, 51.5),
            5000.0,
        );

        self::assertCount(2, $ways);
        self::assertSame([0], $ways[0]['junctionIndices']);
        self::assertSame([0], $ways[1]['junctionIndices']);
    }

    /**
     * @return list<array{
     *     class: string,
     *     coordinates: list<array{0: float, 1: float}>,
     *     junctionIndices: list<int>,
     * }>
     *
     * @throws \JsonException
     */
    private static function trim(string $overpassJson, float $radiusMeters = 5000.0): array
    {
        return StreetNetworkTrim::ways($overpassJson, new Point(13.405, 52.52), $radiusMeters);
    }

    /**
     * @param list<array{tags: array<string, string>, geometry: list<array{0: float, 1: float}>}> $ways
     */
    private static function overpass(array $ways): string
    {
        $elements = [];
        foreach ($ways as $index => $way) {
            $geometry = [];
            foreach ($way['geometry'] as $node) {
                $geometry[] = ['lat' => $node[0], 'lon' => $node[1]];
            }
            $elements[] = [
                'type' => 'way',
                'id' => $index + 1,
                'tags' => $way['tags'],
                'geometry' => $geometry,
            ];
        }

        return json_encode(['version' => 0.6, 'elements' => $elements], JSON_THROW_ON_ERROR);
    }
}
