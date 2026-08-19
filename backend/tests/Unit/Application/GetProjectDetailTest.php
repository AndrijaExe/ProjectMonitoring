<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\GetMonitoringOverview;
use App\Application\GetProjectDetail;
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

final class GetProjectDetailTest extends TestCase
{
    public function testDailySpendIsTheGrowthOfEachUtcDay(): void
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));

        $metrics = new InMemoryMetricStore();
        $this->record($metrics, '2026-08-17T23:00:00+00:00', 100, 50, 20);
        $this->record($metrics, '2026-08-18T12:00:00+00:00', 150, 80, 50);
        $this->record($metrics, '2026-08-19T10:00:00+00:00', 200, 140, 110);

        $detail = (new GetProjectDetail(
            new GetMonitoringOverview(
                $projects,
                new InMemoryHealthSnapshotStore(),
                $metrics,
                new InMemoryAlarmStateStore(),
            ),
            new InMemoryHealthSnapshotStore(),
            $metrics,
        ))->execute('loop9', new \DateTimeImmutable('2026-08-19T15:00:00+00:00'));

        $usage = $detail->toArray()['usage'];
        $byDate = [];
        foreach ($usage['days'] as $day) {
            $byDate[$day['date']] = $day;
        }

        self::assertSame(14, $usage['window_days']);
        self::assertCount(14, $usage['days']);
        self::assertSame('2026-08-06', $usage['days'][0]['date']);
        self::assertSame('2026-08-19', $usage['days'][13]['date']);

        // 17 Aug is the baseline, not a billed day: growth that day is unknown.
        self::assertSame(0, $byDate['2026-08-17']['cost_micros']);
        self::assertSame(50, $byDate['2026-08-18']['tokens_in']);
        self::assertSame(30, $byDate['2026-08-18']['cost_micros']);
        self::assertSame(50, $byDate['2026-08-19']['tokens_in']);
        self::assertSame(60, $byDate['2026-08-19']['cost_micros']);
        self::assertSame('openai', $byDate['2026-08-19']['providers'][0]['id']);
        self::assertSame(60, $byDate['2026-08-19']['providers'][0]['cost_micros']);

        self::assertSame(50, $usage['last_24h']['tokens_in']);
        self::assertSame(60, $usage['last_24h']['cost_micros']);
    }

    private function record(
        InMemoryMetricStore $metrics,
        string $at,
        int $tokensIn,
        int $tokensOut,
        int $costMicros,
    ): void {
        $when = new \DateTimeImmutable($at);
        $game = GameId::fromString('loop9');
        $kind = ['kind' => 'counter'];

        $metrics->recordBatch(new MetricBatch([
            new MetricSample($game, 'ai.tokens.in', (float) $tokensIn, $kind, $when),
            new MetricSample($game, 'ai.tokens.out', (float) $tokensOut, $kind, $when),
            new MetricSample($game, 'ai.cost.micros', (float) $costMicros, $kind, $when),
            new MetricSample($game, 'ai.tokens.in.openai', (float) $tokensIn, $kind, $when),
            new MetricSample($game, 'ai.tokens.out.openai', (float) $tokensOut, $kind, $when),
            new MetricSample($game, 'ai.cost.micros.openai', (float) $costMicros, $kind, $when),
        ]));
    }
}
