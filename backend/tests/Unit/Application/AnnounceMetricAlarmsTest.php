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

    public function testNobodyOnlineStaysRaisedUntilSomebodyIsBack(): void
    {
        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer(new InMemoryMetricStore(), $state, $channel);
        $nobody = new GameReading([], ['players.online' => 0.0], 'redis');

        $announce->forReading($this->project(), $nobody, ['players.online' => 3.0], $this->now());
        // The poll after the fall reads zero on both sides. That is the same empty game, not a
        // recovery, and it used to be mailed as "players are online again".
        $announce->forReading($this->project(), $nobody, ['players.online' => 0.0], $this->now());
        $announce->forReading($this->project(), $nobody, ['players.online' => 0.0], $this->now());

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('nobody is online', $channel->sent[0]->subject());
        self::assertSame(['players.gone'], array_keys($state->raised(GameId::fromString('loop9'))));

        // A reading without the gauge says nothing about players, so it does not clear it either.
        $announce->forReading($this->project(), $this->reading(), ['players.online' => 0.0], $this->now());
        self::assertCount(1, $channel->sent);

        $announce->forReading(
            $this->project(),
            new GameReading([], ['players.online' => 2.0], 'redis'),
            ['players.online' => 0.0],
            $this->now(),
        );

        self::assertCount(2, $channel->sent);
        self::assertStringContainsString('recovered', $channel->sent[1]->subject());
        self::assertStringContainsString('players are online again', $channel->sent[1]->subject());
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testThreeUnreadableScrapesInARowRaiseOneAlarmAndAReadingClearsIt(): void
    {
        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer(new InMemoryMetricStore(), $state, $channel);
        $project = $this->project();

        $announce->forUnreadable($project, 'The game answered 401.', $this->now()->modify('-60 minutes'));
        $announce->forUnreadable($project, 'The game answered 401.', $this->now()->modify('-30 minutes'));

        // Two misses are a cold start and a bad minute. Nothing to say yet.
        self::assertSame([], $channel->sent);

        $announce->forUnreadable($project, 'The game answered 401.', $this->now());

        self::assertCount(1, $channel->sent);
        $mail = $channel->sent[0];
        self::assertStringContainsString("the game's counters cannot be read", $mail->subject());
        self::assertStringContainsString('The last 3 readings of its counters failed', $mail->body());
        // The time of the first miss, so the reader knows how long the monitor has been blind.
        self::assertStringContainsString('Thu 13 Aug, 11:00 UTC', $mail->body());
        self::assertStringContainsString('The game answered 401.', $mail->body());
        self::assertStringContainsString('METRICS_TOKENS', $mail->body());
        // The count is bookkeeping and goes once the alarm itself is raised.
        self::assertSame(['metrics.unreadable'], array_keys($state->raised(GameId::fromString('loop9'))));

        $announce->forUnreadable($project, 'The game answered 401.', $this->now()->modify('+30 minutes'));
        self::assertCount(1, $channel->sent);

        $announce->forReading($project, $this->reading(), [], $this->now()->modify('+60 minutes'));

        self::assertCount(2, $channel->sent);
        self::assertStringContainsString('recovered', $channel->sent[1]->subject());
        self::assertStringContainsString("the game's counters are being read again", $channel->sent[1]->subject());
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testAGoodReadingBetweenMissesStartsTheCountAgainWithoutAMail(): void
    {
        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer(new InMemoryMetricStore(), $state, $channel);
        $project = $this->project();

        $announce->forUnreadable($project, 'timeout', $this->now()->modify('-90 minutes'));
        $announce->forUnreadable($project, 'timeout', $this->now()->modify('-60 minutes'));
        $announce->forReading($project, $this->reading(), [], $this->now()->modify('-30 minutes'));

        // Closing the count is not a recovery: nothing was ever announced.
        self::assertSame([], $channel->sent);
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));

        $announce->forUnreadable($project, 'timeout', $this->now());
        self::assertSame([], $channel->sent);
    }

    public function testThreeQuickManualPollsDuringARedeployAreNotAnAlarm(): void
    {
        $channel = new FakeAlertChannel();
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer(new InMemoryMetricStore(), $state, $channel);
        $project = $this->project();

        // "Update status" tapped three times in two minutes while the game restarts.
        $announce->forUnreadable($project, 'timeout', $this->now());
        $announce->forUnreadable($project, 'timeout', $this->now()->modify('+1 minute'));
        $announce->forUnreadable($project, 'timeout', $this->now()->modify('+2 minutes'));
        self::assertSame([], $channel->sent);

        // Still unreadable an hour after the first miss: now it is worth a mail.
        $announce->forUnreadable($project, 'timeout', $this->now()->modify('+60 minutes'));
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('The last 4 readings of its counters failed', $channel->sent[0]->body());
    }

    public function testAnUnreadableAlarmThatCouldNotBeMailedIsTriedAgainWithTheTrueCount(): void
    {
        $state = new InMemoryAlarmStateStore();
        $metrics = new InMemoryMetricStore();
        $failing = new FakeAlertChannel();
        $failing->willFail('Resend answered 429');
        $down = $this->announcer($metrics, $state, $failing);
        $project = $this->project();

        $down->forUnreadable($project, 'timeout', $this->now()->modify('-60 minutes'));
        $down->forUnreadable($project, 'timeout', $this->now()->modify('-30 minutes'));
        $down->forUnreadable($project, 'timeout', $this->now());

        self::assertNotContains('metrics.unreadable', array_keys($state->raised(GameId::fromString('loop9'))));

        $channel = new FakeAlertChannel();
        $this->announcer($metrics, $state, $channel)
            ->forUnreadable($project, 'timeout', $this->now()->modify('+30 minutes'));

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('The last 4 readings of its counters failed', $channel->sent[0]->body());
        self::assertSame(['metrics.unreadable'], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testWithNoChannelConfiguredUnreadableScrapesAreNotCounted(): void
    {
        $state = new InMemoryAlarmStateStore();
        $announce = $this->announcer(new InMemoryMetricStore(), $state, new FakeAlertChannel(configured: false));

        $announce->forUnreadable($this->project(), 'timeout', $this->now());

        self::assertSame([], $state->raised(GameId::fromString('loop9')));
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

    public function testASteamOutageAtSignInSaysWhoIsAffected(): void
    {
        $metrics = new InMemoryMetricStore();
        $this->record($metrics, 'auth.unavailable', 0.0, '-90 minutes');
        $this->record($metrics, 'auth.unavailable', 2.0, '-1 minute');

        $channel = new FakeAlertChannel();
        $this->announcer($metrics, new InMemoryAlarmStateStore(), $channel, 'auth.unavailable=0')
            ->forReading($this->project(), $this->reading(), [], $this->now());

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('auth.unavailable is rising fast', $channel->sent[0]->subject());
        self::assertStringContainsString('STEAM_WEB_API_KEY', $channel->sent[0]->body());
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
