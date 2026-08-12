<?php

declare(strict_types=1);

namespace App\Adapter\Http\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $origin = (string) $request->headers->get('Origin', '');
        $response = $event->getResponse();

        if ($origin !== '' && $origin !== 'null' && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, X-Admin-Token, X-Ingest-Token, X-Request-Id',
        );
        $response->headers->set('Access-Control-Max-Age', '600');

        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            $response->setStatusCode(204);
        }
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $host = (string) (parse_url($origin, PHP_URL_HOST) ?? '');

        return $host === 'localhost' || $host === '127.0.0.1';
    }
}
