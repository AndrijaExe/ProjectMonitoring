<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Alert
{
    public function __construct(
        public Project $project,
        public HealthSnapshot $snapshot,
        public ?HealthStatus $previous,
        public ?int $outageSeconds = null,
        /** True when the outage began before the history we kept, so the duration is a floor. */
        public bool $outageIsLowerBound = false,
        public bool $isDrill = false,
    ) {
    }

    /**
     * A deliberately fake alert, sent through the real path. An alerting setup nobody has
     * ever seen deliver is a guess, and the day it matters is the wrong day to find out.
     */
    public static function drill(Project $project, \DateTimeImmutable $now = new \DateTimeImmutable()): self
    {
        return new self(
            $project,
            new HealthSnapshot(
                $project->gameId,
                HealthEndpoint::Health,
                HealthStatus::Down,
                0,
                0,
                $now,
                'Nothing is wrong. Somebody pressed the test button in the console.',
            ),
            HealthStatus::Ok,
            isDrill: true,
        );
    }

    public function isRecovery(): bool
    {
        return $this->snapshot->status->isHealthy();
    }

    public function subject(): string
    {
        return sprintf(
            '%s%s %s is %s',
            $this->isDrill ? '[test] ' : '',
            $this->project->displayName,
            $this->snapshot->endpoint->value,
            $this->snapshot->status->value,
        );
    }

    public function body(): string
    {
        $lines = [
            sprintf('%s (%s)', $this->project->displayName, $this->project->gameId->value),
            sprintf('Probe:    %s', $this->snapshot->endpoint->value),
            sprintf(
                'Status:   %s%s',
                $this->snapshot->status->value,
                $this->previous === null ? ' (first conclusive reading)' : ', was '.$this->previous->value,
            ),
            sprintf('HTTP:     %s', $this->snapshot->httpCode === 0 ? 'no response' : (string) $this->snapshot->httpCode),
            sprintf('Latency:  %dms', $this->snapshot->latencyMs),
            sprintf('At:       %s', $this->snapshot->checkedAt->format('Y-m-d H:i:s T')),
        ];

        if ($this->snapshot->error !== null && $this->snapshot->error !== '') {
            $lines[] = sprintf('Note:     %s', $this->snapshot->error);
        }

        if ($this->outageSeconds !== null) {
            $lines[] = sprintf(
                'Down for: %s%s',
                $this->humanDuration($this->outageSeconds),
                $this->outageIsLowerBound ? ' or longer' : '',
            );
        }

        return implode("\n", $lines);
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%d seconds', $seconds);
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return sprintf('%d minutes', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? sprintf('%d hours', $hours)
            : sprintf('%d hours %d minutes', $hours, $rest);
    }
}
