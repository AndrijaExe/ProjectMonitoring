<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\Alert;
use App\Model\AlertChannel;
use App\Model\HealthSnapshot;
use App\Model\HealthSnapshotStore;
use App\Model\Project;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a fresh probe result is worth an email.
 *
 * Only transitions are announced. A target that has been down for a day should produce one
 * message, not one per poll, and an operator who gets a mail per hour stops reading them.
 */
final class AnnounceHealthChange
{
    /**
     * Enough rows to see past a long outage: at an hourly poll and two probes per project,
     * this covers a couple of days. Beyond it, a recovery reports its duration as a floor.
     */
    private const HISTORY = 120;

    public function __construct(
        private readonly HealthSnapshotStore $snapshots,
        private readonly AlertChannel $channel,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function forNewSnapshot(Project $project, HealthSnapshot $fresh): void
    {
        if (!$this->channel->isConfigured() || !$fresh->status->isConclusive()) {
            return;
        }

        $history = $this->conclusiveHistory($fresh);
        $previous = $history[0] ?? null;

        if ($previous === null) {
            // Nothing to compare against. A first sighting that is healthy is not news.
            if ($fresh->status->isHealthy()) {
                return;
            }
        } elseif ($previous->status->isHealthy() === $fresh->status->isHealthy()) {
            return;
        }

        $alert = $fresh->status->isHealthy()
            ? $this->recovery($project, $fresh, $history)
            : new Alert($project, $fresh, $previous?->status);

        // A mail server having a bad day must not fail the poll: the snapshot is the record
        // that matters, and it is already on its way to the database.
        try {
            $this->channel->send($alert);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not send a health alert.', [
                'game_id' => $project->gameId->value,
                'endpoint' => $fresh->endpoint->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param list<HealthSnapshot> $history newest first, conclusive only
     */
    private function recovery(Project $project, HealthSnapshot $fresh, array $history): Alert
    {
        $startedAt = null;
        foreach ($history as $snapshot) {
            if ($snapshot->status->isHealthy()) {
                break;
            }

            $startedAt = $snapshot;
        }

        if ($startedAt === null) {
            return new Alert($project, $fresh, $history[0]->status ?? null);
        }

        $exhausted = count($history) >= self::HISTORY;

        return new Alert(
            $project,
            $fresh,
            $history[0]->status,
            max(0, $fresh->checkedAt->getTimestamp() - $startedAt->checkedAt->getTimestamp()),
            $exhausted,
        );
    }

    /**
     * @return list<HealthSnapshot> newest first, same endpoint, inconclusive readings dropped
     */
    private function conclusiveHistory(HealthSnapshot $fresh): array
    {
        $history = [];
        foreach ($this->snapshots->recent($fresh->gameId, self::HISTORY) as $snapshot) {
            if ($snapshot->endpoint === $fresh->endpoint && $snapshot->status->isConclusive()) {
                $history[] = $snapshot;
            }
        }

        return $history;
    }
}
