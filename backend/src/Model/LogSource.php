<?php

declare(strict_types=1);

namespace App\Model;

interface LogSource
{
    /**
     * True when the source has the credentials it needs. The console shows an explanation
     * instead of an error when it does not, because an unconfigured integration is a setup
     * step, not a failure.
     */
    public function isConfigured(): bool;

    /**
     * @return list<LogLine> newest first
     *
     * @throws LogsUnavailable when the upstream cannot be reached or refuses the query
     */
    public function recent(Project $project, LogFilter $filter): array;
}
