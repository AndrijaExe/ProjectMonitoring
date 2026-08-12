<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Model\GameId;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\MetricStore;

final class JsonMetricStore implements MetricStore
{
    public function __construct(private readonly JsonFileDatabase $database)
    {
    }

    public function recordBatch(MetricBatch $batch): void
    {
        $this->database->mutate(static function (array $state) use ($batch): array {
            foreach ($batch->samples as $sample) {
                $state['metric_samples'][] = [
                    'game_id' => $sample->gameId->value,
                    'name' => $sample->name,
                    'value' => $sample->value,
                    'tags' => $sample->tags,
                    'recorded_at' => $sample->recordedAt->format(\DateTimeInterface::ATOM),
                ];
            }

            return $state;
        });
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
        $samples = [];
        foreach ($this->database->read()['metric_samples'] as $row) {
            if (($row['game_id'] ?? '') === $gameId->value) {
                $samples[] = $this->hydrate($row);
            }
        }

        usort(
            $samples,
            static fn (MetricSample $a, MetricSample $b): int => $b->recordedAt <=> $a->recordedAt,
        );

        return array_slice($samples, 0, $limit);
    }

    /**
     * @return list<MetricSample>
     */
    private function since(GameId $gameId, \DateTimeImmutable $since): array
    {
        $samples = [];
        foreach ($this->database->read()['metric_samples'] as $row) {
            if (($row['game_id'] ?? '') !== $gameId->value) {
                continue;
            }
            $sample = $this->hydrate($row);
            if ($sample->recordedAt >= $since) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MetricSample
    {
        $tags = [];
        if (isset($row['tags']) && is_array($row['tags'])) {
            foreach ($row['tags'] as $key => $value) {
                $tags[(string) $key] = (string) $value;
            }
        }

        return new MetricSample(
            GameId::fromString((string) $row['game_id']),
            (string) $row['name'],
            (float) $row['value'],
            $tags,
            new \DateTimeImmutable((string) $row['recorded_at']),
        );
    }
}
