<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\GameId;
use App\Model\Project;
use App\Model\ProjectRepository;

final class InMemoryProjectRepository implements ProjectRepository
{
    /** @var array<string, Project> */
    private array $projects = [];

    public function save(Project $project): void
    {
        $this->projects[$project->gameId->value] = $project;
    }

    public function findByGameId(GameId $gameId): ?Project
    {
        return $this->projects[$gameId->value] ?? null;
    }

    public function all(): array
    {
        return array_values($this->projects);
    }
}
