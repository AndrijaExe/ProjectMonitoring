<?php

declare(strict_types=1);

namespace App\Model;

interface MetricStore
{
    public function recordBatch(MetricBatch $batch): void;

    public function countSince(GameId $gameId, \DateTimeImmutable $since): int;

    /**
     * @return array<string, float>
     */
    public function totalsSince(GameId $gameId, \DateTimeImmutable $since): array;

    /**
     * @return list<MetricSample>
     */
    public function recent(GameId $gameId, int $limit = 50): array;
}
