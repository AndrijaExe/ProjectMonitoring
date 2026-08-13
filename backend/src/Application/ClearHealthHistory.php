<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\GameId;
use App\Model\HealthSnapshotStore;
use App\Model\ProjectRepository;
use Psr\Log\LoggerInterface;

final class ClearHealthHistory
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly HealthSnapshotStore $snapshots,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{cleared: int}
     */
    public function execute(string $gameId): array
    {
        $project = $this->projects->findByGameId(GameId::fromString($gameId));
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        $cleared = $this->snapshots->deleteFor($project->gameId);

        // Deleting evidence without leaving a trace is how a monitor starts lying. The line
        // lands in the service log, which the console can read back on the fleet page.
        $this->logger->warning('Health history cleared from the console.', [
            'game_id' => $project->gameId->value,
            'rows' => $cleared,
        ]);

        return ['cleared' => $cleared];
    }
}
