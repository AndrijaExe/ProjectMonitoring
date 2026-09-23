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
        self::assertSame(['rate:ai.fallback'], array_keys($state->raised(GameId::fromString('loop9'))));
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
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
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
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
        self::assertContains('Could not send a metric alarm.', $logger->messages);
    }

    public function testWithNoChannelConfiguredNothingIsEvaluated(): void
    {
        $state = new InMemoryAlarmStateStore();

        $this->announcer(new InMemoryMetricStore(), $state, new FakeAlertChannel(configured: false))
            ->forReading($this->project(), new GameReading([], [], 'memory'), [], $this->now());

        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testTheGlobalChatQuotaAlarmNamesTheKnobToTurn(): void
    {
        $metrics = new InMemoryMetricStore();
        // A ceiling of zero: the first refused player in the hour is the alarm.
        $this->record($metrics, 'chat.denied.global', 0.0, '-90 minutes');
        $this->record($metrics, 'chat.denied.global', 1.0, '-1 minute');

        $channel = new FakeAlertChannel();
        $this->announcer($metrics, new InMemoryAlarmStateStore(), $channel, 'chat.denied.global=0,abuse.watch=0')
            ->forReading($this->project(), $this->reading(), [], $this->now());

        // Only the quota that was actually crossed rings; abuse.watch stayed at nothing.
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('chat.denied.global is rising fast', $channel->sent[0]->subject());
        // An operator reading this at launch needs the knob, not a search through the docs.
        self::assertStringContainsString('service is at capacity', $channel->sent[0]->body());
        self::assertStringContainsString('GAME_GLOBAL_DAILY_QUOTA', $channel->sent[0]->body());
    }

    public function testSpendIsJudgedOverTheDayNotTheHour(): void
    {
        $metrics = new InMemoryMetricStore();
        // $6 of voice across the day, never more than $1.50 in any hour.
        $this->record($metrics, 'voice.cost.micros', 0.0, '-23 hours');
        $this->record($metrics, 'voice.cost.micros', 1_500_000.0, '-18 hours');
        $this->record($metrics, 'voice.cost.micros', 3_000_000.0, '-12 hours');
        $this->record($metrics, 'voice.cost.micros', 4_500_000.0, '-6 hours');
        $this->record($metrics, 'voice.cost.micros', 6_000_000.0, '-1 minute');
        $this->record($metrics, 'ai.cost.micros', 0.0, '-23 hours');
        $this->record($metrics, 'ai.cost.micros', 400_000.0, '-1 minute');

        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = new AnnounceMetricAlarms(
            $metrics,
            $state,
            $channel,
            new NullLogger(),
            'voice.cost.micros=2000000',
            'voice.cost.micros=5000000,ai.cost.micros=3000000',
        );
        $announce->forReading($this->project(), $this->reading(), [], $this->now());

        // The hourly ceiling never tripped; the day did. AI spend stayed under its own.
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('voice.cost.micros is over its daily ceiling', $channel->sent[0]->subject());
        self::assertStringContainsString('grew by 6000000 in the last 24 hours', $channel->sent[0]->body());
        self::assertStringContainsString('5000000 is $5', $channel->sent[0]->body());
        self::assertStringContainsString('VOICE_ENABLED=false', $channel->sent[0]->body());
        self::assertSame(['day:voice.cost.micros'], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testTheVoiceKillSwitchNamesItsKnob(): void
    {
        $metrics = new InMemoryMetricStore();
        $this->record($metrics, 'voice.denied.voice_global', 0.0, '-90 minutes');
        $this->record($metrics, 'voice.denied.voice_global', 3.0, '-1 minute');

        $channel = new FakeAlertChannel();
        $this->announcer($metrics, new InMemoryAlarmStateStore(), $channel, 'voice.denied.voice_global=0')
            ->forReading($this->project(), $this->reading(), [], $this->now());

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('VOICE_GLOBAL_DAILY_QUOTA', $channel->sent[0]->body());
        self::assertStringContainsString('answered in text', $channel->sent[0]->body());
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
