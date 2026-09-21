<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DTO\ProjectReport;
use App\Model\MetricStore;

/**
 * The game's counters cut into equal buckets — days, ISO weeks or calendar months — so a
 * question like "how many runs finished this week against last" has an answer that does not
 * depend on when the page was opened.
 *
 * Each bucket is the growth of every counter inside its window, the same arithmetic the
 * 24-hour card uses, plus the AI spend split by provider for the same window. The newest
 * bucket is the one in progress and is marked so, because comparing a Tuesday with a whole
 * previous week is the mistake this report exists to prevent.
 */
final class GetProjectReport
{
    public function __construct(
        private readonly GetMonitoringOverview $overview,
        private readonly MetricStore $metrics,
    ) {
    }

    public function execute(string $gameId, ReportPeriod $period, ?\DateTimeImmutable $now = null): ProjectReport
    {
        $now ??= new \DateTimeImmutable('now');
        $project = $this->overview->requireProject($gameId);
        $breakdown = new UsageBreakdown();

        $current = $period->bucketStart($now);
        $start = $current;
        for ($i = 1; $i < $period->bucketCount(); ++$i) {
            $start = $period->previous($start);
        }

        $buckets = [];
        for ($from = $start; $from <= $current; $from = $period->next($from)) {
            $until = $period->next($from);
            $complete = $until <= $now;
            $totals = $this->metrics->totalsBetween($project->gameId, $from, $complete ? $until : null);

            $buckets[] = [
                'key' => $period->key($from),
                'start' => $from->format(\DateTimeInterface::ATOM),
                'end' => $until->format(\DateTimeInterface::ATOM),
                'complete' => $complete,
                'totals' => $this->rounded($totals),
                'usage' => $breakdown->summarize($totals),
            ];
        }

        return new ProjectReport($project->gameId->value, $period->value, $buckets);
    }

    /**
     * Counters are whole events; a fraction here is a float artefact, not a half a run.
     *
     * @param array<string, float> $totals
     *
     * @return array<string, int>
     */
    private function rounded(array $totals): array
    {
        $out = [];
        foreach ($totals as $name => $value) {
            $out[$name] = (int) round($value);
        }

        return $out;
    }
}
