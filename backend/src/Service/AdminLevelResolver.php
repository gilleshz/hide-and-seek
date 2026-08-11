<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;

final readonly class AdminLevelResolver
{
    /** @var array<string, list<int>> */
    private const array COUNTRY_LEVELS = [
        'CH' => [4, 6, 8],
        'FR' => [4, 6, 8, 9],
        'DE' => [4, 6, 8, 9],
        'AT' => [4, 6, 8, 9],
        'IT' => [4, 6, 8, 9],
        'BE' => [4, 6, 8, 9],
        'ES' => [4, 6, 8, 9],
        'PT' => [4, 6, 7],
        'GB' => [4, 6, 8, 10],
        'NL' => [4, 8, 10],
        'PL' => [4, 6, 8],
        'CZ' => [4, 6, 8, 9],
        'HR' => [4, 8, 9],
        'GR' => [5, 6, 8, 9],
        'HU' => [4, 6, 7],
        'AL' => [4, 7, 8, 9],
        'SE' => [4, 7],
        'NO' => [4, 7],
        'DK' => [4, 7],
        'US' => [4, 6, 8],
        'CA' => [4, 6, 8, 9],
        'MX' => [4, 6, 8, 9],
        'BR' => [4, 8, 9],
        'CL' => [4, 6, 8],
        'CO' => [4, 6, 8, 9],
        'CR' => [4, 6, 8, 9],
        'AR' => [4, 6, 8],
        'IN' => [4, 5, 6, 8],
        'ID' => [4, 5, 6, 7],
        'IR' => [4, 5, 6, 7],
        'JP' => [4, 6, 7, 8],
        'KE' => [4, 5, 6, 7],
        'AF' => [4, 5],
    ];

    /** @var list<int> */
    private const array DEFAULT_LEVELS = [4, 6, 8, 10];

    public function __construct(
        private NominatimService $nominatim,
    ) {
    }

    /** @return array<int, int> */
    public function resolve(Game $game): array
    {
        $swLat = $game->getBoundarySwLat();
        $swLng = $game->getBoundarySwLng();
        $neLat = $game->getBoundaryNeLat();
        $neLng = $game->getBoundaryNeLng();
        if ($swLat === null || $swLng === null || $neLat === null || $neLng === null) {
            return $this->rankMap(self::DEFAULT_LEVELS);
        }

        $code = $this->nominatim->reverseCountryCode(($swLat + $neLat) / 2, ($swLng + $neLng) / 2);
        $levels = ($code !== null ? self::COUNTRY_LEVELS[$code] ?? null : null) ?? self::DEFAULT_LEVELS;

        return $this->rankMap($levels);
    }

    /**
     * @param list<int> $levels
     * @return array<int, int>
     */
    private function rankMap(array $levels): array
    {
        $map = [];
        foreach ($levels as $index => $level) {
            $map[$index + 1] = $level;
        }

        return $map;
    }
}
