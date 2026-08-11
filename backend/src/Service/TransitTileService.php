<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TransitTileService
{
    private const string OVERLAY_FILE = 'overlay.geojson';

    public function __construct(
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
    ) {
    }

    public function resolveOverlayPath(string $gameKey): ?string
    {
        $path = $this->tilesBaseDir . '/' . $gameKey . '/' . self::OVERLAY_FILE;

        return file_exists($path) ? $path : null;
    }

    public function resolvePath(string $gameKey, int $z, int $x, int $y): ?string
    {
        $path = $this->tilesBaseDir . '/' . $gameKey . '/' . $z . '/' . $x . '/' . $y . '.mvt';

        if (file_exists($path)) {
            return $path;
        }

        $gzipped = $path . '.gz';
        if (file_exists($gzipped)) {
            return $gzipped;
        }

        return null;
    }
}
