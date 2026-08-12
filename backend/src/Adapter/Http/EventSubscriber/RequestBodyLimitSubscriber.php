<?php

declare(strict_types=1);

namespace App\Adapter\Http\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestBodyLimitSubscriber implements EventSubscriberInterface
{
    public const MAX_BODY_BYTES = 65536;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return;
        }

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw new HttpException(413, sprintf('Request body must be at most %d bytes.', self::MAX_BODY_BYTES));
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, sprintf('Request body must be at most %d bytes.', self::MAX_BODY_BYTES));
        }
    }
}
