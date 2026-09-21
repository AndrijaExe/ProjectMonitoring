<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\GetMonitoringOverview;
use App\Application\GetProjectReport;
use App\Application\ReportPeriod;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\Project;
use App\Tests\Support\InMemoryAlarmStateStore;
use App\Tests\Support\InMemoryHealthSnapshotStore;
use App\Tests\Support\InMemoryMetricStore;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class GetProjectReportTest extends TestCase
{
    public function testWeeksStartOnMondayAndTheNewestIsStillFilling(): void
    {
        $metrics = new InMemoryMetricStore();
        // A counter read once a day; runs finished climbs 3, then 2, then 5 across a week boundary.
        $this->counter($metrics, '2026-09-11T10:00:00+00:00', 'run.ended', 30);   // Fri, W37
        $this->counter($metrics, '2026-09-13T10:00:00+00:00', 'run.ended', 33);   // Sun, W37
        $this->counter($metrics, '2026-09-14T10:00:00+00:00', 'run.ended', 35);   // Mon, W38
        $this->counter($metrics, '2026-09-20T10:00:00+00:00', 'run.ended', 40);   // Sun, W38
        $this->counter($metrics, '2026-09-21T09:00:00+00:00', 'run.ended', 41);   // Mon, W39 (today)

        $report = $this->report($metrics)
            ->execute('loop9', ReportPeriod::Week, new \DateTimeImmutable('2026-09-21T12:00:00+00:00'))
            ->toArray();

        self::assertSame('week', $report['period']);
        self::assertCount(12, $report['buckets']);

        $byKey = [];
        foreach ($report['buckets'] as $bucket) {
            $byKey[$bucket['key']] = $bucket;
        }

        self::assertSame('2026-W39', $report['buckets'][11]['key']);
        self::assertSame('2026-09-21T00:00:00+00:00', $byKey['2026-W39']['start']);
        self::assertFalse($byKey['2026-W39']['complete']);
        self::assertTrue($byKey['2026-W38']['complete']);
        self::assertSame('2026-09-14T00:00:00+00:00', $byKey['2026-W38']['start']);
        self::assertSame('2026-09-21T00:00:00+00:00', $byKey['2026-W38']['end']);

        // W38 grew from the Sunday reading before it (33) to its last reading (40).
        self::assertSame(7, $byKey['2026-W38']['totals']['run.ended']);
        // W39 so far: from 40 to 41.
        self::assertSame(1, $byKey['2026-W39']['totals']['run.ended']);
        // W37 has no reading before it, so its own first reading is the baseline.
        self::assertSame(3, $byKey['2026-W37']['totals']['run.ended']);
        // A week with no readings at all is present and empty, not missing.
        self::assertSame([], $byKey['2026-W30']['totals']);
    }

    public function testMonthsStartOnTheFirstAndCarrySpendByProvider(): void
    {
        $metrics = new InMemoryMetricStore();
        $this->spend($metrics, '2026-08-30T10:00:00+00:00', 100, 20);
        $this->spend($metrics, '2026-09-02T10:00:00+00:00', 160, 50);
        $this->spend($metrics, '2026-09-21T10:00:00+00:00', 400, 170);

        $report = $this->report($metrics)
            ->execute('loop9', ReportPeriod::Month, new \DateTimeImmutable('2026-09-21T12:00:00+00:00'))
            ->toArray();

        self::assertCount(12, $report['buckets']);
        self::assertSame('2025-10', $report['buckets'][0]['key']);
        self::assertSame('2026-09', $report['buckets'][11]['key']);
        self::assertSame('2026-09-01T00:00:00+00:00', $report['buckets'][11]['start']);
        self::assertSame('2026-10-01T00:00:00+00:00', $report['buckets'][11]['end']);

        $september = $report['buckets'][11];
        self::assertSame(300, $september['totals']['ai.tokens.in']);
        self::assertSame(150, $september['usage']['cost_micros']);
        self::assertSame('openai', $september['usage']['providers'][0]['id']);
        self::assertSame(150, $september['usage']['providers'][0]['cost_micros']);
    }

    public function testDaysAreThirtyAndSumPushedEvents(): void
    {
        $metrics = new InMemoryMetricStore();
        $game = GameId::fromString('loop9');
        // Pushed events carry no kind: the window total is their sum.
        $metrics->recordBatch(new MetricBatch([
            new MetricSample($game, 'crash', 1.0, [], new \DateTimeImmutable('2026-09-20T03:00:00+00:00')),
            new MetricSample($game, 'crash', 1.0, [], new \DateTimeImmutable('2026-09-20T23:59:00+00:00')),
            new MetricSample($game, 'crash', 1.0, [], new \DateTimeImmutable('2026-09-21T00:01:00+00:00')),
        ]));

        $report = $this->report($metrics)
            ->execute('loop9', ReportPeriod::Day, new \DateTimeImmutable('2026-09-21T12:00:00+00:00'))
            ->toArray();

        self::assertCount(30, $report['buckets']);
        self::assertSame('2026-08-23', $report['buckets'][0]['key']);
        self::assertSame('2026-09-20', $report['buckets'][28]['key']);
        self::assertSame(2, $report['buckets'][28]['totals']['crash']);
        self::assertSame(1, $report['buckets'][29]['totals']['crash']);
        self::assertFalse($report['buckets'][29]['complete']);
    }

    public function testPeriodParsingFallsBackToWeek(): void
    {
        self::assertSame(ReportPeriod::Week, ReportPeriod::fromString(null));
        self::assertSame(ReportPeriod::Week, ReportPeriod::fromString('quarter'));
        self::assertSame(ReportPeriod::Day, ReportPeriod::fromString(' DAY '));
        self::assertSame(ReportPeriod::Month, ReportPeriod::fromString('month'));
    }

    private function report(InMemoryMetricStore $metrics): GetProjectReport
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));

        return new GetProjectReport(
            new GetMonitoringOverview(
                $projects,
                new InMemoryHealthSnapshotStore(),
                $metrics,
                new InMemoryAlarmStateStore(),
            ),
            $metrics,
        );
    }

    private function counter(InMemoryMetricStore $metrics, string $at, string $name, int $value): void
    {
        $metrics->recordBatch(new MetricBatch([
            new MetricSample(
                GameId::fromString('loop9'),
                $name,
                (float) $value,
                ['kind' => 'counter'],
                new \DateTimeImmutable($at),
            ),
        ]));
    }

    private function spend(InMemoryMetricStore $metrics, string $at, int $tokensIn, int $costMicros): void
    {
        $when = new \DateTimeImmutable($at);
        $game = GameId::fromString('loop9');
        $kind = ['kind' => 'counter'];

        $metrics->recordBatch(new MetricBatch([
            new MetricSample($game, 'ai.tokens.in', (float) $tokensIn, $kind, $when),
            new MetricSample($game, 'ai.cost.micros', (float) $costMicros, $kind, $when),
            new MetricSample($game, 'ai.tokens.in.openai', (float) $tokensIn, $kind, $when),
            new MetricSample($game, 'ai.cost.micros.openai', (float) $costMicros, $kind, $when),
        ]));
    }
}
