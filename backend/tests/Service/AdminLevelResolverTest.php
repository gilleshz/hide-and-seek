<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Service\AdminLevelResolver;
use App\Service\NominatimService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AdminLevelResolver::class)]
final class AdminLevelResolverTest extends TestCase
{
    /** @return array<string, array{string, array<int, int>}> */
    public static function countryCases(): array
    {
        return [
            'known country FR' => ['{"address":{"country_code":"fr"}}', [1 => 4, 2 => 6, 3 => 8, 4 => 9]],
            'unknown country ZZ' => ['{"address":{"country_code":"zz"}}', [1 => 4, 2 => 6, 3 => 8, 4 => 10]],
            'missing country code' => ['{"address":{}}', [1 => 4, 2 => 6, 3 => 8, 4 => 10]],
        ];
    }

    /** @param array<int, int> $expected */
    #[Test]
    #[DataProvider('countryCases')]
    public function resolveMapsCountryCodeToRankLevels(string $reverseJson, array $expected): void
    {
        $resolver = new AdminLevelResolver($this->nominatim($reverseJson));

        self::assertSame($expected, $resolver->resolve($this->gameWithBoundary()));
    }

    #[Test]
    public function resolveReturnsDefaultMapWhenGameHasNoBoundary(): void
    {
        $resolver = new AdminLevelResolver($this->nominatim(null));
        $game = new Game('Nowhere', GameSize::Small, Edition::Metric);

        self::assertSame([1 => 4, 2 => 6, 3 => 8, 4 => 10], $resolver->resolve($game));
    }

    private function nominatim(?string $reverseJson): NominatimService
    {
        $responses = $reverseJson === null ? [] : [new MockResponse($reverseJson)];

        return new NominatimService(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            'test-agent',
            'https://nominatim.test',
        );
    }

    private function gameWithBoundary(): Game
    {
        $game = new Game('Somewhere', GameSize::Medium, Edition::Metric);
        $game->setBoundary(46.0, 6.0, 47.0, 7.0);

        return $game;
    }
}
