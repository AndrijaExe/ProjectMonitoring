<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformEndpointsTest extends WebTestCase
{
    public function testHealthzIsLive(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent() ?: '', true));
    }

    public function testReadyzCanWriteStore(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ready'], json_decode($client->getResponse()->getContent() ?: '', true));
    }

    public function testDashboardRedirectsUntilLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testIngestAcceptsASampleMetric(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/projects/loop9/metrics',
            server: [
                'HTTP_X_INGEST_TOKEN' => 'test-ingest-token-ok',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'metrics' => [
                    ['name' => 'chat.requests', 'value' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 1], json_decode($client->getResponse()->getContent() ?: '', true));
    }

    public function testIngestRejectsBadToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/projects/loop9/metrics',
            server: [
                'HTTP_X_INGEST_TOKEN' => 'nope-nope-nope-nope',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['metrics' => [['name' => 'chat.requests', 'value' => 1]]], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
