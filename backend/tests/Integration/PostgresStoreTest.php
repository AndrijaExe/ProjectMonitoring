<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Persistence\Postgres\PdoHealthSnapshotStore;
use App\Adapter\Persistence\Postgres\PdoMetricStore;
use App\Adapter\Persistence\Postgres\PdoProjectRepository;
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
