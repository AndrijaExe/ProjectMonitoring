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
 * through all of it. These alarms are the ones that survived the noise test — each opens on a
 * change rather than on a state, so an unreleased game with no players is silent instead of
 * ringing every hour. Once open, an alarm holds for as long as its condition does and is mailed
 * once each way: a warning that clears itself on the next poll is two mails and no information.
 */
final class AnnounceMetricAlarms
{
    private const PLAYERS_ONLINE = 'players.online';
    private const PLAYERS_GONE = 'players.gone';
    private const RATE_WINDOW_HOURS = 1;
    private const DAY_WINDOW_HOURS = 24;
    private const QUIET_WINDOW_HOURS = 24;

    /**
     * Raised when the game's counters could not be read this many scrapes in a row. At the
     * half-hourly poll that is an hour and a half since the last good reading: one cold start or
     * one dropped connection says nothing, a wrong token says the same thing every time.
     */
    public const UNREADABLE = 'metrics.unreadable';
    private const UNREADABLE_AFTER = 3;

    /**
     * Rows in the alarm table that are bookkeeping rather than alarms. The poll is one HTTP
     * request with no memory of the last, so the count of failed scrapes before there are enough
     * to say anything has to live somewhere, and the alarm table is the store this class owns.
     * Nothing mails about these rows, and nothing should show them as raised.
     */
    public const STREAK_PREFIX = 'streak:';
    private const UNREADABLE_STREAK = self::STREAK_PREFIX.self::UNREADABLE.':';

    /**
     * What to do about a counter, for the ones where the number alone does not say. A ceiling
     * of zero on chat.denied.global means the mail arrives on the first refused player, and the
     * operator reading it at launch needs the name of the knob, not a search through the docs.
     *
     * @var array<string, list<string>>
     */
    private const ADVICE = [
        'chat.denied.global' => [
            'The game hit its global daily chat quota: players are being told the service is at capacity.',
            'If the traffic is legitimate, raise GAME_GLOBAL_DAILY_QUOTA on the game\'s Render service; it applies without a cook.',
        ],
        'abuse.watch' => [
            'A single player is chatting far beyond a normal run. Find the player hash in the game\'s log before touching any quota.',
        ],
        'ai.cost.micros' => [
            'The figure is in millionths of a dollar: 3000000 is $3.',
            'A normal day costs cents. Look at chat.messages for the same hours before suspecting the price; a quota that is too loose shows up here first.',
        ],
        'voice.cost.micros' => [
            'The figure is in millionths of a dollar: 5000000 is $5.',
            'Voice is capped per player (VOICE_DAILY_PLAYER_QUOTA, VOICE_MONTHLY_PLAYER_QUOTA) and in total (VOICE_GLOBAL_DAILY_QUOTA) on the game\'s Render service.',
            'To stop it outright, set AI_TTS_PROVIDER=openai if it was switched, or VOICE_ENABLED=false; the game goes on in text either way.',
        ],
        'voice.denied.voice_global' => [
            'The voice kill-switch tripped: every Voice Line owner is being answered in text until the day turns over.',
            'If the traffic is real players, raise VOICE_GLOBAL_DAILY_QUOTA on the game\'s Render service; it applies without a cook.',
        ],
        'voice.unavailable' => [
            'Replies are arriving without a voice (speech provider failing or timing out). Players still get the text.',
        ],
        'auth.unavailable' => [
            'Steam could not be asked whether a player owns the game, so their sign-in was refused: those players have no phone until Steam answers.',
            'Usually Steam itself (steamstat.us). If it persists while Steam is fine, check STEAM_WEB_API_KEY on the game\'s Render service.',
        ],
    ];

    /** @var array<string, float> */
    private readonly array $rateLimits;

    /** @var array<string, float> */
    private readonly array $dayLimits;

    /**
     * @param string $dayLimits the same "name=limit" list, over the last 24 hours rather than
     *                          the last hour — what money is measured in. An hour of voice can be
     *                          fine and the day it adds up to not.
     */
    public function __construct(
        private readonly MetricStore $metrics,
        private readonly AlarmStateStore $alarms,
        private readonly AlertChannel $channel,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ALERT_RATE_PER_HOUR)%')]
        string $rateLimits = '',
        #[Autowire('%env(default::ALERT_PER_DAY)%')]
        ?string $dayLimits = '',
    ) {
        $this->rateLimits = self::parseLimits($rateLimits);
        $this->dayLimits = self::parseLimits((string) $dayLimits);
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

        $open = self::keysOf($this->alarms->raised($project->gameId));
        $raised = $this->evaluate($project, $reading, $previousGauges, $now, $open);

        foreach ($raised as $alert) {
            if (!in_array($alert->key, $open, true)) {
                $this->announce($project, $alert, $now);
            }
        }

        $keys = array_map(static fn (MetricAlert $alert): string => $alert->key, $raised);
        foreach ($open as $key) {
            if (str_starts_with($key, self::STREAK_PREFIX)) {
                // A reading arrived, so whatever run of failures came before it is over. It was
                // never announced, so it is closed without a word.
                $this->alarms->close($project->gameId, $key);

                continue;
            }

            if (!in_array($key, $keys, true)) {
                $this->announce($project, $this->recovery($project, $key), $now);
            }
        }
    }

    /**
     * A scrape that could not be read at all.
     *
     * One is a cold start or a dropped connection. Several in a row are a wrong token or a moved
     * endpoint, and while they last every other alarm here is blind: spend, quotas and the voice
     * kill-switch are judged on readings that are not arriving. A monitor that cannot see and
     * says nothing looks exactly like a quiet night, which is the one thing it must not do.
     *
     * Other open alarms are left as they are. Nothing was read, so nothing is known to have
     * recovered.
     */
    public function forUnreadable(Project $project, string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->channel->isConfigured()) {
            return;
        }

        $raised = $this->alarms->raised($project->gameId);
        $open = self::keysOf($raised);
        if (in_array(self::UNREADABLE, $open, true)) {
            // Already said, and still true. Until a reading arrives there is nothing new to add.
            return;
        }

        [$failures, $since] = self::streak($raised);
        $failures++;
        $since ??= $now;

        if ($failures >= self::UNREADABLE_AFTER) {
            $this->logger->warning('Game counters unreadable for several polls in a row.', [
                'game_id' => $project->gameId->value,
                'failures' => $failures,
                'reason' => $reason,
            ]);

            $alert = new MetricAlert($project, self::UNREADABLE, 'the game\'s counters cannot be read', [
                sprintf(
                    'The last %d readings of its counters failed, the first of them at %s UTC.',
                    $failures,
                    $since->setTimezone(new \DateTimeZone('UTC'))->format('D j M, H:i'),
                ),
                sprintf('Latest error: %s', $reason),
                // Counters are only read after a passing health probe, so this much is known.
                'Each of those polls found the game up; it is only its numbers that are out of reach.',
                'Until this clears no other metric alarm can fire: spend, quotas and the voice kill-switch are not being watched.',
                sprintf(
                    'The usual cause is a token mismatch: the "%s" entry in METRICS_TOKENS on the monitor must equal METRICS_TOKEN on the game\'s service.',
                    $project->gameId->value,
                ),
            ]);

            if ($this->announce($project, $alert, $now)) {
                $this->forgetStreak($project, $open);

                return;
            }
        }

        // Counted even when the mail just failed, so the retry on the next poll reports how long
        // it has really been rather than starting over.
        $this->rememberStreak($project, $open, $failures, $since);
    }

    /**
     * @param array<string, float> $previousGauges
     * @param list<string>         $open           alarm keys raised before this reading
     *
     * @return list<MetricAlert>
     */
    private function evaluate(
        Project $project,
        GameReading $reading,
        array $previousGauges,
        \DateTimeImmutable $now,
        array $open,
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
                    ...(self::ADVICE[$name] ?? []),
                ]);
            }
        }

        $lastDay = $this->dayLimits === [] ? [] : $this->metrics->totalsBetween(
            $project->gameId,
            $now->modify(sprintf('-%d hours', self::DAY_WINDOW_HOURS)),
            null,
        );

        foreach ($this->dayLimits as $name => $limit) {
            $grown = $lastDay[$name] ?? 0.0;

            if ($grown > $limit) {
                $alerts[] = new MetricAlert($project, 'day:'.$name, sprintf('%s is over its daily ceiling', $name), [
                    sprintf('%s grew by %s in the last 24 hours.', $name, self::number($grown)),
                    sprintf('The configured ceiling is %s per day.', self::number($limit)),
                    ...(self::ADVICE[$name] ?? []),
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
        if (in_array(self::PLAYERS_GONE, $open, true)) {
            // Opened on the fall, then held as a state until somebody is back. Judged on the
            // transition alone, the poll after the fall (zero to zero) closed it and mailed that
            // players were online again while nobody was. A reading without the gauge says
            // nothing about players either way, so it does not clear it.
            if ($playersNow === null || $playersNow <= 0.0) {
                $alerts[] = new MetricAlert($project, self::PLAYERS_GONE, 'nobody is online', [
                    'Players online is still zero.',
                ]);
            }
        } elseif ($playersNow !== null && $playersNow <= 0.0 && $playersBefore !== null && $playersBefore > 0.0) {
            // Opening still takes a fall from above zero: a game nobody has played yet is
            // unreleased, not abandoned.
            $alerts[] = new MetricAlert($project, self::PLAYERS_GONE, 'nobody is online', [
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
            str_starts_with($key, 'day:') => sprintf('%s is back under its daily ceiling', substr($key, 4)),
            $key === 'quiet' => 'events are being counted again',
            $key === self::PLAYERS_GONE => 'players are online again',
            $key === self::UNREADABLE => 'the game\'s counters are being read again',
            $key === 'storage.memory' => 'counters are being stored properly again',
            // An alarm key this version no longer raises. Closing it quietly would leave the
            // operator with an unexplained warning as the last thing they heard.
            default => sprintf('the "%s" warning no longer applies', $key),
        };

        return (new MetricAlert($project, $key, $summary))->cleared();
    }

    /**
     * @return bool whether the mail went out, and so whether the state changed
     */
    private function announce(Project $project, MetricAlert $alert, \DateTimeImmutable $now): bool
    {
        // State changes only after the mail is away. Recording an alarm that was never
        // delivered would silence it for good, which is the one outcome worse than a duplicate.
        if (!$this->send($alert)) {
            return false;
        }

        if ($alert->cleared) {
            $this->alarms->close($project->gameId, $alert->key);

            return true;
        }

        $this->alarms->open($project->gameId, $alert->key, $now);

        return true;
    }

    /**
     * How many scrapes in a row have failed so far, and when the first of them did.
     *
     * @param array<array-key, \DateTimeImmutable> $raised
     *
     * @return array{int, ?\DateTimeImmutable}
     */
    private static function streak(array $raised): array
    {
        $failures = 0;
        $since = null;
        foreach ($raised as $key => $openedAt) {
            $key = (string) $key;
            if (!str_starts_with($key, self::UNREADABLE_STREAK)) {
                continue;
            }

            // The highest count wins, so a row left behind by a half-finished update overstates
            // the streak by one rather than resetting it.
            $failures = max($failures, (int) substr($key, strlen(self::UNREADABLE_STREAK)));
            $since = $since === null || $openedAt < $since ? $openedAt : $since;
        }

        return [$failures, $since];
    }

    /**
     * One row holds the count, opened at the time of the first failure, so the mail can say how
     * long it has been. The new row goes in before the old one comes out: a failure between the
     * two leaves a count one too high rather than none at all.
     *
     * @param list<string> $open
     */
    private function rememberStreak(Project $project, array $open, int $failures, \DateTimeImmutable $since): void
    {
        $current = self::UNREADABLE_STREAK.$failures;
        $this->alarms->open($project->gameId, $current, $since);
        $this->forgetStreak($project, $open, $current);
    }

    /**
     * @param list<string> $open
     */
    private function forgetStreak(Project $project, array $open, ?string $keep = null): void
    {
        foreach ($open as $key) {
            if (str_starts_with($key, self::UNREADABLE_STREAK) && $key !== $keep) {
                $this->alarms->close($project->gameId, $key);
            }
        }
    }

    /**
     * @param array<array-key, \DateTimeImmutable> $raised
     *
     * @return list<string>
     */
    private static function keysOf(array $raised): array
    {
        // A key that looks like a number comes back from an array as an int, and every
        // comparison made with these is strict.
        return array_map(static fn (int|string $key): string => (string) $key, array_keys($raised));
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
