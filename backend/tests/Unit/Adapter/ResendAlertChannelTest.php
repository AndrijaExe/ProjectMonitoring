<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Alerting\ResendAlertChannel;
use App\Model\Alert;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResendAlertChannelTest extends TestCase
{
    public function testWithoutAKeyOrRecipientItStaysQuiet(): void
    {
        $client = new MockHttpClient([]);

        self::assertFalse((new ResendAlertChannel($client, '', 'me@example.test'))->isConfigured());
        self::assertFalse((new ResendAlertChannel($client, 're_key', ''))->isConfigured());

        (new ResendAlertChannel($client, '', ''))->send($this->alert());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItPostsTheAlertAndLinksBackToTheConsole(): void
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.resend.com/emails', $url);
            self::assertContains('Authorization: Bearer re_key', $options['headers']);
            $body = json_decode($options['body'], true);

            return new MockResponse('{"id":"abc"}');
        });

        $channel = new ResendAlertChannel(
            $client,
            're_key',
            'me@example.test, second@example.test',
            'alerts@example.test',
            'https://monitoring-console.onrender.com/',
        );
        $channel->send($this->alert());

        self::assertSame('alerts@example.test', $body['from']);
        self::assertSame(['me@example.test', 'second@example.test'], $body['to']);
        self::assertSame('Loop 9 health is down', $body['subject']);
        self::assertStringContainsString('Status:   down, was ok', $body['text']);
        self::assertStringContainsString('Note:     connection refused', $body['text']);
        self::assertStringContainsString('https://monitoring-console.onrender.com/projects/loop9', $body['text']);
    }

    public function testARefusedSendIsReportedSoTheCallerCanLogIt(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"invalid api key"}', ['http_code' => 401]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resend answered 401');

        (new ResendAlertChannel($client, 're_key', 'me@example.test'))->send($this->alert());
    }

    private function alert(): Alert
    {
        $project = new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        );

        return new Alert(
            $project,
            new HealthSnapshot(
                $project->gameId,
                HealthEndpoint::Health,
                HealthStatus::Down,
                0,
                31,
                new \DateTimeImmutable('2026-08-13T09:00:00+00:00'),
                'connection refused',
            ),
            HealthStatus::Ok,
        );
    }
}
