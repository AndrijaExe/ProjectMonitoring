<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;
use App\Model\HealthStatus;

final class JsonHealthSnapshotStore implements HealthSnapshotStore
{
    public function __construct(private readonly JsonFileDatabase $database)
    {
    }

    public function record(HealthSnapshot $snapshot): void
    {
        $this->database->mutate(static function (array $state) use ($snapshot): array {
            $state['health_snapshots'][] = [
                'game_id' => $snapshot->gameId->value,
                'endpoint' => $snapshot->endpoint->value,
                'status' => $snapshot->status->value,
                'http_code' => $snapshot->httpCode,
                'latency_ms' => $snapshot->latencyMs,
                'error' => $snapshot->error,
                'checked_at' => $snapshot->checkedAt->format(\DateTimeInterface::ATOM),
            ];

            return $state;
        });
    }

    public function latest(GameId $gameId, HealthEndpoint $endpoint): ?HealthSnapshot
    {
        $match = null;
        foreach ($this->database->read()['health_snapshots'] as $row) {
            if (($row['game_id'] ?? '') === $gameId->value && ($row['endpoint'] ?? '') === $endpoint->value) {
                $match = $this->hydrate($row);
            }
        }

        return $match;
    }

    public function recent(GameId $gameId, int $limit = 40): array
    {
        $snapshots = [];
        foreach ($this->database->read()['health_snapshots'] as $row) {
            if (($row['game_id'] ?? '') === $gameId->value) {
                $snapshots[] = $this->hydrate($row);
            }
        }

        usort(
            $snapshots,
            static fn (HealthSnapshot $a, HealthSnapshot $b): int => $b->checkedAt <=> $a->checkedAt,
        );

        return array_slice($snapshots, 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): HealthSnapshot
    {
        return new HealthSnapshot(
            GameId::fromString((string) $row['game_id']),
            HealthEndpoint::from((string) $row['endpoint']),
            HealthStatus::from((string) $row['status']),
            (int) $row['http_code'],
            (int) $row['latency_ms'],
            new \DateTimeImmutable((string) $row['checked_at']),
            isset($row['error']) && $row['error'] !== '' ? (string) $row['error'] : null,
        );
    }
}
