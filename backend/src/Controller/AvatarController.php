<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AvatarService;
use App\Storage\ImageStorageInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class AvatarController
{
    public function __construct(
        private ImageStorageInterface $storage,
    ) {
    }

    #[Route('/api/avatars/{ref}', methods: ['GET'])]
    public function serve(string $ref): Response
    {
        if (!$this->storage->exists(AvatarService::SCOPE, $ref)) {
            throw new NotFoundHttpException('Avatar not found.');
        }

        return new StreamedResponse(
            function () use ($ref): void {
                $fp = $this->storage->read(AvatarService::SCOPE, $ref);
                fpassthru($fp);
                fclose($fp);
            },
            200,
            [
                'Content-Type' => $this->storage->mimeType(AvatarService::SCOPE, $ref),
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ],
        );
    }
}
