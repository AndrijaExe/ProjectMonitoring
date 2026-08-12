<?php

declare(strict_types=1);

namespace App\Model;

interface HealthSnapshotStore
{
    public function record(HealthSnapshot $snapshot): void;

    public function latest(GameId $gameId, HealthEndpoint $endpoint): ?HealthSnapshot;

    /**
     * @return list<HealthSnapshot>
     */
    public function recent(GameId $gameId, int $limit = 40): array;
}
