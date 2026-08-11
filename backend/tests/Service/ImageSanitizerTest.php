<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\FunctionalException;
use App\Service\ImageSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageSanitizerTest extends TestCase
{
    private ImageSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ImageSanitizer();
    }

    #[Test]
    public function aPolyglotPayloadIsDestroyedByTheReEncode(): void
    {
        $jpeg = $this->gdJpeg(4, 4);
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_polyglot_');
        self::assertIsString($path);
        file_put_contents($path, $jpeg . '<?php echo "C1EXECOK"; ?>');

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'x.php', 'image/jpeg', null, true));

        self::assertSame('jpg', $result['extension']);
        self::assertSame('image/jpeg', $result['mime']);
        $decoded = file_get_contents($result['path']);
        self::assertIsString($decoded);
        self::assertStringNotContainsString('C1EXECOK', $decoded);
        self::assertNotFalse(getimagesize($result['path']));
        @unlink($result['path']);
    }

    #[Test]
    public function aPngNamedPhpGetsTheDerivedPngExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_png_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $path);
        imagedestroy($image);
        file_put_contents($path, '<?php echo 1; ?>', FILE_APPEND);

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'x.php', 'image/png', null, true));

        self::assertSame('png', $result['extension']);
        self::assertSame('image/png', $result['mime']);
        @unlink($result['path']);
    }

    #[Test]
    public function aWebpRoundTripsWithTheDerivedExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_webp_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagewebp($image, $path, 85);
        imagedestroy($image);

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'photo.webp', 'image/webp', null, true));

        self::assertSame('webp', $result['extension']);
        self::assertSame('image/webp', $result['mime']);
        self::assertNotFalse(getimagesize($result['path']));
        @unlink($result['path']);
    }

    #[Test]
    public function anOversizedHeaderIsRejectedBeforeDecode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_huge_');
        self::assertIsString($path);
        $ihdr = pack('N', 20000) . pack('N', 20000) . "\x08\x06\x00\x00\x00";
        file_put_contents(
            $path,
            "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)),
        );

        $this->expectTooLargeImage($path);
    }

    #[Test]
    public function aTransparentPngKeepsItsAlphaThroughTheReEncode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_alpha_');
        self::assertIsString($path);
        $image = imagecreatetruecolor(4, 4);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        self::assertIsInt($transparent);
        imagefill($image, 0, 0, $transparent);
        imagepng($image, $path);
        imagedestroy($image);

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'alpha.png', 'image/png', null, true));

        $decoded = imagecreatefrompng($result['path']);
        self::assertInstanceOf(\GdImage::class, $decoded);
        $corner = imagecolorat($decoded, 0, 0);
        self::assertIsInt($corner);
        // GD packs alpha in bits 24-30 (0 opaque .. 127 fully transparent).
        self::assertSame(127, ($corner >> 24) & 0x7F);
        imagedestroy($decoded);
        @unlink($result['path']);
    }

    #[Test]
    public function aCorruptImageIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_corrupt_');
        self::assertIsString($path);
        file_put_contents($path, 'garbage that is not an image');

        $this->expectInvalidImage($path);
    }

    #[Test]
    public function aNonImageIsRejectedWithTheInvalidMimeKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_text_');
        self::assertIsString($path);
        file_put_contents($path, 'plain text');

        $this->expectInvalidImage($path);
    }

    #[Test]
    public function exifOrientation6IsAppliedSoTheReEncodedImageIsUpright(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_exif_');
        self::assertIsString($path);
        file_put_contents($path, $this->jpegWithExifOrientation(6, 4, 2));

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true));

        $size = getimagesize($result['path']);
        self::assertIsArray($size);
        self::assertSame([2, 4], [$size[0], $size[1]]);
        @unlink($result['path']);
    }

    #[Test]
    public function exifOrientation3KeepsTheDimensions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitizer_exif_');
        self::assertIsString($path);
        file_put_contents($path, $this->jpegWithExifOrientation(3, 4, 2));

        $result = $this->sanitizer->sanitize(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true));

        $size = getimagesize($result['path']);
        self::assertIsArray($size);
        self::assertSame([4, 2], [$size[0], $size[1]]);
        @unlink($result['path']);
    }

    private function expectInvalidImage(string $path): void
    {
        try {
            $this->sanitizer->sanitize(new UploadedFile($path, 'x.php', 'image/jpeg', null, true));
            self::fail('Expected FunctionalException for invalid image.');
        } catch (FunctionalException $e) {
            self::assertSame('chat_image.invalid_mime', $e->getErrorKey());
        }
    }

    private function expectTooLargeImage(string $path): void
    {
        try {
            $this->sanitizer->sanitize(new UploadedFile($path, 'x.php', 'image/jpeg', null, true));
            self::fail('Expected FunctionalException for the oversized image.');
        } catch (FunctionalException $e) {
            self::assertSame('chat_image.too_large', $e->getErrorKey());
        }
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function gdJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }

    /**
     * A minimal EXIF APP1 (little-endian TIFF, IFD0 Orientation tag) spliced in after the
     * mandatory JFIF APP0 block, so exif_read_data sees the orientation we test.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function jpegWithExifOrientation(int $orientation, int $width, int $height): string
    {
        $jpeg = $this->gdJpeg($width, $height);
        $app0 = unpack('n', substr($jpeg, 4, 2));
        self::assertIsArray($app0);
        $app0Length = $app0[1];
        self::assertIsInt($app0Length);
        $tiff = "II\x2a\x00"
            . pack('V', 8)                       // offset to IFD0
            . pack('v', 1)                        // one entry
            . pack('v', 0x0112) . pack('v', 3)    // tag Orientation, type SHORT
            . pack('V', 1) . pack('v', $orientation) . pack('v', 0)
            . pack('V', 0);                       // no next IFD
        $app1 = "\xFF\xE1" . pack('n', 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 4 + $app0Length) . $app1 . substr($jpeg, 4 + $app0Length);
    }
}
