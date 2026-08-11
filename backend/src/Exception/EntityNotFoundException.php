<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Extends HttpException so the kernel treats it as an expected 404 instead of logging it at CRITICAL.
final class EntityNotFoundException extends HttpException
{
    private ?string $errorKey;
    /** @var array<string, string|int>|null */
    private ?array $errorArgs;

    /**
     * @param array<string, string|int>|null $errorArgs
     */
    public function __construct(
        string $message,
        ?string $errorKey = null,
        ?array $errorArgs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(Response::HTTP_NOT_FOUND, $message, $previous);
        $this->errorKey = $errorKey;
        $this->errorArgs = $errorArgs;
    }

    public function getErrorKey(): ?string
    {
        return $this->errorKey;
    }

    /**
     * @return array<string, string|int>|null
     */
    public function getErrorArgs(): ?array
    {
        return $this->errorArgs;
    }
}
