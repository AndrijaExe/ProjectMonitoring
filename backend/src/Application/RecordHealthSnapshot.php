<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthProbe;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;
use App\Model\Project;
use App\Model\ProjectRepository;

final class RecordHealthSnapshot
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly HealthProbe $probe,
        private readonly HealthSnapshotStore $snapshots,
        private readonly AnnounceHealthChange $announce,
    ) {
    }

    /**
     * @return list<HealthSnapshot>
     */
    public function forAll(): array
    {
        $recorded = [];
        foreach ($this->projects->all() as $project) {
            $recorded = [...$recorded, ...$this->forProject($project)];
        }

        return $recorded;
    }

    /**
     * @return list<HealthSnapshot>
     */
    public function forGameId(string $gameId): array
    {
        $project = $this->projects->findByGameId(GameId::fromString($gameId));
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        return $this->forProject($project);
    }

    /**
     * @return list<HealthSnapshot>
     */
    private function forProject(Project $project): array
    {
        $now = new \DateTimeImmutable();
        $health = $this->probe->probe($project->healthUrl, HealthEndpoint::Health);
        $ready = $this->probe->probe($project->readyUrl, HealthEndpoint::Ready);

        $snapshots = [
            new HealthSnapshot(
                $project->gameId,
                HealthEndpoint::Health,
                $health->status,
                $health->httpCode,
                $health->latencyMs,
                $now,
                $health->error,
            ),
            new HealthSnapshot(
                $project->gameId,
                HealthEndpoint::Ready,
                $ready->status,
                $ready->httpCode,
                $ready->latencyMs,
                $now,
                $ready->error,
            ),
        ];

        foreach ($snapshots as $snapshot) {
            // Announced before the write, so the comparison reads history without the row
            // that is about to join it.
            $this->announce->forNewSnapshot($project, $snapshot);
            $this->snapshots->record($snapshot);
        }

        return $snapshots;
    }
}
