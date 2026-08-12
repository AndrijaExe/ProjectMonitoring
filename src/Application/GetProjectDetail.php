<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DTO\ProjectDetail;
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

    public function execute(string $gameId): ProjectDetail
    {
        $project = $this->overview->requireProject($gameId);
        $card = $this->overview->cardFor($project);

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

        return new ProjectDetail($card, $history, $recentMetrics);
    }
}
