<?php

declare(strict_types=1);

namespace App\Adapter\Http\EventSubscriber;

use App\Adapter\Http\WriteAccessDenied;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof WriteAccessDenied) {
            // Checked before the status match below: this is a 403 that must not read as a
            // dead token, or a read-only client signs itself out over it.
            $statusCode = $exception->getStatusCode();
            $publicMessage = $exception->getMessage();
            $code = WriteAccessDenied::CODE;
        } elseif ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $publicMessage = $statusCode >= 500 ? 'Internal server error.' : $exception->getMessage();
            $code = match ($statusCode) {
                401, 403 => 'UNAUTHORIZED',
                404 => 'NOT_FOUND',
                429 => 'RATE_LIMITED',
                default => $statusCode >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
            };
        } elseif ($exception instanceof \InvalidArgumentException) {
            $statusCode = 400;
            $publicMessage = $exception->getMessage();
            $code = 'REQUEST_ERROR';
        } else {
            $statusCode = 500;
            $publicMessage = 'Internal server error.';
            $code = 'INTERNAL_ERROR';
        }

        $this->logger->error('API request failed.', [
            'statusCode' => $statusCode,
            'path' => $event->getRequest()->getPathInfo(),
            'exceptionClass' => $exception::class,
        ]);

        $event->setResponse(new JsonResponse([
            'error' => [
                'message' => $publicMessage,
                'code' => $code,
            ],
        ], $statusCode));
    }
}
