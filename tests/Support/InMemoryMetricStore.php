<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\GameId;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\MetricStore;

final class InMemoryMetricStore implements MetricStore
{
    /** @var list<MetricSample> */
    private array $samples = [];

    public function recordBatch(MetricBatch $batch): void
    {
        foreach ($batch->samples as $sample) {
            $this->samples[] = $sample;
        }
    }

    public function countSince(GameId $gameId, \DateTimeImmutable $since): int
    {
        return count($this->since($gameId, $since));
    }

    public function totalsSince(GameId $gameId, \DateTimeImmutable $since): array
    {
        $totals = [];
        foreach ($this->since($gameId, $since) as $sample) {
            $totals[$sample->name] = ($totals[$sample->name] ?? 0.0) + $sample->value;
        }
        ksort($totals);

        return $totals;
    }

    public function recent(GameId $gameId, int $limit = 50): array
    {
        $filtered = array_values(array_filter(
            $this->samples,
            static fn (MetricSample $sample): bool => $sample->gameId->value === $gameId->value,
        ));

        return array_slice(array_reverse($filtered), 0, $limit);
    }

    /**
     * @return list<MetricSample>
     */
    private function since(GameId $gameId, \DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $this->samples,
            static fn (MetricSample $sample): bool => $sample->gameId->value === $gameId->value
                && $sample->recordedAt >= $since,
        ));
    }
}
