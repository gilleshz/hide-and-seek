<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TransitTileService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class TransitTileController
{
    public function __construct(
        private TransitTileService $tiles,
    ) {
    }

    #[Route('/api/games/{gameKey}/transit-overlay.geojson', methods: ['GET'])]
    public function serveOverlay(string $gameKey): Response
    {
        $path = $this->tiles->resolveOverlayPath($gameKey);

        if ($path === null) {
            return new Response('', Response::HTTP_NO_CONTENT, [
                'Content-Type' => 'application/geo+json',
            ]);
        }

        return new StreamedResponse(
            static function () use ($path): void {
                $fp = fopen($path, 'rb');
                if ($fp !== false) {
                    fpassthru($fp);
                    fclose($fp);
                }
            },
            200,
            [
                'Content-Type' => 'application/geo+json',
                'Cache-Control' => 'public, max-age=86400, immutable',
            ],
        );
    }

    #[Route('/api/games/{gameKey}/transit-tiles/{z}/{x}/{y}.mvt', methods: ['GET'])]
    public function serve(string $gameKey, int $z, int $x, int $y): Response
    {
        $path = $this->tiles->resolvePath($gameKey, $z, $x, $y);

        if ($path === null || !file_exists($path)) {
            return new Response('', Response::HTTP_NO_CONTENT, [
                'Content-Type' => 'application/vnd.mapbox-vector-tile',
            ]);
        }

        return new StreamedResponse(
            static function () use ($path): void {
                $fp = fopen($path, 'rb');
                if ($fp !== false) {
                    fpassthru($fp);
                    fclose($fp);
                }
            },
            200,
            [
                'Content-Type' => 'application/vnd.mapbox-vector-tile',
                'Content-Encoding' => $this->isGzipped($path) ? 'gzip' : 'identity',
                'Cache-Control' => 'public, max-age=86400, immutable',
            ],
        );
    }

    private function isGzipped(string $path): bool
    {
        if (!str_ends_with($path, '.gz') && !str_ends_with($path, '.mvt')) {
            return false;
        }
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return false;
        }
        $magic = fread($fp, 2);
        fclose($fp);

        return $magic === "\x1f\x8b";
    }
}
