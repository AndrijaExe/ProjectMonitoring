<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Alerting\ExpoPushAlertChannel;
use App\Adapter\Alerting\FanOutAlertChannel;
use App\Adapter\Alerting\ResendAlertChannel;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\FakeNotification;
use App\Tests\Support\InMemoryDeviceTokenStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FanOutAlertChannelTest extends TestCase
{
    private const TOKEN = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    public function testAnAlertGoesToTheInboxAndThePhoneAtOnce(): void
    {
        $called = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$called): MockResponse {
            $called[] = $url;

            return new MockResponse('{"data":[{"status":"ok","id":"1"}],"id":"abc"}');
        });

        $this->fanOut($client, [self::TOKEN])->send($this->notification());

        self::assertSame([
            'https://api.resend.com/emails',
            'https://exp.host/--/api/v2/push/send',
        ], $called);
    }

    public function testNothingSetUpAnywhereIsNotConfigured(): void
    {
        $channel = $this->fanOut(new MockHttpClient([]), [], mailKey: '');

        self::assertFalse($channel->isConfigured());
    }

    public function testAPhoneAloneIsEnoughToBeConfigured(): void
    {
        $channel = $this->fanOut(new MockHttpClient([]), [self::TOKEN], mailKey: '');

        self::assertTrue($channel->isConfigured());
    }

    /**
     * The point of the whole class. The caller records alarm state only when send() returns
     * cleanly, so raising here would re-raise the alarm on every poll and mail the operator
     * again and again because of a broken push route.
     */
    public function testAFailedPushIsForgivenWhenTheMailGotThrough(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return $url === 'https://api.resend.com/emails'
                ? new MockResponse('{"id":"abc"}')
                : new MockResponse('{"errors":[{"message":"expo is down"}]}', ['http_code' => 503]);
        });

        $this->fanOut($client, [self::TOKEN])->send($this->notification());

        self::assertTrue(true, 'no exception: the operator was reached by mail');
    }

    public function testWhenEveryRouteFailsTheCallerIsTold(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"no"}', ['http_code' => 500]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No alert route accepted the alert.');

        $this->fanOut($client, [self::TOKEN])->send($this->notification());
    }

    /**
     * @param list<string> $tokens
     */
    private function fanOut(MockHttpClient $client, array $tokens, string $mailKey = 're_key'): FanOutAlertChannel
    {
        return new FanOutAlertChannel(
            new ResendAlertChannel($client, $mailKey, $mailKey === '' ? '' : 'me@example.test'),
            new ExpoPushAlertChannel($client, new InMemoryDeviceTokenStore($tokens), new NullLogger()),
            new NullLogger(),
        );
    }

    private function notification(): FakeNotification
    {
        return new FakeNotification(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));
    }
}
