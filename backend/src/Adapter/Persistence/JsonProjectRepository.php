<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Model\GameId;
use App\Model\Project;
use App\Model\ProjectRepository;

final class JsonProjectRepository implements ProjectRepository
{
    public function __construct(private readonly JsonFileDatabase $database)
    {
    }

    public function save(Project $project): void
    {
        $this->database->mutate(static function (array $state) use ($project): array {
            $row = [
                'game_id' => $project->gameId->value,
                'display_name' => $project->displayName,
                'health_url' => $project->healthUrl,
                'ready_url' => $project->readyUrl,
                'ingest_token_hash' => $project->ingestTokenHash,
            ];

            foreach ($state['projects'] as $index => $existing) {
                if (($existing['game_id'] ?? '') === $project->gameId->value) {
                    $state['projects'][$index] = $row;

                    return $state;
                }
            }

            $state['projects'][] = $row;

            return $state;
        });
    }

    public function findByGameId(GameId $gameId): ?Project
    {
        foreach ($this->database->read()['projects'] as $row) {
            if (($row['game_id'] ?? '') === $gameId->value) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    public function all(): array
    {
        $projects = array_map($this->hydrate(...), $this->database->read()['projects']);
        usort($projects, static fn (Project $a, Project $b): int => $a->displayName <=> $b->displayName);

        return $projects;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Project
    {
        return new Project(
            GameId::fromString((string) $row['game_id']),
            (string) $row['display_name'],
            (string) $row['health_url'],
            (string) $row['ready_url'],
            (string) $row['ingest_token_hash'],
        );
    }
}
