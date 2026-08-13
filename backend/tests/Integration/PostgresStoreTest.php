<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Persistence\Postgres\PdoAlarmStateStore;
use App\Adapter\Persistence\Postgres\PdoHealthSnapshotStore;
use App\Adapter\Persistence\Postgres\PdoMetricStore;
use App\Adapter\Persistence\Postgres\PdoProjectRepository;
use App\Adapter\Persistence\Postgres\PostgresConnection;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\Project;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class PostgresStoreTest extends TestCase
{
    private GameId $loop9;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->loop9 = GameId::fromString('loop9');
    }

    public function testSavingAProjectTwiceUpdatesItInsteadOfDuplicating(): void
    {
        $repository = new PdoProjectRepository(TestDatabase::connection());

        $repository->save(new Project(
            $this->loop9,
            'Loop 9 renamed',
            'https://example.test/healthz',
            'https://example.test/readyz',
            IngestToken::hash('another-ingest-token'),
        ));

        $all = $repository->all();
        self::assertCount(1, $all);
        self::assertSame('Loop 9 renamed', $all[0]->displayName);
        self::assertSame('Loop 9 renamed', $repository->findByGameId($this->loop9)?->displayName);
    }

    public function testProjectsComeBackOrderedByName(): void
    {
        $repository = new PdoProjectRepository(TestDatabase::connection());
        $repository->save(new Project(
            GameId::fromString('another-game'),
            'Another Game',
            'https://example.test/healthz',
            'https://example.test/readyz',
            IngestToken::hash('another-ingest-token'),
        ));

        $names = array_map(static fn (Project $p): string => $p->displayName, $repository->all());

        self::assertSame(['Another Game', 'Loop 9'], $names);
    }

    public function testLatestSnapshotWinsOnTimeNotInsertOrder(): void
    {
        $store = new PdoHealthSnapshotStore(TestDatabase::connection());
        $newest = new \DateTimeImmutable('2026-08-12T12:00:00+00:00');
        $older = new \DateTimeImmutable('2026-08-12T11:00:00+00:00');

        $store->record($this->snapshot(HealthStatus::Ok, $newest, 120));
        $store->record($this->snapshot(HealthStatus::Timeout, $older, 20000, 'No response within 1.0s over 2 attempts.'));

        $latest = $store->latest($this->loop9, HealthEndpoint::Health);

        self::assertSame(HealthStatus::Ok, $latest?->status);
        self::assertSame(120, $latest?->latencyMs);
    }

    public function testRecentSnapshotsAreNewestFirstAndKeepTheReason(): void
    {
        $store = new PdoHealthSnapshotStore(TestDatabase::connection());
        $store->record($this->snapshot(HealthStatus::Ok, new \DateTimeImmutable('2026-08-12T10:00:00+00:00'), 100));
        $store->record($this->snapshot(HealthStatus::Down, new \DateTimeImmutable('2026-08-12T11:00:00+00:00'), 15, 'Connection refused.'));

        $recent = $store->recent($this->loop9, 10);

        self::assertCount(2, $recent);
        self::assertSame(HealthStatus::Down, $recent[0]->status);
        self::assertSame('Connection refused.', $recent[0]->error);
        self::assertNull($recent[1]->error);
    }

    public function testMetricTotalsAggregateInsideTheWindowOnly(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.requests', 3.0, ['provider' => 'openai'], $now),
            new MetricSample($this->loop9, 'chat.requests', 2.0, [], $now->modify('-1 hour')),
            new MetricSample($this->loop9, 'chat.errors', 1.0, [], $now),
            new MetricSample($this->loop9, 'chat.requests', 99.0, [], $now->modify('-3 days')),
        ]));

        $since = $now->modify('-24 hours');

        self::assertSame(3, $store->countSince($this->loop9, $since));
        self::assertSame(
            ['chat.errors' => 1.0, 'chat.requests' => 5.0],
            $store->totalsSince($this->loop9, $since),
        );
    }

    public function testCumulativeCountersReportGrowthWhilePushedSamplesStillSum(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $counter = ['kind' => 'counter'];

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.messages', 1000.0, $counter, $now->modify('-3 hours')),
            new MetricSample($this->loop9, 'chat.messages', 1042.0, $counter, $now->modify('-1 hour')),
            new MetricSample($this->loop9, 'chat.messages', 1050.0, $counter, $now),
            new MetricSample($this->loop9, 'chat.errors', 2.0, [], $now),
            new MetricSample($this->loop9, 'chat.errors', 3.0, [], $now->modify('-1 hour')),
        ]));

        self::assertSame(
            // 50 of growth, not 3092 of stacked lifetime readings.
            ['chat.errors' => 5.0, 'chat.messages' => 50.0],
            $store->totalsSince($this->loop9, $now->modify('-24 hours')),
        );
    }

    public function testGrowthIsMeasuredFromTheLastReadingBeforeTheWindow(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $counter = ['kind' => 'counter'];

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.messages', 100.0, $counter, $now->modify('-30 hours')),
            new MetricSample($this->loop9, 'chat.messages', 180.0, $counter, $now->modify('-20 hours')),
            new MetricSample($this->loop9, 'chat.messages', 200.0, $counter, $now),
        ]));

        // Measuring from the first reading inside the window would lose the 80 that happened
        // between the reading before it and the one after.
        self::assertSame(
            ['chat.messages' => 100.0],
            $store->totalsSince($this->loop9, $now->modify('-24 hours')),
        );
    }

    public function testACounterReadingLowerThanBeforeCountsFromTheReset(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $counter = ['kind' => 'counter'];

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.messages', 900.0, $counter, $now->modify('-2 hours')),
            new MetricSample($this->loop9, 'chat.messages', 7.0, $counter, $now),
        ]));

        self::assertSame(
            ['chat.messages' => 7.0],
            $store->totalsSince($this->loop9, $now->modify('-24 hours')),
        );
    }

    public function testAClosedWindowSeesOnlyItsOwnPeriod(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $counter = ['kind' => 'counter'];

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.messages', 100.0, $counter, $now->modify('-40 hours')),
            new MetricSample($this->loop9, 'chat.messages', 150.0, $counter, $now->modify('-30 hours')),
            new MetricSample($this->loop9, 'chat.messages', 400.0, $counter, $now->modify('-1 hour')),
        ]));

        // Yesterday saw 50 and stopped. Asking about yesterday must not be answered with the
        // 250 that arrived today, which is the comparison the quiet alarm rests on.
        self::assertSame(
            ['chat.messages' => 50.0],
            $store->totalsBetween($this->loop9, $now->modify('-48 hours'), $now->modify('-24 hours')),
        );
        self::assertSame(
            ['chat.messages' => 250.0],
            $store->totalsBetween($this->loop9, $now->modify('-24 hours'), null),
        );
    }

    public function testAlarmsAreRememberedOncePerKeyAndCanBeClosed(): void
    {
        $alarms = new PdoAlarmStateStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();

        $alarms->open($this->loop9, 'rate:ai.fallback', $now);
        // A second poll finding the same condition must not multiply the row, or the operator
        // would be mailed again the moment it clears.
        $alarms->open($this->loop9, 'rate:ai.fallback', $now->modify('+1 hour'));
        $alarms->open($this->loop9, 'storage.memory', $now);

        self::assertSame(['rate:ai.fallback', 'storage.memory'], $alarms->openKeys($this->loop9));

        $alarms->close($this->loop9, 'rate:ai.fallback');
        self::assertSame(['storage.memory'], $alarms->openKeys($this->loop9));
    }

    public function testAGaugeReportsItsNewestValueAndStaysOutOfTheTotals(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $gauge = ['kind' => 'gauge'];

        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'players.online', 12.0, $gauge, $now->modify('-2 hours')),
            new MetricSample($this->loop9, 'players.online', 5.0, $gauge, $now),
            new MetricSample($this->loop9, 'chat.messages', 30.0, ['kind' => 'counter'], $now),
        ]));

        $since = $now->modify('-24 hours');

        // Seventeen players online never happened; five is the answer, and the only one.
        self::assertSame(['players.online' => 5.0], $store->latestGauges($this->loop9, $since));
        self::assertArrayNotHasKey('players.online', $store->totalsSince($this->loop9, $since));
    }

    public function testRecentMetricsKeepTagsAndOrder(): void
    {
        $store = new PdoMetricStore(TestDatabase::connection());
        $now = new \DateTimeImmutable();
        $store->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.requests', 1.0, ['provider' => 'openai'], $now->modify('-5 minutes')),
            new MetricSample($this->loop9, 'chat.latency', 250.5, [], $now),
        ]));

        $recent = $store->recent($this->loop9, 10);

        self::assertSame('chat.latency', $recent[0]->name);
        self::assertSame(250.5, $recent[0]->value);
        self::assertSame(['provider' => 'openai'], $recent[1]->tags);
    }

    public function testTheStoresSurviveAPoolerThatForcesEmulatedPreparedStatements(): void
    {
        // Supabase's transaction pooler makes PDO emulate prepares, which changes how floats,
        // integers and JSONB tags are bound. Same database, only the binding mode differs.
        $pooled = new PostgresConnection(TestDatabase::url().'?pgbouncer=true');
        self::assertTrue($pooled->emulatesPreparedStatements());

        $now = new \DateTimeImmutable();
        $metrics = new PdoMetricStore($pooled);
        $metrics->recordBatch(new MetricBatch([
            new MetricSample($this->loop9, 'chat.requests', 2.5, ['provider' => 'openai'], $now),
        ]));

        $snapshots = new PdoHealthSnapshotStore($pooled);
        $snapshots->record($this->snapshot(HealthStatus::Ok, $now, 120));

        $since = $now->modify('-1 hour');
        self::assertSame(1, $metrics->countSince($this->loop9, $since));
        self::assertSame(['chat.requests' => 2.5], $metrics->totalsSince($this->loop9, $since));
        self::assertSame(['provider' => 'openai'], $metrics->recent($this->loop9, 5)[0]->tags);
        self::assertSame(120, $snapshots->latest($this->loop9, HealthEndpoint::Health)?->latencyMs);
    }

    private function snapshot(
        HealthStatus $status,
        \DateTimeImmutable $checkedAt,
        int $latencyMs,
        ?string $error = null,
    ): HealthSnapshot {
        return new HealthSnapshot(
            $this->loop9,
            HealthEndpoint::Health,
            $status,
            $status === HealthStatus::Ok ? 200 : 0,
            $latencyMs,
            $checkedAt,
            $error,
        );
    }
}
