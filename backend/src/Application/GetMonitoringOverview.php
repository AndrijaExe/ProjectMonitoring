<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DTO\MonitoringOverview;
use App\Application\DTO\ProjectCard;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;
use App\Model\MetricStore;
use App\Model\Project;
use App\Model\ProjectRepository;

final class GetMonitoringOverview
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly HealthSnapshotStore $healthSnapshots,
        private readonly MetricStore $metrics,
    ) {
    }

    public function execute(): MonitoringOverview
    {
        $since = new \DateTimeImmutable('-24 hours');
        $cards = [];

        foreach ($this->projects->all() as $project) {
            $cards[] = $this->cardFor($project, $since);
        }

        return new MonitoringOverview($cards);
    }

    public function cardFor(Project $project, ?\DateTimeImmutable $since = null): ProjectCard
    {
        $since ??= new \DateTimeImmutable('-24 hours');
        $health = $this->healthSnapshots->latest($project->gameId, HealthEndpoint::Health);
        $ready = $this->healthSnapshots->latest($project->gameId, HealthEndpoint::Ready);

        return new ProjectCard(
            gameId: $project->gameId->value,
            displayName: $project->displayName,
            healthUrl: $project->healthUrl,
            readyUrl: $project->readyUrl,
            healthStatus: $health?->status->value,
            healthHttpCode: $health?->httpCode,
            healthLatencyMs: $health?->latencyMs,
            healthCheckedAt: $this->formatTime($health),
            readyStatus: $ready?->status->value,
            readyHttpCode: $ready?->httpCode,
            readyLatencyMs: $ready?->latencyMs,
            readyCheckedAt: $this->formatTime($ready),
            recentMetricCount: $this->metrics->countSince($project->gameId, $since),
            recentMetricTotals: $this->metrics->totalsSince($project->gameId, $since),
            gauges: $this->metrics->latestGauges($project->gameId, $since),
        );
    }

    public function requireProject(string $gameId): Project
    {
        $project = $this->projects->findByGameId(GameId::fromString($gameId));
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        return $project;
    }

    private function formatTime(?HealthSnapshot $snapshot): ?string
    {
        return $snapshot?->checkedAt->format(\DateTimeInterface::ATOM);
    }
}
