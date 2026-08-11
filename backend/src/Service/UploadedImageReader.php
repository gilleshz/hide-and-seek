<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FunctionalException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every multipart endpoint that takes a player photo, chat images, photo answers and the card a
 * powerup is played with, has to reject the same oversized and non-image uploads the same way.
 */
final readonly class UploadedImageReader
{
    private const int MAX_SIZE_BYTES = 8_388_608;

    private const array ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function image(): UploadedFile
    {
        $file = $this->currentRequest()->files->get('image');
        if (!$file instanceof UploadedFile) {
            throw new FunctionalException(
                message: 'Missing or invalid image file.',
                errorKey: 'chat_image.missing_file',
            );
        }

        $this->assertSizeAccepted($file);
        $this->assertMimeAccepted($file);

        return $file;
    }

    public function requiredField(string $name): string
    {
        $value = $this->currentRequest()->request->get($name);
        if (!is_string($value) || $value === '') {
            throw new FunctionalException(
                message: sprintf('Missing "%s" field.', $name),
                errorKey: 'chat_image.missing_field',
            );
        }

        return $value;
    }

    private function assertSizeAccepted(UploadedFile $file): void
    {
        $sizeExceeded = in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
            || $file->getSize() > self::MAX_SIZE_BYTES;
        if ($sizeExceeded) {
            throw new FunctionalException(
                message: 'Image exceeds maximum size of 8 MB.',
                errorKey: 'chat_image.size_exceeded',
            );
        }
    }

    private function assertMimeAccepted(UploadedFile $file): void
    {
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new FunctionalException(
                message: 'Missing or invalid image file.',
                errorKey: 'chat_image.missing_file',
            );
        }

        $mime = $file->getMimeType();
        if ($mime === null || !in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new FunctionalException(
                message: 'Only JPEG, PNG, and WebP images are accepted.',
                errorKey: 'chat_image.invalid_mime',
            );
        }
    }

    private function currentRequest(): Request
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new FunctionalException(message: 'No request available.', errorKey: 'chat_image.no_request');
        }

        return $request;
    }
}
