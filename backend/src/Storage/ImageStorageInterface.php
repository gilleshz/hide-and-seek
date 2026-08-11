<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ImageStorageInterface
{
    public function store(string $gameUuid, UploadedFile $file): string;

    /** @return resource */
    public function read(string $gameUuid, string $ref): mixed;

    public function mimeType(string $gameUuid, string $ref): string;

    public function delete(string $gameUuid, string $ref): void;
}
