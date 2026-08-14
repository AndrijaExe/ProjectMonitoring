<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Render\RenderApi;
use App\Adapter\Render\RenderServiceControl;
use App\Adapter\Render\RenderServiceDirectory;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Model\RenderUnavailable;
use App\Model\ServiceAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RenderServiceControlTest extends TestCase
{
    public function testItReadsTheRunStateAndTheDeployThatProducedIt(): void
    {
        $control = $this->control($this->host());

        $state = $control->state($this->project());

        self::assertSame('loop9-backend', $state->name);
        self::assertFalse($state->stopped);
        self::assertSame('live', $state->deployStatus);
        self::assertSame('Add player presence', $state->commit);
        self::assertSame('2026-08-13T09:12:00+00:00', $state->deployAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('Running.', $state->summary());
    }

    public function testASuspendedServiceIsReportedAsStoppedRatherThanUnwell(): void
    {
        $control = $this->control($this->host(suspended: 'suspended'));

        $state = $control->state($this->project());

        self::assertTrue($state->stopped);
        // The distinction the probes cannot make: nobody needs waking for a service that was
        // switched off on purpose.
        self::assertStringContainsString('until someone starts it', $state->summary());
    }

    public function testADeployInFlightIsBusySoTheButtonsCanWait(): void
    {
        $control = $this->control($this->host(deployStatus: 'build_in_progress'));

        $state = $control->state($this->project());

        self::assertTrue($state->isBusy());
        self::assertFalse($state->deployFailed());
    }

    public function testAFailedDeploySaysWhatIsActuallyRunning(): void
    {
        $control = $this->control($this->host(deployStatus: 'build_failed'));

        $state = $control->state($this->project());

        self::assertTrue($state->deployFailed());
        self::assertStringContainsString('previous build is still what is running', $state->summary());
    }

    /**
     * @return list<array{0: ServiceAction, 1: string}>
     */
    public static function actions(): array
    {
        return [
            [ServiceAction::Rebuild, '/services/srv-123/deploys'],
            [ServiceAction::Stop, '/services/srv-123/suspend'],
            [ServiceAction::Start, '/services/srv-123/resume'],
        ];
    }

    #[DataProvider('actions')]
    public function testEachActionPostsToItsOwnEndpointAndNothingElse(ServiceAction $action, string $expected): void
    {
        $calls = [];
        $bodies = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$bodies): MockResponse {
            $calls[] = $method.' '.parse_url($url, PHP_URL_PATH);
            $bodies[] = $options['body'] ?? null;

            if (str_contains($url, '/services?')) {
                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse(json_encode(['id' => 'dep-1']), ['http_code' => 202]);
        });

        $this->control($client)->apply($this->project(), $action);

        self::assertContains('POST /v1'.$expected, $calls);
        // One lookup and one action. A control plane that quietly issues extra calls with a key
        // this powerful is not one anybody can reason about.
        self::assertCount(2, $calls);

        // Suspend and resume take no body. Sending an encoded empty array would be sending one.
        $sent = $bodies[1] ?? null;
        if ($action === ServiceAction::Rebuild) {
            self::assertSame('{"clearCache":"do_not_clear"}', $sent);
        } else {
            self::assertNull($sent);
        }
    }

    public function testRateLimitingIsReportedAsSomethingToWaitOutRatherThanAFailure(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/services?')) {
                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse('{"message":"rate limit exceeded"}', ['http_code' => 429]);
        });

        $this->expectException(RenderUnavailable::class);
        $this->expectExceptionMessage('Wait a minute and try again.');

        $this->control($client)->apply($this->project(), ServiceAction::Stop);
    }

    public function testAnUnknownActionNamesTheOnesThatExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use rebuild, stop or start.');

        ServiceAction::fromString('delete');
    }

    private function host(string $suspended = 'not_suspended', string $deployStatus = 'live'): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($suspended, $deployStatus): MockResponse {
            if (str_contains($url, '/deploys')) {
                return new MockResponse(json_encode([
                    [
                        'deploy' => [
                            'id' => 'dep-1',
                            'status' => $deployStatus,
                            'finishedAt' => '2026-08-13T09:12:00Z',
                            'commit' => ['message' => "Add player presence\n\nWith a body nobody needs here."],
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/services?')) {
                return new MockResponse(json_encode([
                    ['service' => ['id' => 'srv-123', 'ownerId' => 'tea-9', 'name' => 'loop9-backend']],
                ]));
            }

            return new MockResponse(json_encode([
                'id' => 'srv-123',
                'ownerId' => 'tea-9',
                'name' => 'loop9-backend',
                'suspended' => $suspended,
            ]));
        });
    }

    private function control(MockHttpClient $client): RenderServiceControl
    {
        $api = new RenderApi($client, 'rnd_key');

        return new RenderServiceControl($api, new RenderServiceDirectory($api, new ArrayAdapter()));
    }

    private function project(): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('test-ingest-token-ok'),
        );
    }
}
