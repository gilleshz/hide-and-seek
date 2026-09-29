<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\FunctionalException;
use App\Service\UploadedImageReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(UploadedImageReader::class)]
final class UploadedImageReaderTest extends TestCase
{
    /** Mirrors the Android download ceiling (ImageSaver.MAX_IMAGE_BYTES). */
    private const int MAX_SIZE_BYTES = 26_214_400;

    #[Test]
    public function itAcceptsAnImageUpToTheSizeCap(): void
    {
        foreach ([self::MAX_SIZE_BYTES - 1, self::MAX_SIZE_BYTES] as $size) {
            $file = $this->paddedPng($size);

            self::assertSame($file, $this->readerFor($file)->image());
            @unlink($file->getPathname());
        }
    }

    #[Test]
    public function itRejectsAnImageOneByteOverTheSizeCap(): void
    {
        $file = $this->paddedPng(self::MAX_SIZE_BYTES + 1);

        try {
            $this->readerFor($file)->image();
            self::fail('An image over the size cap must be rejected.');
        } catch (FunctionalException $exception) {
            self::assertSame('chat_image.size_exceeded', $exception->getErrorKey());
            self::assertSame('Image exceeds maximum size of 25 MB.', $exception->getMessage());
        } finally {
            @unlink($file->getPathname());
        }
    }

    private function readerFor(UploadedFile $file): UploadedImageReader
    {
        $request = new Request([], [], [], [], ['image' => $file]);
        $stack = new RequestStack();
        $stack->push($request);

        return new UploadedImageReader($stack);
    }

    /**
     * @param positive-int $size
     */
    private function paddedPng(int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'reader_png_');
        self::assertIsString($path);

        $image = imagecreatetruecolor(1, 1);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $path);
        imagedestroy($image);

        $handle = fopen($path, 'r+b');
        self::assertIsResource($handle);
        ftruncate($handle, $size);
        fclose($handle);

        return new UploadedFile($path, 'photo.png', 'image/png', \UPLOAD_ERR_OK, true);
    }
}
