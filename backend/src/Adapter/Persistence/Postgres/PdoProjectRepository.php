<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use App\Model\GameId;
use App\Model\Project;
use App\Model\ProjectRepository;

final class PdoProjectRepository implements ProjectRepository
{
    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function save(Project $project): void
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            INSERT INTO projects (game_id, display_name, health_url, ready_url, ingest_token_hash, metrics_url)
            VALUES (:game_id, :display_name, :health_url, :ready_url, :ingest_token_hash, :metrics_url)
            ON CONFLICT (game_id) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                health_url = EXCLUDED.health_url,
                ready_url = EXCLUDED.ready_url,
                ingest_token_hash = EXCLUDED.ingest_token_hash,
                metrics_url = EXCLUDED.metrics_url
            SQL);

        $statement->execute([
            'game_id' => $project->gameId->value,
            'display_name' => $project->displayName,
            'health_url' => $project->healthUrl,
            'ready_url' => $project->readyUrl,
            'ingest_token_hash' => $project->ingestTokenHash,
            'metrics_url' => $project->metricsUrl,
        ]);
    }

    public function findByGameId(GameId $gameId): ?Project
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM projects WHERE game_id = :game_id',
        );
        $statement->execute(['game_id' => $gameId->value]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $rows = $this->connection->pdo()
            ->query('SELECT * FROM projects ORDER BY display_name ASC')
            ->fetchAll();

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Project
    {
        $metricsUrl = $row['metrics_url'] ?? null;

        return new Project(
            GameId::fromString((string) $row['game_id']),
            (string) $row['display_name'],
            (string) $row['health_url'],
            (string) $row['ready_url'],
            (string) $row['ingest_token_hash'],
            is_string($metricsUrl) && $metricsUrl !== '' ? $metricsUrl : null,
        );
    }
}
