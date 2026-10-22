<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ImageStorageInterface
{
    public function store(string $scope, UploadedFile $file, ?int $squareSize = null): string;

    public function exists(string $scope, string $ref): bool;

    /** @return resource */
    public function read(string $scope, string $ref): mixed;

    public function mimeType(string $scope, string $ref): string;

    public function delete(string $scope, string $ref): void;
}
