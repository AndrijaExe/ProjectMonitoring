<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Log lines plus the name of whoever wrote them.
 *
 * The name travels with the lines because a panel of log output with no attribution invites
 * the reader to guess whose it is, and the guess is worth nothing when two services are
 * visible on the same screen.
 */
final readonly class LogPage
{
    /**
     * @param list<LogLine> $lines   newest first
     * @param int           $routine lines dropped as platform bookkeeping
     */
    public function __construct(
        public string $source,
        public array $lines,
        public int $routine = 0,
    ) {
    }

    /**
     * Says what was left out, because a viewer that quietly disagrees with the host's own log
     * page teaches its reader to distrust it.
     */
    public function note(): ?string
    {
        if ($this->routine === 0) {
            return null;
        }

        $checks = sprintf('%d routine platform health check%s', $this->routine, $this->routine === 1 ? '' : 's');

        return $this->lines === []
            ? sprintf('Nothing but %s in this window.', $checks)
            : sprintf('%s hidden.', ucfirst($checks));
    }
}
