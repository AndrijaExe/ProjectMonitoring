<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Alerting\ExpoPushAlertChannel;
use App\Model\Alert;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\FakeNotification;
use App\Tests\Support\InMemoryDeviceTokenStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExpoPushAlertChannelTest extends TestCase
{
    private const TOKEN = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';
    private const OTHER = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    public function testWithNoPhoneRegisteredThereIsNothingToSend(): void
    {
        $client = new MockHttpClient([]);
        $channel = $this->channel($client, new InMemoryDeviceTokenStore());

        self::assertFalse($channel->isConfigured());

        $channel->send($this->alert());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItPostsOneMessagePerPhone(): void
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://exp.host/--/api/v2/push/send', $url);
            $body = json_decode($options['body'], true);

            return new MockResponse('{"data":[{"status":"ok","id":"1"},{"status":"ok","id":"2"}]}');
        });

        $channel = $this->channel($client, new InMemoryDeviceTokenStore([self::TOKEN, self::OTHER]));
        self::assertTrue($channel->isConfigured());

        $channel->send($this->alert());

        self::assertCount(2, $body);
        self::assertSame(self::TOKEN, $body[0]['to']);
        self::assertSame(self::OTHER, $body[1]['to']);
        self::assertSame('Loop 9 health is down', $body[0]['title']);
        self::assertSame('loop9', $body[0]['data']['gameId']);
        // An outage is worth waking the phone for, and a channel it has never heard of would
        // be dropped without a trace.
        self::assertSame('high', $body[0]['priority']);
        self::assertSame('alerts', $body[0]['channelId']);
    }

    public function testAnAccessTokenIsSentWhenTheExpoAccountRequiresOne(): void
    {
        $headers = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['headers'];

            return new MockResponse('{"data":[{"status":"ok","id":"1"}]}');
        });

        $this->channel($client, new InMemoryDeviceTokenStore([self::TOKEN]), 'expo-secret')
            ->send($this->alert());

        self::assertContains('Authorization: Bearer expo-secret', $headers);
    }

    public function testAPhoneExpoNoLongerKnowsIsForgottenRatherThanRetriedForever(): void
    {
        $devices = new InMemoryDeviceTokenStore([self::TOKEN, self::OTHER]);
        $client = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
                ['status' => 'ok', 'id' => '2'],
            ],
        ])));

        $this->channel($client, $devices)->send($this->alert());

        // One phone left, and no exception: the alert did reach somebody.
        self::assertSame([self::OTHER], $devices->all());
    }

    public function testAPhoneThatFailedForAnotherReasonKeepsItsRegistration(): void
    {
        $devices = new InMemoryDeviceTokenStore([self::TOKEN, self::OTHER]);
        $client = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['status' => 'error', 'message' => 'slow down', 'details' => ['error' => 'MessageRateExceeded']],
                ['status' => 'ok', 'id' => '2'],
            ],
        ])));

        $this->channel($client, $devices)->send($this->alert());

        self::assertSame([self::TOKEN, self::OTHER], $devices->all());
    }

    public function testWhenNoPhoneAcceptedItTheCallerIsTold(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'data' => [['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']]],
        ])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expo accepted none of the push messages.');

        $this->channel($client, new InMemoryDeviceTokenStore([self::TOKEN]))->send($this->alert());
    }

    public function testARefusedRequestIsReportedSoTheCallerCanLogIt(): void
    {
        $client = new MockHttpClient(new MockResponse('{"errors":[{"message":"invalid access token"}]}', ['http_code' => 400]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expo answered 400');

        $this->channel($client, new InMemoryDeviceTokenStore([self::TOKEN]))->send($this->alert());
    }

    public function testALongBodyIsShortenedRatherThanRefusedForBeingTooBig(): void
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode($options['body'], true);

            return new MockResponse('{"data":[{"status":"ok","id":"1"}]}');
        });

        $this->channel($client, new InMemoryDeviceTokenStore([self::TOKEN]))
            ->send(new FakeNotification($this->project(), 'Loop 9 is down', str_repeat('evidence ', 200)));

        self::assertLessThanOrEqual(400, mb_strlen($body[0]['body']));
        self::assertStringEndsWith('…', $body[0]['body']);
    }

    private function channel(
        MockHttpClient $client,
        InMemoryDeviceTokenStore $devices,
        string $accessToken = '',
    ): ExpoPushAlertChannel {
        return new ExpoPushAlertChannel($client, $devices, new NullLogger(), $accessToken);
    }

    private function alert(): Alert
    {
        $project = $this->project();

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

    private function project(): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        );
    }
}
