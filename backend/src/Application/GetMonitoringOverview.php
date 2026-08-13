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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GetMonitoringOverview
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly HealthSnapshotStore $healthSnapshots,
        private readonly MetricStore $metrics,
        #[Autowire('%env(int:POLL_MAX_AGE_MINUTES)%')]
        private readonly int $staleAfterMinutes = 120,
    ) {
    }

    public function execute(): MonitoringOverview
    {
        $now = new \DateTimeImmutable();
        $since = $now->modify('-24 hours');
        $cards = [];

        foreach ($this->projects->all() as $project) {
            $cards[] = $this->cardFor($project, $since);
        }

        $newest = $this->newestProbe($cards);

        return new MonitoringOverview(
            projects: $cards,
            lastProbeAt: $newest?->format(\DateTimeInterface::ATOM),
            // A registered project with no recent probe counts as stale, including one never
            // probed at all: nobody is watching it either way, and that is the thing to say.
            stale: $cards !== [] && $this->staleAfterMinutes > 0 && (
                $newest === null || $newest < $now->modify(sprintf('-%d minutes', $this->staleAfterMinutes))
            ),
            staleAfterMinutes: $this->staleAfterMinutes,
        );
    }

    /**
     * @param list<ProjectCard> $cards
     */
    private function newestProbe(array $cards): ?\DateTimeImmutable
    {
        $newest = null;

        foreach ($cards as $card) {
            foreach ([$card->healthCheckedAt, $card->readyCheckedAt] as $checkedAt) {
                if ($checkedAt === null) {
                    continue;
                }

                $at = new \DateTimeImmutable($checkedAt);
                if ($newest === null || $at > $newest) {
                    $newest = $at;
                }
            }
        }

        return $newest;
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
