<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\GameId;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\MetricStore;
use App\Model\ProjectRepository;

final class IngestMetricBatch
{
    /**
     * Tags the monitor writes about a series, so a sender cannot write them.
     *
     * "kind" decides how a number is read: a counter is shown as its growth over the window, a
     * gauge as its newest value alone. That is the monitor's reading of the series, not a fact
     * the game measures, and a sender who could set it could decide how its own numbers are
     * totalled. A pushed sample is an event with a value, which is what an untagged sample means.
     */
    private const RESERVED_TAGS = ['kind'];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly MetricStore $metrics,
    ) {
    }

    /**
     * @param list<array{name?: mixed, value?: mixed, tags?: mixed, recorded_at?: mixed}> $rawMetrics
     */
    public function execute(string $gameId, array $rawMetrics): MetricBatch
    {
        $id = GameId::fromString($gameId);
        if ($this->projects->findByGameId($id) === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        if ($rawMetrics === []) {
            throw new \InvalidArgumentException('Metric batch cannot be empty.');
        }

        if (count($rawMetrics) > MetricBatch::MAX_SAMPLES) {
            throw new \InvalidArgumentException(sprintf('Metric batch cannot exceed %d samples.', MetricBatch::MAX_SAMPLES));
        }

        $now = new \DateTimeImmutable();
        $samples = [];
        foreach ($rawMetrics as $index => $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException(sprintf('Metric at index %d is invalid.', $index));
            }

            $name = is_string($raw['name'] ?? null) ? $raw['name'] : '';
            $value = $raw['value'] ?? null;
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException(sprintf('Metric "%s" needs a numeric value.', $name !== '' ? $name : '#'.$index));
            }

            $tags = [];
            if (isset($raw['tags'])) {
                if (!is_array($raw['tags'])) {
                    throw new \InvalidArgumentException('Metric tags must be an object of string values.');
                }
                foreach ($raw['tags'] as $key => $tagValue) {
                    if (!is_string($key) || !is_scalar($tagValue)) {
                        throw new \InvalidArgumentException('Metric tags must be string keys and scalar values.');
                    }

                    // Dropped rather than refused: the batch is still good data, and a sender
                    // that never asked to label the series should not lose it over one key.
                    if (in_array($key, self::RESERVED_TAGS, true)) {
                        continue;
                    }

                    $tags[$key] = (string) $tagValue;
                }
            }

            $recordedAt = $now;
            if (isset($raw['recorded_at']) && is_string($raw['recorded_at']) && $raw['recorded_at'] !== '') {
                try {
                    $recordedAt = new \DateTimeImmutable($raw['recorded_at']);
                } catch (\Exception) {
                    throw new \InvalidArgumentException('recorded_at must be an ISO-8601 timestamp.');
                }
            }

            $samples[] = new MetricSample($id, $name, (float) $value, $tags, $recordedAt);
        }

        $batch = new MetricBatch($samples);
        $this->metrics->recordBatch($batch);

        return $batch;
    }
}
