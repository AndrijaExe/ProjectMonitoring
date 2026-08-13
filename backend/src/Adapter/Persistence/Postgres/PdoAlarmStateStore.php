<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use App\Model\AlarmStateStore;
use App\Model\GameId;

final class PdoAlarmStateStore implements AlarmStateStore
{
    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function raised(GameId $gameId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT alarm_key, opened_at FROM metric_alarms WHERE game_id = :game_id ORDER BY alarm_key ASC',
        );
        $statement->execute(['game_id' => $gameId->value]);

        $raised = [];
        foreach ($statement->fetchAll() as $row) {
            $raised[(string) $row['alarm_key']] = new \DateTimeImmutable((string) $row['opened_at']);
        }

        return $raised;
    }

    public function open(GameId $gameId, string $key, \DateTimeImmutable $at): void
    {
        // Already raised means already mailed, so the first opening time is the one to keep.
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            INSERT INTO metric_alarms (game_id, alarm_key, opened_at)
            VALUES (:game_id, :alarm_key, :opened_at)
            ON CONFLICT (game_id, alarm_key) DO NOTHING
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'alarm_key' => $key,
            'opened_at' => $at->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function close(GameId $gameId, string $key): void
    {
        $statement = $this->connection->pdo()->prepare(
            'DELETE FROM metric_alarms WHERE game_id = :game_id AND alarm_key = :alarm_key',
        );
        $statement->execute([
            'game_id' => $gameId->value,
            'alarm_key' => $key,
        ]);
    }
}
