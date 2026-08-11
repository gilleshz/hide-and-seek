<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\ImageStorageInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class ChatImageController
{
    public function __construct(
        private ImageStorageInterface $storage,
    ) {
    }

    #[Route('/api/games/{gameKey}/chat/image/{ref}', methods: ['GET'])]
    public function serve(string $gameKey, string $ref): Response
    {
        return new StreamedResponse(
            function () use ($gameKey, $ref): void {
                $fp = $this->storage->read($gameKey, $ref);
                fpassthru($fp);
                fclose($fp);
            },
            200,
            [
                'Content-Type' => $this->storage->mimeType($gameKey, $ref),
                // Refs are random write-once names, the bytes behind a URL never change; private keeps
                // game content out of shared caches.
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ],
        );
    }
}
