<?php

declare(strict_types=1);

namespace App\Service;

use App\ErrorKey;
use App\Exception\FunctionalException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Re-encodes uploaded images through GD: only pixels survive, so polyglot payloads
 * (a webshell appended to JPEG magic bytes) die here and EXIF/GPS metadata never
 * reaches another player's device.
 */
final readonly class ImageSanitizer
{
    // GD allocates width*height*4; a 40 MP cap stays inside the 1024M memory_limit.
    private const int MAX_SIDE_PX = 10_000;
    private const int MAX_PIXELS = 40_000_000;
    private const int JPEG_QUALITY = 85;
    private const int PNG_COMPRESSION = 6;
    private const int WEBP_QUALITY = 85;

    /** @var array<string, array{extension: string}> */
    private const array FORMATS = [
        'image/jpeg' => ['extension' => 'jpg'],
        'image/png' => ['extension' => 'png'],
        'image/webp' => ['extension' => 'webp'],
    ];

    /**
     * @return array{path: string, extension: string, mime: string}
     */
    public function sanitize(UploadedFile $file): array
    {
        $size = @getimagesize($file->getPathname());
        if ($size === false) {
            throw $this->invalidImage();
        }

        [$width, $height] = $size;
        if ($width > self::MAX_SIDE_PX || $height > self::MAX_SIDE_PX || $width * $height > self::MAX_PIXELS) {
            throw $this->tooLargeImage();
        }

        $format = $this->formatOf($size['mime']);

        $contents = @file_get_contents($file->getPathname());
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;
        if (!$image instanceof \GdImage) {
            throw $this->invalidImage();
        }

        $image = $this->applyOrientation($image, $file->getPathname());

        $path = tempnam(sys_get_temp_dir(), 'jetlag-img-');
        if (!is_string($path) || !$this->encode($image, $path, $format)) {
            imagedestroy($image);
            if (is_string($path)) {
                @unlink($path);
            }

            throw $this->invalidImage();
        }
        imagedestroy($image);

        return ['path' => $path, 'extension' => $format['extension'], 'mime' => $size['mime']];
    }

    /**
     * @param array{extension: string} $format
     */
    private function encode(\GdImage $image, string $path, array $format): bool
    {
        return match ($format['extension']) {
            'jpg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'png' => $this->writePng($image, $path),
            'webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default => false,
        };
    }

    private function writePng(\GdImage $image, string $path): bool
    {
        // Without these, GD flattens transparent pixels onto black.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, $path, self::PNG_COMPRESSION);
    }

    /**
     * @return array{extension: string}
     */
    private function formatOf(string $mime): array
    {
        if (!isset(self::FORMATS[$mime])) {
            throw $this->invalidImage();
        }

        return self::FORMATS[$mime];
    }

    /**
     * Coil applies EXIF orientation at decode time, so a re-encode that dropped it
     * would render portrait photos rotated; the re-encoded bytes carry no EXIF at all.
     */
    private function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        if (!is_int($orientation) || $orientation <= 1) {
            return $image;
        }

        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->rotated($this->flipped($image, IMG_FLIP_HORIZONTAL), 270),
            6 => $this->rotated($image, 270),
            7 => $this->rotated($this->flipped($image, IMG_FLIP_HORIZONTAL), 90),
            default => $this->rotated($image, 90),
        };
    }

    private function flipped(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function rotated(\GdImage $image, int $angle): \GdImage
    {
        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);
        if (!$rotated instanceof \GdImage) {
            throw $this->invalidImage();
        }

        return $rotated;
    }

    private function invalidImage(): FunctionalException
    {
        return new FunctionalException(
            message: 'Only JPEG, PNG, and WebP images are accepted.',
            errorKey: 'chat_image.invalid_mime',
        );
    }

    private function tooLargeImage(): FunctionalException
    {
        return new FunctionalException(
            message: 'Image dimensions exceed the maximum supported size.',
            errorKey: ErrorKey::CHAT_IMAGE_TOO_LARGE,
        );
    }
}
