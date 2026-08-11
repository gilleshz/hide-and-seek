<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 401: the caller could not be identified as an active player (missing/invalid token, unknown or
 * left player). Carries an identity.* errorKey so the app can route to its re-join flow.
 */
final class IdentityRequiredException extends HttpException
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
        parent::__construct(Response::HTTP_UNAUTHORIZED, $message, $previous);
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
