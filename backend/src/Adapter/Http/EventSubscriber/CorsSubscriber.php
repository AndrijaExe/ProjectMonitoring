<?php

declare(strict_types=1);

namespace App\Adapter\Http\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorsSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private readonly array $allowedOrigins;

    public function __construct(
        #[Autowire('%env(CORS_ALLOWED_ORIGINS)%')]
        string $allowedOrigins = '',
    ) {
        $origins = [];
        foreach (explode(',', $allowedOrigins) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        $this->allowedOrigins = $origins;
    }

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

        $response = $event->getResponse();
        $origin = rtrim((string) $request->headers->get('Origin', ''), '/');

        // No wildcard: an unlisted origin gets no CORS header, so the browser blocks it.
        if ($origin !== '' && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
            $response->headers->set(
                'Access-Control-Allow-Headers',
                'Content-Type, X-Admin-Token, X-Ingest-Token, X-Request-Id',
            );
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            $response->setStatusCode(204);
        }
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if ($this->allowedOrigins !== []) {
            return in_array($origin, $this->allowedOrigins, true);
        }

        // Unconfigured means local development, where the Vite dev server is the only caller.
        $host = (string) (parse_url($origin, PHP_URL_HOST) ?? '');

        return $host === 'localhost' || $host === '127.0.0.1';
    }
}
