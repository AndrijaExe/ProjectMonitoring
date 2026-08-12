<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;
use App\Model\HealthStatus;

final class PdoHealthSnapshotStore implements HealthSnapshotStore
{
    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function record(HealthSnapshot $snapshot): void
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            INSERT INTO health_snapshots (game_id, endpoint, status, http_code, latency_ms, error, checked_at)
            VALUES (:game_id, :endpoint, :status, :http_code, :latency_ms, :error, :checked_at)
            SQL);

        $statement->execute([
            'game_id' => $snapshot->gameId->value,
            'endpoint' => $snapshot->endpoint->value,
            'status' => $snapshot->status->value,
            'http_code' => $snapshot->httpCode,
            'latency_ms' => $snapshot->latencyMs,
            'error' => $snapshot->error,
            'checked_at' => $snapshot->checkedAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function latest(GameId $gameId, HealthEndpoint $endpoint): ?HealthSnapshot
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT * FROM health_snapshots
            WHERE game_id = :game_id AND endpoint = :endpoint
            ORDER BY checked_at DESC, id DESC
            LIMIT 1
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'endpoint' => $endpoint->value,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function recent(GameId $gameId, int $limit = 40): array
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT * FROM health_snapshots
            WHERE game_id = :game_id
            ORDER BY checked_at DESC, id DESC
            LIMIT :limit
            SQL);
        $statement->bindValue('game_id', $gameId->value);
        $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): HealthSnapshot
    {
        $error = $row['error'] ?? null;

        return new HealthSnapshot(
            GameId::fromString((string) $row['game_id']),
            HealthEndpoint::from((string) $row['endpoint']),
            HealthStatus::from((string) $row['status']),
            (int) $row['http_code'],
            (int) $row['latency_ms'],
            new \DateTimeImmutable((string) $row['checked_at']),
            is_string($error) && $error !== '' ? $error : null,
        );
    }
}
