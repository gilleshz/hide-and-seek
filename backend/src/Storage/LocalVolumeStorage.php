<?php

declare(strict_types=1);

namespace App\Storage;

use App\Service\ImageSanitizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly class LocalVolumeStorage implements ImageStorageInterface
{
    public function __construct(
        #[Autowire('%env(CHAT_IMAGE_DIR)%')]
        private string $baseDir,
        private ImageSanitizer $sanitizer,
    ) {
    }

    public function store(string $gameUuid, UploadedFile $file): string
    {
        $dir = "{$this->baseDir}/{$gameUuid}";
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $sanitized = $this->sanitizer->sanitize($file);
        $ref = bin2hex(random_bytes(16)) . '.' . $sanitized['extension'];
        $dest = "{$dir}/{$ref}";
        $stored = @rename($sanitized['path'], $dest) || @copy($sanitized['path'], $dest);
        @unlink($sanitized['path']);
        if (!$stored || !is_file($dest)) {
            throw new \RuntimeException("Failed to store sanitized image: {$dest}");
        }

        return $ref;
    }

    public function read(string $gameUuid, string $ref): mixed
    {
        $path = $this->resolvePath($gameUuid, $ref);
        $fp = is_file($path) ? fopen($path, 'rb') : false;
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: {$path}");
        }

        return $fp;
    }

    public function mimeType(string $gameUuid, string $ref): string
    {
        $path = $this->resolvePath($gameUuid, $ref);
        $mime = mime_content_type($path);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    public function delete(string $gameUuid, string $ref): void
    {
        $path = $this->resolvePath($gameUuid, $ref);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function resolvePath(string $gameUuid, string $ref): string
    {
        if (str_contains($ref, '/') || str_contains($ref, '\\') || str_contains($ref, '..')) {
            throw new \InvalidArgumentException('Invalid image reference.');
        }

        return "{$this->baseDir}/{$gameUuid}/{$ref}";
    }
}
