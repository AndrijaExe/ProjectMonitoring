<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DTO\ProjectDetail;
use App\Model\GameId;
use App\Model\HealthSnapshotStore;
use App\Model\MetricSample;
use App\Model\MetricStore;

final class GetProjectDetail
{
    public function __construct(
        private readonly GetMonitoringOverview $overview,
        private readonly HealthSnapshotStore $healthSnapshots,
        private readonly MetricStore $metrics,
    ) {
    }

    public function execute(string $gameId, ?\DateTimeImmutable $now = null): ProjectDetail
    {
        $now ??= new \DateTimeImmutable('now');
        $project = $this->overview->requireProject($gameId);
        $card = $this->overview->cardFor($project, $now->modify('-24 hours'));

        $history = [];
        foreach ($this->healthSnapshots->recent($project->gameId, 40) as $snapshot) {
            $history[] = [
                'endpoint' => $snapshot->endpoint->value,
                'status' => $snapshot->status->value,
                'http_code' => $snapshot->httpCode,
                'latency_ms' => $snapshot->latencyMs,
                'checked_at' => $snapshot->checkedAt->format(\DateTimeInterface::ATOM),
                'error' => $snapshot->error,
            ];
        }

        $recentMetrics = array_map(
            static fn (MetricSample $sample): array => [
                'name' => $sample->name,
                'value' => $sample->value,
                'tags' => $sample->tags,
                'recorded_at' => $sample->recordedAt->format(\DateTimeInterface::ATOM),
            ],
            $this->metrics->recent($project->gameId, 50),
        );

        return new ProjectDetail($card, $history, $recentMetrics, $this->usage($project->gameId, $card->recentMetricTotals, $now));
    }

    /**
     * @param array<string, float> $last24h
     *
     * @return array<string, mixed>
     */
    private function usage(GameId $gameId, array $last24h, \DateTimeImmutable $now): array
    {
        $breakdown = new UsageBreakdown();
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $days = [];

        for ($day = $breakdown->windowStart($now); $day <= $today; $day = $day->modify('+1 day')) {
            $next = $day->modify('+1 day');
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'totals' => $this->metrics->totalsBetween($gameId, $day, $next <= $now ? $next : null),
            ];
        }

        return [
            'window_days' => UsageBreakdown::WINDOW_DAYS,
            'last_24h' => $breakdown->summarize($last24h),
            'days' => $breakdown->days($days),
        ];
    }
}
