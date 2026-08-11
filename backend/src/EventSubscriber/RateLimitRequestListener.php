<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\RateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Per-IP budgets on the endpoints attackers can hammer (join, token mint, location ingest) plus a
 * global per-IP ceiling on everything under /api/. Runs after the API-key listener (priority 20),
 * so unauthenticated traffic is rejected before it counts.
 */
final readonly class RateLimitRequestListener implements EventSubscriberInterface
{
    private const string API_PREFIX = '/api';

    public function __construct(
        #[Autowire(service: 'limiter.join')]
        private RateLimiterFactory $join,
        #[Autowire(service: 'limiter.account_connect')]
        private RateLimiterFactory $accountConnect,
        #[Autowire(service: 'limiter.account_password_change')]
        private RateLimiterFactory $accountPasswordChange,
        #[Autowire(service: 'limiter.game_create')]
        private RateLimiterFactory $gameCreate,
        #[Autowire(service: 'limiter.token_mint')]
        private RateLimiterFactory $tokenMint,
        #[Autowire(service: 'limiter.location_ingest_ip')]
        private RateLimiterFactory $locationIngestIp,
        #[Autowire(service: 'limiter.api_global')]
        private RateLimiterFactory $apiGlobal,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isGuarded($event->getRequest()->getPathInfo())) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $limiter = $this->limiterFor($request, $path);
        if (!$limiter->create('ip:' . $request->getClientIp())->consume()->isAccepted()) {
            throw new RateLimitExceededException('Too many requests.');
        }
    }

    private function limiterFor(Request $request, string $path): RateLimiterFactory
    {
        if ($path === '/api/games' && $request->isMethod('POST')) {
            return $this->gameCreate;
        }

        if (preg_match('#^/api/games/[^/]+/join$#', $path) === 1) {
            return $this->join;
        }

        if (preg_match('#^/api/accounts$#', $path) === 1) {
            return $this->accountConnect;
        }

        if (preg_match('#^/api/account/password$#', $path) === 1) {
            return $this->accountPasswordChange;
        }

        if (
            preg_match('#^/api/rounds/[^/]+/team$#', $path) === 1
            || preg_match('#^/api/rounds/[^/]+/subscriber-token$#', $path) === 1
        ) {
            return $this->tokenMint;
        }

        if (preg_match('#^/api/rounds/[^/]+/location$#', $path) === 1) {
            return $this->locationIngestIp;
        }

        return $this->apiGlobal;
    }

    private function isGuarded(string $path): bool
    {
        if (!str_starts_with($path, self::API_PREFIX)) {
            return false;
        }

        return $path !== self::API_PREFIX
            && !str_starts_with($path, '/api/docs')
            && !str_starts_with($path, '/api/contexts');
    }
}
