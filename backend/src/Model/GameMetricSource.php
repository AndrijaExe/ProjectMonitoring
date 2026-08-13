<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Reads a game's own counters.
 *
 * Pull, not push. A game that reports on a timer needs a scheduler and a copy of this API's
 * credentials, to answer a question this API is already awake to ask on its own schedule.
 */
interface GameMetricSource
{
    /**
     * @throws MetricsUnavailable
     */
    public function read(Project $project): GameReading;
}
