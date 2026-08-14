<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestDatabase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformEndpointsTest extends WebTestCase
{
    protected function setUp(): void
    {
        TestDatabase::reset();
    }

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

    public function testDashboardRequiresAdminToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/overview');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOverviewReturnsSeededLoop9(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/overview', server: [
            'HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok',
        ]);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        $payload = json_decode($content, true);
        self::assertIsArray($payload);
        self::assertSame('loop9', $payload['projects'][0]['game_id'] ?? null);
        self::assertStringContainsString('"totals_24h":{}', $content);
        // The board travels with its own age, so a console reading it can tell a live fleet
        // from a frozen one without being told separately.
        self::assertArrayHasKey('stale', $payload);
        self::assertArrayHasKey('last_probe_at', $payload);
        self::assertSame(120, $payload['stale_after_minutes'] ?? null);
    }

    public function testProjectDetailReturnsHistoryLanes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/projects/loop9', server: [
            'HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('loop9', $payload['project']['game_id'] ?? null);
        self::assertIsArray($payload['health_history'] ?? null);
        self::assertIsArray($payload['recent_metrics'] ?? null);
    }

    public function testProjectDetailRejectsUnknownGame(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/projects/not-a-game', server: [
            'HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginRejectsBadToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['token' => 'nope-nope-nope-nope'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
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

    public function testTheServiceRouteNeedsTheAdminTokenLikeEverythingElse(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/projects/loop9/service', content: json_encode(['action' => 'stop']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnActionNobodyOffersIsARequestErrorAndNotAnOutage(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/projects/loop9/service',
            server: ['HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'delete'], JSON_THROW_ON_ERROR),
        );

        // Read before the project is looked up, so a typo cannot be reported as a missing game.
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('rebuild, stop or start', $client->getResponse()->getContent() ?: '');
    }

    public function testWithoutAHostKeyTheRunStateIsReadableAndSaysWhatIsMissing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/projects/loop9/service', server: [
            'HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertFalse($payload['configured']);
        self::assertFalse($payload['enabled']);
        self::assertNull($payload['state']);
        self::assertStringContainsString('RENDER_API_KEY', (string) $payload['note']);
    }

    public function testARefusedActionDoesNotLookLikeADeadTokenToTheConsole(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/projects/loop9/service',
            server: ['HTTP_X_ADMIN_TOKEN' => 'test-admin-token-ok', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'stop'], JSON_THROW_ON_ERROR),
        );

        // Anything but 403 or 401: the console ends its session on those, and being signed out
        // while pressing a button is a worse answer than being told the switch is off.
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('RENDER_API_KEY', $client->getResponse()->getContent() ?: '');
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
