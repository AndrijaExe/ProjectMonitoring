<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Probe\HttpHealthProbe;
use App\Model\HealthEndpoint;
use App\Model\HealthStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpHealthProbeTest extends TestCase
{
    public function testASleepingHostThatWakesUpCountsAsHealthy(): void
    {
        $calls = 0;
        $probe = new HttpHealthProbe(new MockHttpClient(function () use (&$calls): MockResponse {
            if (++$calls === 1) {
                throw new TimeoutException('Idle timeout reached');
            }

            return new MockResponse('{"status":"ok"}', ['http_code' => 200]);
        }), 1.0);

        $result = $probe->probe('https://example.test/healthz', HealthEndpoint::Health);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(200, $result->httpCode);
        self::assertNull($result->error);
        self::assertSame(2, $calls);
    }

    public function testRepeatedTimeoutsReportTimeoutRatherThanDown(): void
    {
        $calls = 0;
        $probe = new HttpHealthProbe(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            throw new TimeoutException('Idle timeout reached');
        }), 1.0);

        $result = $probe->probe('https://example.test/healthz', HealthEndpoint::Health);

        self::assertSame(HealthStatus::Timeout, $result->status);
        self::assertFalse($result->status->isHealthy());
        self::assertSame(0, $result->httpCode);
        self::assertStringContainsString('2 attempts', (string) $result->error);
        self::assertSame(2, $calls);
    }

    public function testRefusedConnectionIsDownAndIsNotRetried(): void
    {
        $calls = 0;
        $probe = new HttpHealthProbe(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            throw new TransportException('Connection refused for "https://example.test/healthz".');
        }), 1.0);

        $result = $probe->probe('https://example.test/healthz', HealthEndpoint::Health);

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertStringContainsString('Connection refused', (string) $result->error);
        self::assertSame(1, $calls);
    }

    public function testServerErrorOnHealthIsNotRetried(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"degraded"}', ['http_code' => 500]),
        ]);
        $probe = new HttpHealthProbe($client, 1.0);

        $result = $probe->probe('https://example.test/healthz', HealthEndpoint::Health);

        self::assertSame(HealthStatus::Error, $result->status);
        self::assertSame(500, $result->httpCode);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testReadyEndpointMapsUnavailableToNotReady(): void
    {
        $probe = new HttpHealthProbe(new MockHttpClient([
            new MockResponse('{"status":"not_ready"}', ['http_code' => 503]),
        ]), 1.0);

        $result = $probe->probe('https://example.test/readyz', HealthEndpoint::Ready);

        self::assertSame(HealthStatus::NotReady, $result->status);
    }
}
