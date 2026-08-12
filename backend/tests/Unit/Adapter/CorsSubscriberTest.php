<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Http\EventSubscriber\CorsSubscriber;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorsSubscriberTest extends TestCase
{
    public function testAListedOriginIsEchoedBack(): void
    {
        $response = $this->handle(
            'https://console.example.com',
            'https://console.example.com,https://staging.example.com',
        );

        self::assertSame('https://console.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->headers->get('Vary'));
        self::assertStringContainsString('X-Admin-Token', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testAnUnlistedOriginGetsNoCorsHeaderAtAll(): void
    {
        $response = $this->handle('https://attacker.example', 'https://console.example.com');

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function testATrailingSlashStillMatches(): void
    {
        $response = $this->handle('https://console.example.com/', 'https://console.example.com');

        self::assertSame('https://console.example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testWithoutConfigurationOnlyLocalhostIsAllowed(): void
    {
        $allowed = $this->handle('http://127.0.0.1:5173', '');
        $blocked = $this->handle('https://console.example.com', '');

        self::assertSame('http://127.0.0.1:5173', $allowed->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($blocked->headers->has('Access-Control-Allow-Origin'));
    }

    public function testPreflightAnswersWithNoContent(): void
    {
        $response = $this->handle('https://console.example.com', 'https://console.example.com', 'OPTIONS');

        self::assertSame(204, $response->getStatusCode());
    }

    public function testNonApiPathsAreLeftAlone(): void
    {
        $response = $this->handle('https://console.example.com', 'https://console.example.com', 'GET', '/healthz');

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    private function handle(
        string $origin,
        string $allowedOrigins,
        string $method = 'GET',
        string $path = '/api/v1/overview',
    ): Response {
        $request = Request::create($path, $method);
        $request->headers->set('Origin', $origin);
        $response = new Response();

        (new CorsSubscriber($allowedOrigins))->onResponse(new ResponseEvent(
            new Kernel('test', true),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }
}
