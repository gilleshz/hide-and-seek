<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Player-keyed budgets enforced inside processors once the caller's identity is known; the
 * per-IP budgets live in the request listener.
 */
final readonly class RateLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.location_ingest_player')]
        private RateLimiterFactory $locationIngestPlayer,
        #[Autowire(service: 'limiter.chat_send')]
        private RateLimiterFactory $chatSend,
    ) {
    }

    public function locationIngest(string $playerUuid): void
    {
        $this->consume($this->locationIngestPlayer, 'player:' . $playerUuid);
    }

    public function chatSend(string $playerUuid): void
    {
        $this->consume($this->chatSend, 'player:' . $playerUuid);
    }

    private function consume(RateLimiterFactory $limiter, string $key): void
    {
        if (!$limiter->create($key)->consume()->isAccepted()) {
            throw new RateLimitExceededException('Too many requests.');
        }
    }
}
