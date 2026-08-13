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
        private readonly AnnounceMetricAlarms $alarms,
    ) {
    }

    public function forProject(Project $project, \DateTimeImmutable $now): int
    {
        if ($project->metricsUrl === null) {
            return 0;
        }

        try {
            $reading = $this->source->read($project);
        } catch (MetricsUnavailable $exception) {
            // A game that will not report its counters is not an outage. The probe already
            // said whether it is up, and that is the part the operator is paged about.
            $this->logger->info('Could not read game counters.', [
                'game_id' => $project->gameId->value,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }

        if ($reading->storage === 'memory') {
            // Counts that die with the process are not counts. Said out loud, because the
            // symptom is a board of zeros that looks exactly like a quiet day.
            $this->logger->warning('Game counts in memory, so its numbers reset on every restart.', [
                'game_id' => $project->gameId->value,
            ]);
        }

        // Read before writing: once this reading lands, the previous level is no longer the
        // latest one, and "players fell to zero" needs both sides of that change.
        $previousGauges = $this->metrics->latestGauges($project->gameId, $now->modify('-24 hours'));

        $samples = [];

        // Gauges first, because the batch below is capped: a level dropped from a reading has no
        // second chance, while a counter's growth survives in the next one.
        foreach ($reading->gauges as $name => $value) {
            $samples[] = new MetricSample($project->gameId, $name, $value, ['kind' => 'gauge'], $now);
        }

        foreach ($reading->counters as $name => $value) {
            $samples[] = new MetricSample($project->gameId, $name, $value, ['kind' => 'counter'], $now);
        }

        $stored = 0;

        if ($samples === []) {
            // Answered, but with nothing in it. Without this line the console cannot tell a
            // game that has counted nothing from a reading that never happened.
            $this->logger->info('Game published no counters yet.', [
                'game_id' => $project->gameId->value,
            ]);
        } else {
            // A game publishing more series than a batch holds gets the first of them rather
            // than a rejected reading. Losing the tail beats losing everything.
            $samples = array_slice($samples, 0, MetricBatch::MAX_SAMPLES);
            $this->metrics->recordBatch(new MetricBatch($samples));
            $stored = count($samples);
        }

        // Always, and after the write. A reading with nothing in it is the exact shape of a
        // game whose counters live in memory, which is the thing most worth saying out loud;
        // and a rate has to be measured over a window that includes the reading just taken.
        $this->alarms->forReading($project, $reading, $previousGauges, $now);

        return $stored;
    }
}
