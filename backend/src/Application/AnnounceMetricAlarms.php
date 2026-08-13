<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\AlarmStateStore;
use App\Model\AlertChannel;
use App\Model\GameReading;
use App\Model\MetricAlert;
use App\Model\MetricStore;
use App\Model\Notification;
use App\Model\Project;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides when a game's own numbers deserve a mail.
 *
 * A probe answers whether the service is reachable, which is a different question from whether
 * it is working: every AI call can be failing over to the fallback, a whole day can pass with
 * nobody playing, the counters can quietly fall back to memory, and /healthz will answer 200
 * through all of it. These four alarms are the ones that survived the noise test — each fires
 * on a change rather than on a state, so an unreleased game with no players is silent instead
 * of ringing every hour.
 */
final class AnnounceMetricAlarms
{
    private const PLAYERS_ONLINE = 'players.online';
    private const RATE_WINDOW_HOURS = 1;
    private const QUIET_WINDOW_HOURS = 24;

    /** @var array<string, float> */
    private readonly array $rateLimits;

    public function __construct(
        private readonly MetricStore $metrics,
        private readonly AlarmStateStore $alarms,
        private readonly AlertChannel $channel,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ALERT_RATE_PER_HOUR)%')]
        string $rateLimits = '',
    ) {
        $this->rateLimits = self::parseLimits($rateLimits);
    }

    /**
     * @param array<string, float> $previousGauges levels as they stood before this reading
     */
    public function forReading(
        Project $project,
        GameReading $reading,
        array $previousGauges,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->channel->isConfigured()) {
            return;
        }

        $raised = $this->evaluate($project, $reading, $previousGauges, $now);
        $open = array_keys($this->alarms->raised($project->gameId));

        foreach ($raised as $alert) {
            if (!in_array($alert->key, $open, true)) {
                $this->announce($project, $alert, $now);
            }
        }

        $keys = array_map(static fn (MetricAlert $alert): string => $alert->key, $raised);
        foreach ($open as $key) {
            if (!in_array($key, $keys, true)) {
                $this->announce($project, $this->recovery($project, $key), $now);
            }
        }
    }

    /**
     * @param array<string, float> $previousGauges
     *
     * @return list<MetricAlert>
     */
    private function evaluate(
        Project $project,
        GameReading $reading,
        array $previousGauges,
        \DateTimeImmutable $now,
    ): array {
        $alerts = [];

        $lastHour = $this->rateLimits === [] ? [] : $this->metrics->totalsBetween(
            $project->gameId,
            $now->modify(sprintf('-%d hours', self::RATE_WINDOW_HOURS)),
            null,
        );

        foreach ($this->rateLimits as $name => $limit) {
            $grown = $lastHour[$name] ?? 0.0;

            if ($grown > $limit) {
                $alerts[] = new MetricAlert($project, 'rate:'.$name, sprintf('%s is rising fast', $name), [
                    sprintf('%s grew by %s in the last hour.', $name, self::number($grown)),
                    sprintf('The configured ceiling is %s per hour.', self::number($limit)),
                ]);
            }
        }

        if ($this->hasGoneQuiet($project, $now)) {
            $alerts[] = new MetricAlert($project, 'quiet', 'nothing has been counted for a day', [
                'The game was counting events the day before and has counted none since.',
                // Both causes look identical from here: an empty window is an empty window
                // whether nothing happened or nothing was read. Naming both saves an hour.
                'Either nothing is reaching the game, or its counters stopped being read at all.',
            ]);
        }

        $playersNow = $reading->gauges[self::PLAYERS_ONLINE] ?? null;
        $playersBefore = $previousGauges[self::PLAYERS_ONLINE] ?? null;
        if ($playersNow !== null && $playersNow <= 0.0 && $playersBefore !== null && $playersBefore > 0.0) {
            $alerts[] = new MetricAlert($project, 'players.gone', 'nobody is online', [
                sprintf('Players online fell from %s to zero.', self::number($playersBefore)),
            ]);
        }

        if ($reading->storage === 'memory') {
            $alerts[] = new MetricAlert($project, 'storage.memory', 'counters are being kept in memory', [
                'Every count resets when the game restarts, so its numbers understate reality.',
                'Set REDIS_URL on the game for counts that survive a deploy.',
            ]);
        }

        return $alerts;
    }

    /**
     * A game that has never counted anything is not quiet, it is unreleased. Only a game that
     * was counting and then stopped has said something worth a mail.
     */
    private function hasGoneQuiet(Project $project, \DateTimeImmutable $now): bool
    {
        $window = $now->modify(sprintf('-%d hours', self::QUIET_WINDOW_HOURS));
        if (self::sum($this->metrics->totalsBetween($project->gameId, $window, null)) > 0.0) {
            return false;
        }

        $before = $window->modify(sprintf('-%d hours', self::QUIET_WINDOW_HOURS));

        return self::sum($this->metrics->totalsBetween($project->gameId, $before, $window)) > 0.0;
    }

    private function recovery(Project $project, string $key): MetricAlert
    {
        $summary = match (true) {
            str_starts_with($key, 'rate:') => sprintf('%s is back within its limit', substr($key, 5)),
            $key === 'quiet' => 'events are being counted again',
            $key === 'players.gone' => 'players are online again',
            $key === 'storage.memory' => 'counters are being stored properly again',
            // An alarm key this version no longer raises. Closing it quietly would leave the
            // operator with an unexplained warning as the last thing they heard.
            default => sprintf('the "%s" warning no longer applies', $key),
        };

        return (new MetricAlert($project, $key, $summary))->cleared();
    }

    private function announce(Project $project, MetricAlert $alert, \DateTimeImmutable $now): void
    {
        // State changes only after the mail is away. Recording an alarm that was never
        // delivered would silence it for good, which is the one outcome worse than a duplicate.
        if (!$this->send($alert)) {
            return;
        }

        if ($alert->cleared) {
            $this->alarms->close($project->gameId, $alert->key);

            return;
        }

        $this->alarms->open($project->gameId, $alert->key, $now);
    }

    private function send(Notification $alert): bool
    {
        try {
            $this->channel->send($alert);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not send a metric alarm.', [
                'game_id' => $alert->project->gameId->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, float> $totals
     */
    private static function sum(array $totals): float
    {
        return array_sum($totals);
    }

    private static function number(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : sprintf('%.2f', $value);
    }

    /**
     * Reads "name=limit,other=limit". Malformed entries are dropped rather than fatal: this
     * arrives from a deploy-time environment variable, and a typo in it must not stop the poll
     * that also records health.
     *
     * @return array<string, float>
     */
    private static function parseLimits(string $raw): array
    {
        $limits = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $limit = trim($parts[1]);
            if ($name === '' || !is_numeric($limit) || (float) $limit < 0) {
                continue;
            }

            $limits[$name] = (float) $limit;
        }

        return $limits;
    }
}
