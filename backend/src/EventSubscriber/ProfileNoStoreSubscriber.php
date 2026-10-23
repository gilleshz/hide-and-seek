<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A profile response must never be reused across identities: the device key is a header, so an
 * intermediary or client cache that ignores it would otherwise replay another identity's profile.
 */
final readonly class ProfileNoStoreSubscriber implements EventSubscriberInterface
{
    private const string PREFIX = '/api/profile';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), self::PREFIX)) {
            return;
        }

        $event->getResponse()->setCache(['no_store' => true, 'max_age' => 0, 'must_revalidate' => true]);
    }
}
