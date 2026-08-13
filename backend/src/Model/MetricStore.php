<?php

declare(strict_types=1);

namespace App\Model;

interface MetricStore
{
    public function recordBatch(MetricBatch $batch): void;

    public function countSince(GameId $gameId, \DateTimeImmutable $since): int;

    /**
     * Counters and pushed events only. A gauge has no window total worth printing.
     *
     * @return array<string, float>
     */
    public function totalsSince(GameId $gameId, \DateTimeImmutable $since): array;

    /**
     * The same totals over a closed window, so one period can be compared with the one before
     * it. Answering "has this gone quiet" needs both halves; "how much today" needs only one.
     *
     * @return array<string, float>
     */
    public function totalsBetween(GameId $gameId, \DateTimeImmutable $from, ?\DateTimeImmutable $until): array;

    /**
     * The newest value of each gauge, which is the only value a level has.
     *
     * @return array<string, float>
     */
    public function latestGauges(GameId $gameId, \DateTimeImmutable $since): array;

    /**
     * @return list<MetricSample>
     */
    public function recent(GameId $gameId, int $limit = 50): array;
}
