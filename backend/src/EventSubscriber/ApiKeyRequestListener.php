<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiKeyRequestListener implements EventSubscriberInterface
{
    public const string HEADER = 'X-API-KEY';

    private const string API_PREFIX = '/api';

    public function __construct(#[Autowire('%env(APP_API_KEY)%')] private string $apiKey)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isGuarded($event->getRequest()->getPathInfo())) {
            return;
        }

        $request = $event->getRequest();
        $provided = (string) $request->headers->get(self::HEADER, '');

        if ($this->apiKey === '' || !hash_equals($this->apiKey, $provided)) {
            throw new UnauthorizedHttpException('Header ' . self::HEADER, 'Invalid or missing API key.');
        }
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
