<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One answer from a game's metrics endpoint.
 *
 * Counters and gauges are kept apart because they are read differently: a counter only means
 * something as the difference between two readings, a gauge only as its latest value. Adding
 * up "players online" across a day would produce a number with no meaning at all.
 */
final readonly class GameReading
{
    /**
     * @param array<string, float> $counters lifetime totals
     * @param array<string, float> $gauges   levels at the moment of reading
     * @param string               $storage  where the game keeps them: "redis", "memory", ""
     */
    public function __construct(
        public array $counters,
        public array $gauges,
        public string $storage = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->counters === [] && $this->gauges === [];
    }
}
