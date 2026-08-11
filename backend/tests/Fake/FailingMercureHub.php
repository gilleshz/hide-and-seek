<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Fails the first N publishes, then works: lets a rollback test drive the publish-failure path and
 * a clean retry within one kernel.
 */
final class FailingMercureHub implements HubInterface
{
    private int $failuresLeft;

    public function __construct(int $failures)
    {
        $this->failuresLeft = $failures;
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        if ($this->failuresLeft > 0) {
            --$this->failuresLeft;

            throw new \RuntimeException('Mercure hub unavailable');
        }

        return 'ok';
    }
}
