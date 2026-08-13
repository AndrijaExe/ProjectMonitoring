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
     * @param list<LogLine> $lines newest first
     */
    public function __construct(
        public string $source,
        public array $lines,
    ) {
    }
}
