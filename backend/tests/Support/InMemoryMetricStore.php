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
        /** @var array<string, list<MetricSample>> $bySeries */
        $bySeries = [];
        foreach ($this->since($gameId, $since) as $sample) {
            $bySeries[$sample->name][] = $sample;
        }

        $totals = [];
        foreach ($bySeries as $name => $samples) {
            usort($samples, static fn (MetricSample $a, MetricSample $b): int => $a->recordedAt <=> $b->recordedAt);
            $first = $samples[0];
            $last = $samples[count($samples) - 1];

            if (($first->tags['kind'] ?? '') === 'gauge') {
                continue;
            }

            if (($first->tags['kind'] ?? '') === 'counter') {
                // Cumulative readings grow; the window total is the growth, not the sum,
                // measured from wherever the counter stood when the window opened.
                $baseline = $this->lastValueBefore($gameId, $name, $since) ?? $first->value;
                $totals[$name] = $last->value >= $baseline
                    ? $last->value - $baseline
                    : $last->value;

                continue;
            }

            $totals[$name] = array_sum(array_map(
                static fn (MetricSample $sample): float => $sample->value,
                $samples,
            ));
        }
        ksort($totals);

        return $totals;
    }

    public function latestGauges(GameId $gameId, \DateTimeImmutable $since): array
    {
        $gauges = [];
        foreach ($this->since($gameId, $since) as $sample) {
            if (($sample->tags['kind'] ?? '') !== 'gauge') {
                continue;
            }

            $known = $gauges[$sample->name] ?? null;
            if ($known === null || $sample->recordedAt >= $known->recordedAt) {
                $gauges[$sample->name] = $sample;
            }
        }

        $latest = [];
        foreach ($gauges as $name => $sample) {
            $latest[$name] = $sample->value;
        }
        ksort($latest);

        return $latest;
    }

    public function recent(GameId $gameId, int $limit = 50): array
    {
        $filtered = array_values(array_filter(
            $this->samples,
            static fn (MetricSample $sample): bool => $sample->gameId->value === $gameId->value,
        ));

        return array_slice(array_reverse($filtered), 0, $limit);
    }

    private function lastValueBefore(GameId $gameId, string $name, \DateTimeImmutable $since): ?float
    {
        $earlier = array_values(array_filter(
            $this->samples,
            static fn (MetricSample $sample): bool => $sample->gameId->value === $gameId->value
                && $sample->name === $name
                && $sample->recordedAt < $since,
        ));

        if ($earlier === []) {
            return null;
        }

        usort($earlier, static fn (MetricSample $a, MetricSample $b): int => $a->recordedAt <=> $b->recordedAt);

        return $earlier[count($earlier) - 1]->value;
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
