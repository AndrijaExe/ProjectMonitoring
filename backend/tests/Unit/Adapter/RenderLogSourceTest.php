<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Logs\RenderLogSource;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\LogFilter;
use App\Model\LogsUnavailable;
use App\Model\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RenderLogSourceTest extends TestCase
{
    public function testWithoutAKeyItReportsItselfUnconfigured(): void
    {
        $source = new RenderLogSource(new MockHttpClient([]), new ArrayAdapter(), '');

        self::assertFalse($source->isConfigured());

        $this->expectException(LogsUnavailable::class);
        $source->recent($this->project(), new LogFilter());
    }

    public function testItResolvesTheServiceFromTheHealthUrlAndMapsLines(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            if (str_contains($url, '/services')) {
                return new MockResponse(json_encode([
                    ['cursor' => 'abc', 'service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]), ['response_headers' => ['content-type' => 'application/json']]);
            }

            return new MockResponse(json_encode([
                'hasMore' => false,
                'logs' => [
                    [
                        'id' => 'log-1',
                        'message' => 'Chat request finished',
                        'timestamp' => '2026-08-13T07:20:05.777035+00:00',
                        'labels' => [
                            ['name' => 'level', 'value' => 'info'],
                            ['name' => 'type', 'value' => 'app'],
                        ],
                    ],
                ],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $source = new RenderLogSource($client, new ArrayAdapter(), 'rnd_key');
        $page = $source->recent($this->project(), new LogFilter(50, 'info', 'chat'));
        $lines = $page->lines;

        // The panel names whoever wrote the lines, so two services on one screen cannot be
        // mistaken for each other.
        self::assertSame('loop9-backend', $page->source);
        self::assertCount(1, $lines);
        self::assertSame('Chat request finished', $lines[0]->message);
        self::assertSame('info', $lines[0]->level);
        self::assertSame('app', $lines[0]->type);
        self::assertSame('2026-08-13T07:20:05+00:00', $lines[0]->at->format(DATE_ATOM));

        self::assertStringContainsString('name=loop9-backend', $requests[0]);
        self::assertStringContainsString('ownerId=tea-9', $requests[1]);
        // Repeated keys, not PHP's resource[0]= form, which Render ignores.
        self::assertStringContainsString('resource=srv-123', $requests[1]);
        self::assertStringContainsString('level=info', $requests[1]);
        // Render matches log text with wildcards, so a bare term would find nothing.
        self::assertStringContainsString('text=%2Achat%2A', $requests[1]);
    }

    public function testLinesComeBackNewestFirstWhateverOrderRenderUsed(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/services')) {
                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse(json_encode(['logs' => [
                $this->entry('oldest', '2026-08-13T07:00:00+00:00'),
                $this->entry('newest', '2026-08-13T09:00:00+00:00'),
                $this->entry('middle', '2026-08-13T08:00:00+00:00'),
            ]]));
        });

        $lines = (new RenderLogSource($client, new ArrayAdapter(), 'rnd_key'))
            ->recent($this->project(), new LogFilter())
            ->lines;

        // Oldest first buries the line that made somebody open the panel.
        self::assertSame(['newest', 'middle', 'oldest'], array_map(
            static fn ($line): string => $line->message,
            $lines,
        ));
    }

    public function testTerminalColoursAreStrippedFromRendersOwnLines(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/services')) {
                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse(json_encode(['logs' => [
                $this->entry("\e[0;32m\e[1m==> \e[1mYour service is live\e[0m", '2026-08-13T09:00:00+00:00'),
            ]]));
        });

        $lines = (new RenderLogSource($client, new ArrayAdapter(), 'rnd_key'))
            ->recent($this->project(), new LogFilter())
            ->lines;

        // A browser renders the escape codes as literal noise in front of the message.
        self::assertSame('==> Your service is live', $lines[0]->message);
    }

    public function testTheMonitorReadsOnlyItsOwnServiceWhenLookingAtItself(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            if (str_contains($url, '/services/')) {
                return new MockResponse(json_encode(
                    ['id' => 'srv-self', 'ownerId' => 'tea-9', 'name' => 'monitoring-api'],
                ));
            }

            return new MockResponse(json_encode(['logs' => []]));
        });

        $page = (new RenderLogSource($client, new ArrayAdapter(), 'rnd_key'))
            ->recentForService('srv-self', new LogFilter());

        self::assertSame('monitoring-api', $page->source);
        self::assertStringContainsString('/services/srv-self', $requests[0]);
        // One resource, and it is not the game's.
        self::assertStringContainsString('resource=srv-self', $requests[1]);
        self::assertStringNotContainsString('srv-123', $requests[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $message, string $timestamp): array
    {
        return [
            'id' => 'log-'.$message,
            'message' => $message,
            'timestamp' => $timestamp,
            'labels' => [['name' => 'level', 'value' => 'info']],
        ];
    }

    public function testTheServiceLookupHappensOncePerName(): void
    {
        $lookups = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$lookups): MockResponse {
            if (str_contains($url, '/services')) {
                ++$lookups;

                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse(json_encode(['logs' => []]));
        });

        $source = new RenderLogSource($client, new ArrayAdapter(), 'rnd_key');
        $source->recent($this->project(), new LogFilter());
        $source->recent($this->project(), new LogFilter());

        self::assertSame(1, $lookups);
    }

    public function testARejectedKeySaysSoRatherThanLeakingTheStatusCode(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"unauthorized"}', ['http_code' => 401]));
        $source = new RenderLogSource($client, new ArrayAdapter(), 'wrong-key');

        $this->expectException(LogsUnavailable::class);
        $this->expectExceptionMessage('Render rejected the API key.');

        $source->recent($this->project(), new LogFilter());
    }

    public function testATargetOutsideRenderIsRefusedBeforeAnyRequest(): void
    {
        $client = new MockHttpClient([]);
        $source = new RenderLogSource($client, new ArrayAdapter(), 'rnd_key');

        $this->expectException(LogsUnavailable::class);
        $this->expectExceptionMessage('only wired for targets hosted on Render');

        $source->recent($this->project('https://example.test/healthz'), new LogFilter());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItFallsBackToTheUrlRenderReportsWhenTheNameDiffers(): void
    {
        // Render appends a suffix to the hostname when the name is taken platform-wide, so a
        // service called "api" can answer on api-0gy1.onrender.com.
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'name=loop9-backend')) {
                return new MockResponse('[]');
            }

            if (str_contains($url, '/services')) {
                return new MockResponse(json_encode([
                    ['service' => [
                        'id' => 'srv-other',
                        'ownerId' => 'tea-9',
                        'name' => 'unrelated',
                        'serviceDetails' => ['url' => 'https://unrelated.onrender.com'],
                    ]],
                    ['service' => [
                        'id' => 'srv-777',
                        'ownerId' => 'tea-9',
                        'name' => 'loop9-backend-renamed',
                        'serviceDetails' => ['url' => 'https://loop9-backend.onrender.com'],
                    ]],
                ]));
            }

            return new MockResponse(json_encode(['logs' => []]));
        });

        $source = new RenderLogSource($client, new ArrayAdapter(), 'rnd_key');
        $source->recent($this->project(), new LogFilter());

        self::assertSame(3, $client->getRequestsCount());
    }

    public function testAMissingServiceReportsWhatTheKeyCanSee(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'name=loop9-backend')) {
                return new MockResponse('[]');
            }

            return new MockResponse(json_encode([
                ['service' => ['id' => 'srv-1', 'ownerId' => 'tea-9', 'name' => 'monitoring-api']],
                ['service' => ['id' => 'srv-2', 'ownerId' => 'tea-9', 'name' => 'monitoring-console']],
            ]));
        });

        $source = new RenderLogSource($client, new ArrayAdapter(), 'rnd_key');

        $this->expectException(LogsUnavailable::class);
        // Naming what the key can reach turns "not found" into a diagnosis: a key from the
        // wrong workspace lists the wrong services.
        $this->expectExceptionMessage('No Render service serves loop9-backend.onrender.com. This key sees: monitoring-api, monitoring-console.');

        $source->recent($this->project(), new LogFilter());
    }

    public function testTheFilterClampsHostileValues(): void
    {
        $filter = new LogFilter(9999, null, null, 999_999);

        self::assertSame(LogFilter::MAX_LIMIT, $filter->limit);
        self::assertSame(LogFilter::MAX_WINDOW_MINUTES, $filter->sinceMinutes);
        self::assertSame(1, (new LogFilter(0))->limit);
    }

    private function project(string $healthUrl = 'https://loop9-backend.onrender.com/healthz'): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            $healthUrl,
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('test-ingest-token-ok'),
        );
    }
}
