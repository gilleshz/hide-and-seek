<?php

declare(strict_types=1);

namespace App\Exception;

use App\ErrorKey;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 429: a per-IP or per-player request budget is exhausted. The app must back off on its own cadence
 * (no Retry-After header is sent).
 */
final class RateLimitExceededException extends HttpException
{
    private string $errorKey;

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
        parent::__construct(Response::HTTP_TOO_MANY_REQUESTS, $message, $previous);
        $this->errorKey = $errorKey ?? ErrorKey::RATE_LIMIT_EXCEEDED;
        $this->errorArgs = $errorArgs;
    }

    public function getErrorKey(): string
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
