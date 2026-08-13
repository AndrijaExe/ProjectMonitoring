<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CollectGameMetrics;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\FakeGameMetricSource;
use App\Tests\Support\InMemoryMetricStore;
use PHPUnit\Framework\TestCase;
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

    private function collector(FakeGameMetricSource $source, InMemoryMetricStore $store): CollectGameMetrics
    {
        return new CollectGameMetrics($source, $store, new NullLogger());
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
