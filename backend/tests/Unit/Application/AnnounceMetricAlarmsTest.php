<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AnnounceMetricAlarms;
use App\Model\GameId;
use App\Model\GameReading;
use App\Model\IngestToken;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\Project;
use App\Tests\Support\CollectingLogger;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\InMemoryAlarmStateStore;
use App\Tests\Support\InMemoryMetricStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AnnounceMetricAlarmsTest extends TestCase
{
    private const NOW = '2026-08-13T12:00:00+00:00';

    public function testACounterPastItsCeilingIsMailedOnceRatherThanEveryHour(): void
    {
        $metrics = new InMemoryMetricStore();
        $this->record($metrics, 'ai.fallback', 100.0, '-90 minutes');
        $this->record($metrics, 'ai.fallback', 140.0, '-1 minute');

        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer($metrics, $state, $channel, 'ai.fallback=10');

        $announce->forReading($this->project(), $this->reading(), [], $this->now());
        $announce->forReading($this->project(), $this->reading(), [], $this->now());

        // The condition is still true on the next poll, and an operator who gets the same mail
        // every hour stops reading them.
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('ai.fallback is rising fast', $channel->sent[0]->subject());
        self::assertStringContainsString('grew by 40 in the last hour', $channel->sent[0]->body());
        self::assertSame(['rate:ai.fallback'], $state->openKeys(GameId::fromString('loop9')));
    }

    public function testACounterBackUnderItsCeilingReportsTheRecoveryAndCanFireAgain(): void
    {
        $metrics = new InMemoryMetricStore();
        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $state->open(GameId::fromString('loop9'), 'rate:ai.fallback', $this->now());

        $this->announcer($metrics, $state, $channel, 'ai.fallback=10')
            ->forReading($this->project(), $this->reading(), [], $this->now());

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('recovered', $channel->sent[0]->subject());
        // Closed, so the next spike is news again rather than a duplicate.
        self::assertSame([], $state->openKeys(GameId::fromString('loop9')));
    }

    public function testAGameThatHasNeverCountedAnythingIsNotCalledQuiet(): void
    {
        $channel = new FakeAlertChannel();

        $this->announcer(new InMemoryMetricStore(), new InMemoryAlarmStateStore(), $channel)
            ->forReading($this->project(), $this->reading(), [], $this->now());

        // An unreleased game has no players by definition. Alerting on that teaches the
        // operator that mail from the monitor is noise.
        self::assertSame([], $channel->sent);
    }

    public function testACounterThatWasMovingAndStoppedRaisesTheQuietAlarm(): void
    {
        $metrics = new InMemoryMetricStore();
        $this->record($metrics, 'chat.messages', 100.0, '-40 hours');
        $this->record($metrics, 'chat.messages', 150.0, '-30 hours');
        $this->record($metrics, 'chat.messages', 150.0, '-2 hours');

        $channel = new FakeAlertChannel();
        $this->announcer($metrics, new InMemoryAlarmStateStore(), $channel)
            ->forReading($this->project(), $this->reading(), [], $this->now());

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('nothing has been counted for a day', $channel->sent[0]->subject());
    }

    public function testPlayersFallingToZeroIsNewsButStayingAtZeroIsNot(): void
    {
        $channel = new FakeAlertChannel();
        $announce = $this->announcer(new InMemoryMetricStore(), new InMemoryAlarmStateStore(), $channel);
        $reading = new GameReading([], ['players.online' => 0.0], 'redis');

        $announce->forReading($this->project(), $reading, ['players.online' => 3.0], $this->now());
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('nobody is online', $channel->sent[0]->subject());

        $quiet = new FakeAlertChannel();
        $this->announcer(new InMemoryMetricStore(), new InMemoryAlarmStateStore(), $quiet)
            ->forReading($this->project(), $reading, ['players.online' => 0.0], $this->now());

        // Zero to zero is a Tuesday, not an event.
        self::assertSame([], $quiet->sent);
    }

    public function testCountersKeptInMemoryAreWorthAMailEvenWithNothingCounted(): void
    {
        $channel = new FakeAlertChannel();

        $this->announcer(new InMemoryMetricStore(), new InMemoryAlarmStateStore(), $channel)
            ->forReading($this->project(), new GameReading([], [], 'memory'), [], $this->now());

        // This is the shape of a board that will read zero forever while looking like a quiet
        // day, so it has to be said before anyone trusts the numbers.
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('counters are being kept in memory', $channel->sent[0]->subject());
    }

    public function testAnAlarmThatCouldNotBeMailedIsNotRecordedAsRaised(): void
    {
        $channel = new FakeAlertChannel();
        $channel->willFail('Resend answered 429');
        $logger = new CollectingLogger();
        $state = new InMemoryAlarmStateStore();

        $announce = new AnnounceMetricAlarms(
            new InMemoryMetricStore(),
            $state,
            $channel,
            $logger,
            '',
        );
        $announce->forReading($this->project(), new GameReading([], [], 'memory'), [], $this->now());

        // Remembering an alarm nobody received would silence it for good, which is worse than
        // sending it twice.
        self::assertSame([], $state->openKeys(GameId::fromString('loop9')));
        self::assertContains('Could not send a metric alarm.', $logger->messages);
    }

    public function testWithNoChannelConfiguredNothingIsEvaluated(): void
    {
        $state = new InMemoryAlarmStateStore();

        $this->announcer(new InMemoryMetricStore(), $state, new FakeAlertChannel(configured: false))
            ->forReading($this->project(), new GameReading([], [], 'memory'), [], $this->now());

        self::assertSame([], $state->openKeys(GameId::fromString('loop9')));
    }

    private function announcer(
        InMemoryMetricStore $metrics,
        InMemoryAlarmStateStore $state,
        FakeAlertChannel $channel,
        string $rateLimits = '',
    ): AnnounceMetricAlarms {
        return new AnnounceMetricAlarms($metrics, $state, $channel, new NullLogger(), $rateLimits);
    }

    private function record(InMemoryMetricStore $metrics, string $name, float $value, string $ago): void
    {
        $metrics->recordBatch(new MetricBatch([new MetricSample(
            GameId::fromString('loop9'),
            $name,
            $value,
            ['kind' => 'counter'],
            $this->now()->modify($ago),
        )]));
    }

    private function reading(): GameReading
    {
        return new GameReading([], [], 'redis');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function project(): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
            'https://loop9-backend.onrender.com/metrics',
        );
    }
}
