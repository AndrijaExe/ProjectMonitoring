<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\GameMetricSource;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\MetricsUnavailable;
use App\Model\MetricStore;
use App\Model\Project;
use Psr\Log\LoggerInterface;

/**
 * Takes one reading of a game's counters and stores it.
 *
 * The reading is cumulative, so a single row means little on its own; the pair of rows either
 * side of a window is what answers "how many today". Storing the raw reading rather than a
 * computed delta means a missed poll costs resolution, not data.
 */
final class CollectGameMetrics
{
    public function __construct(
        private readonly GameMetricSource $source,
        private readonly MetricStore $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function forProject(Project $project, \DateTimeImmutable $now): int
    {
        if ($project->metricsUrl === null) {
            return 0;
        }

        try {
            $counters = $this->source->read($project);
        } catch (MetricsUnavailable $exception) {
            // A game that will not report its counters is not an outage. The probe already
            // said whether it is up, and that is the part the operator is paged about.
            $this->logger->info('Could not read game counters.', [
                'game_id' => $project->gameId->value,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }

        $samples = [];
        foreach ($counters as $name => $value) {
            $samples[] = new MetricSample($project->gameId, $name, $value, ['kind' => 'counter'], $now);
        }

        if ($samples === []) {
            return 0;
        }

        // A game publishing more series than a batch holds gets the first of them rather than
        // a rejected reading. Losing the tail beats losing everything.
        $samples = array_slice($samples, 0, MetricBatch::MAX_SAMPLES);
        $this->metrics->recordBatch(new MetricBatch($samples));

        return count($samples);
    }
}
