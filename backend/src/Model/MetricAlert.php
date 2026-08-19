<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Something the game's own numbers said, rather than something a probe measured.
 *
 * A service can answer every probe correctly while falling apart inside: every AI call failing
 * over to the fallback, a day passing without a single event, the player count dropping to
 * nobody. None of that is visible from outside, which is the whole reason the game publishes
 * counters at all.
 */
final readonly class MetricAlert implements Notification
{
    /**
     * @param string       $key     stable identity of the alarm, so it is mailed once and not hourly
     * @param list<string> $details lines of evidence, shown under the summary
     */
    public function __construct(
        public Project $project,
        public string $key,
        public string $summary,
        public array $details = [],
        public bool $cleared = false,
    ) {
    }

    /**
     * What a raised alarm is called on screen.
     *
     * The stored state is only a key, because that is all the deduplication needs. A console
     * showing "storage.memory" to its reader would be leaking a database value into a sentence.
     */
    public static function describe(string $key): string
    {
        return match (true) {
            $key === 'rate:chat.denied.player_daily' => 'a player hit the daily chat cap',
            $key === 'rate:chat.denied.player_monthly' => 'a player hit the monthly chat cap',
            $key === 'rate:abuse.watch' => 'a player is chatting far more than a normal run',
            str_starts_with($key, 'rate:') => sprintf('%s is rising faster than its limit', substr($key, 5)),
            $key === 'quiet' => 'nothing counted for a day',
            $key === 'players.gone' => 'nobody online',
            $key === 'storage.memory' => 'counters kept in memory',
            default => $key,
        };
    }

    public function cleared(): self
    {
        return new self(
            $this->project,
            $this->key,
            $this->summary,
            $this->details,
            cleared: true,
        );
    }

    public function subject(): string
    {
        return sprintf(
            '%s %s: %s',
            $this->project->displayName,
            $this->cleared ? 'recovered' : 'warning',
            $this->summary,
        );
    }

    public function body(): string
    {
        $lines = [
            sprintf('%s (%s)', $this->project->displayName, $this->project->gameId->value),
            sprintf('%s: %s', $this->cleared ? 'Cleared' : 'Warning', $this->summary),
        ];

        foreach ($this->details as $detail) {
            $lines[] = '  '.$detail;
        }

        // The probes are deliberately named as unaffected. An operator reading "AI fallbacks
        // rising" at 3am should not have to guess whether the service is also down.
        $lines[] = '';
        $lines[] = $this->cleared
            ? 'This came from the game\'s own counters. Nothing here says anything about its uptime.'
            : 'This came from the game\'s own counters, not from a probe. The service may well be answering normally.';

        return implode("\n", $lines);
    }
}
