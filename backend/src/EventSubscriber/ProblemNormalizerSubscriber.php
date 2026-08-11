<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Exception\RateLimitExceededException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Carries our machine-readable errorKey into the problem document so the app can translate the
 * failure instead of echoing the server's English detail.
 *
 * API Platform renders the document from an Error resource well after kernel.exception, so the key
 * is parked on the request there and merged in on the way out.
 */
final readonly class ProblemNormalizerSubscriber implements EventSubscriberInterface
{
    private const string KEY_ATTRIBUTE = '_jetlag_error_key';
    private const string ARGS_ATTRIBUTE = '_jetlag_error_args';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (
            !$exception instanceof FunctionalException
            && !$exception instanceof EntityNotFoundException
            && !$exception instanceof IdentityRequiredException
            && !$exception instanceof RateLimitExceededException
        ) {
            return;
        }

        $errorKey = $exception->getErrorKey();
        if ($errorKey === null) {
            return;
        }

        $event->getRequest()->attributes->set(self::KEY_ATTRIBUTE, $errorKey);
        $event->getRequest()->attributes->set(self::ARGS_ATTRIBUTE, $exception->getErrorArgs() ?? []);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $errorKey = $event->getRequest()->attributes->get(self::KEY_ATTRIBUTE);
        if (!is_string($errorKey)) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        $data = is_string($content) && $content !== '' ? json_decode($content, true) : null;
        if (!is_array($data)) {
            return;
        }

        $args = $event->getRequest()->attributes->get(self::ARGS_ATTRIBUTE);
        $data['errorKey'] = $errorKey;
        $data['errorArgs'] = is_array($args) ? $args : [];
        $response->setContent(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
