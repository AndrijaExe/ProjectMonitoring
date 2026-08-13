<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;

final class InMemoryHealthSnapshotStore implements HealthSnapshotStore
{
    /** @var list<HealthSnapshot> */
    private array $snapshots = [];

    public function record(HealthSnapshot $snapshot): void
    {
        $this->snapshots[] = $snapshot;
    }

    public function deleteFor(GameId $gameId): int
    {
        $before = count($this->snapshots);
        $this->snapshots = array_values(array_filter(
            $this->snapshots,
            static fn (HealthSnapshot $snapshot): bool => $snapshot->gameId->value !== $gameId->value,
        ));

        return $before - count($this->snapshots);
    }

    public function latest(GameId $gameId, HealthEndpoint $endpoint): ?HealthSnapshot
    {
        $match = null;
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->gameId->value === $gameId->value && $snapshot->endpoint === $endpoint) {
                $match = $snapshot;
            }
        }

        return $match;
    }

    public function recent(GameId $gameId, int $limit = 40): array
    {
        $filtered = array_values(array_filter(
            $this->snapshots,
            static fn (HealthSnapshot $snapshot): bool => $snapshot->gameId->value === $gameId->value,
        ));

        return array_slice(array_reverse($filtered), 0, $limit);
    }
}
