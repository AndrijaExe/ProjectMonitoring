<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AnnounceMetricAlarms;
use App\Application\CollectGameMetrics;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\Project;
use App\Tests\Support\CollectingLogger;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\FakeGameMetricSource;
use App\Tests\Support\InMemoryAlarmStateStore;
use App\Tests\Support\InMemoryMetricStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CollectGameMetricsTest extends TestCase
{
    public function testAGameThatPublishesNothingIsNotAsked(): void
    {
        $source = new FakeGameMetricSource();
        $source->willFail('should never be called');

        $collected = $this->collector($source, new InMemoryMetricStore())
            ->forProject($this->project(metricsUrl: null), new \DateTimeImmutable());

        self::assertSame(0, $collected);
    }

    public function testAnUnreadableGameIsNotTreatedAsAFailure(): void
    {
        $source = new FakeGameMetricSource();
        $source->willFail('connection refused');

        // The probe already reported whether the game is up. A refused counter read is a
        // gap in the numbers, not an incident, and must not break the poll for everyone else.
        $collected = $this->collector($source, new InMemoryMetricStore())
            ->forProject($this->project(), new \DateTimeImmutable());

        self::assertSame(0, $collected);
    }

    public function testTheWindowTotalIsGrowthRatherThanTheSumOfReadings(): void
    {
        $store = new InMemoryMetricStore();
        $source = new FakeGameMetricSource();
        $collector = $this->collector($source, $store);
        $project = $this->project();

        $source->willReturn(['chat.messages' => 1000.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));

        $source->willReturn(['chat.messages' => 1030.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T10:00:00+00:00'));

        // Summing the two readings would claim 2030 messages in an hour that saw 30.
        $totals = $store->totalsSince(GameId::fromString('loop9'), new \DateTimeImmutable('2026-08-13T00:00:00+00:00'));
        self::assertSame(30.0, $totals['chat.messages']);
    }

    public function testACounterThatWentBackwardsIsReadAsAReset(): void
    {
        $store = new InMemoryMetricStore();
        $source = new FakeGameMetricSource();
        $collector = $this->collector($source, $store);
        $project = $this->project();

        $source->willReturn(['chat.messages' => 900.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));

        // Redis lost the key: the count restarts rather than the game un-happening.
        $source->willReturn(['chat.messages' => 12.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T10:00:00+00:00'));

        $totals = $store->totalsSince(GameId::fromString('loop9'), new \DateTimeImmutable('2026-08-13T00:00:00+00:00'));
        self::assertSame(12.0, $totals['chat.messages']);
    }

    public function testAGaugeIsReadAsItsLatestValueAndNeverSummed(): void
    {
        $store = new InMemoryMetricStore();
        $source = new FakeGameMetricSource();
        $collector = $this->collector($source, $store);
        $project = $this->project();

        $source->willReturn([], ['players.online' => 4.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));

        $source->willReturn([], ['players.online' => 7.0]);
        $collector->forProject($project, new \DateTimeImmutable('2026-08-13T10:00:00+00:00'));

        $since = new \DateTimeImmutable('2026-08-13T00:00:00+00:00');
        $gameId = GameId::fromString('loop9');

        // Eleven players online would be a lie told by addition.
        self::assertSame(['players.online' => 7.0], $store->latestGauges($gameId, $since));
        // And a level has no place among the day's totals at all.
        self::assertSame([], $store->totalsSince($gameId, $since));
    }

    public function testAnAnswerWithNothingInItIsSaidOutLoud(): void
    {
        $logger = new CollectingLogger();
        $source = new FakeGameMetricSource();
        $source->willReturn([], []);

        $collected = $this->collector($source, new InMemoryMetricStore(), $logger)
            ->forProject($this->project(), new \DateTimeImmutable());

        // Otherwise a game counting nothing looks exactly like a reading that never happened.
        self::assertSame(0, $collected);
        self::assertContains('Game published no counters yet.', $logger->messages);
    }

    public function testAReadingLargerThanABatchIsStoredWholeIncludingTheEndOfTheAlphabet(): void
    {
        $logger = new CollectingLogger();
        $store = new InMemoryMetricStore();
        $source = new FakeGameMetricSource();
        $source->willReturn($this->manyCounters(['voice.cost.micros' => 1_500_000.0]), ['players.online' => 2.0]);

        $collected = $this->collector($source, $store, $logger)
            ->forProject($this->project(), new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));

        $total = MetricBatch::MAX_SAMPLES + 13 + 1;
        self::assertSame($total, $collected);

        $names = array_map(
            static fn (MetricSample $sample): string => $sample->name,
            $store->recent(GameId::fromString('loop9'), 500),
        );
        self::assertCount($total, $names);
        // The game sorts by name, so these are the series a cut at the cap used to lose.
        self::assertContains('voice.cost.micros', $names);
        self::assertContains('voice.denied.voice_global', $names);
        self::assertContains('safety.unavailable', $names);
        self::assertContains('players.online', $names);
        self::assertContains('Game reading stored in several batches.', $logger->messages);
    }

    public function testASmallReadingIsOneBatchAndSaysNothingAboutIt(): void
    {
        $logger = new CollectingLogger();
        $source = new FakeGameMetricSource();
        $source->willReturn(['chat.messages' => 3.0]);

        $this->collector($source, new InMemoryMetricStore(), $logger)
            ->forProject($this->project(), new \DateTimeImmutable());

        self::assertNotContains('Game reading stored in several batches.', $logger->messages);
    }

    public function testVoiceSpendPastTheCapIsJudgedAndMailed(): void
    {
        $store = new InMemoryMetricStore();
        $source = new FakeGameMetricSource();
        $channel = new FakeAlertChannel();
        $alarms = new AnnounceMetricAlarms(
            $store,
            new InMemoryAlarmStateStore(),
            $channel,
            new NullLogger(),
            '',
            'voice.cost.micros=5000000',
        );
        $collector = new CollectGameMetrics($source, $store, new NullLogger(), $alarms);

        $source->willReturn($this->manyCounters(['voice.cost.micros' => 0.0]));
        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));

        $source->willReturn($this->manyCounters(['voice.cost.micros' => 6_000_000.0]));
        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T10:00:00+00:00'));

        // Before the whole reading was kept, this counter was never stored and the ceiling
        // could not be crossed however much voice was spent.
        self::assertCount(1, $channel->sent);
        self::assertStringContainsString('voice.cost.micros is over its daily ceiling', $channel->sent[0]->subject());
    }

    public function testThreeFailedReadsInARowRaiseTheUnreadableAlarm(): void
    {
        $store = new InMemoryMetricStore();
        $state = new InMemoryAlarmStateStore();
        $channel = new FakeAlertChannel();
        $source = new FakeGameMetricSource();
        $collector = new CollectGameMetrics(
            $source,
            $store,
            new NullLogger(),
            new AnnounceMetricAlarms($store, $state, $channel, new NullLogger()),
        );

        // What a wrong METRICS_TOKEN looks like from here: the game is up and refuses the read.
        $source->willFail('The game answered 404.');
        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T09:00:00+00:00'));
        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T09:30:00+00:00'));
        self::assertSame([], $channel->sent);

        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T10:00:00+00:00'));

        self::assertCount(1, $channel->sent);
        self::assertStringContainsString("the game's counters cannot be read", $channel->sent[0]->subject());
        self::assertSame(['metrics.unreadable'], array_keys($state->raised(GameId::fromString('loop9'))));

        $source->willReturn(['chat.messages' => 3.0]);
        $collector->forProject($this->project(), new \DateTimeImmutable('2026-08-13T10:30:00+00:00'));

        self::assertCount(2, $channel->sent);
        self::assertStringContainsString("the game's counters are being read again", $channel->sent[1]->subject());
        self::assertSame([], array_keys($state->raised(GameId::fromString('loop9'))));
    }

    public function testCountingInMemoryIsReportedAsAProblemWithTheNumbers(): void
    {
        $logger = new CollectingLogger();
        $source = new FakeGameMetricSource();
        $source->willReturn(['chat.messages' => 3.0], [], 'memory');

        $this->collector($source, new InMemoryMetricStore(), $logger)
            ->forProject($this->project(), new \DateTimeImmutable());

        self::assertContains(
            'Game counts in memory, so its numbers reset on every restart.',
            $logger->messages,
        );
    }

    /**
     * A reading the shape of Loop 9's: more series than one batch holds, sorted by name the way
     * the game publishes them, with the spend and safety counters near the end.
     *
     * @param array<string, float> $overrides
     *
     * @return array<string, float>
     */
    private function manyCounters(array $overrides = []): array
    {
        $counters = [];
        for ($i = 0; $i < MetricBatch::MAX_SAMPLES; ++$i) {
            $counters[sprintf('advice.gate.c%02d', $i)] = (float) $i;
        }

        foreach (['safety.blocked', 'safety.unavailable', 'voice.cost.micros', 'voice.denied.voice_global',
            'voice.denied.voice_player_daily', 'voice.requests', 'voice.unavailable', 'run.ended',
            'chat.messages', 'auth.issued', 'auth.rejected', 'auth.unavailable', 'ai.cost.micros'] as $name) {
            $counters[$name] = 0.0;
        }

        $counters = [...$counters, ...$overrides];
        ksort($counters);

        return $counters;
    }

    private function collector(
        FakeGameMetricSource $source,
        InMemoryMetricStore $store,
        ?LoggerInterface $logger = null,
    ): CollectGameMetrics {
        $logger ??= new NullLogger();

        // Alarms are exercised in their own test; here the channel is off, which is also what a
        // deployment without RESEND_API_KEY looks like.
        $alarms = new AnnounceMetricAlarms(
            $store,
            new InMemoryAlarmStateStore(),
            new FakeAlertChannel(configured: false),
            $logger,
        );

        return new CollectGameMetrics($source, $store, $logger, $alarms);
    }

    private function project(?string $metricsUrl = 'https://loop9-backend.onrender.com/metrics'): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
            $metricsUrl,
        );
    }
}
