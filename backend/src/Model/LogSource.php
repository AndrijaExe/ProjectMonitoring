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
     * @throws RenderUnavailable when the upstream cannot be reached or refuses the query
     */
    public function recent(Project $project, LogFilter $filter): LogPage;

    /**
     * Reads a host's own logs, for the monitor looking at itself.
     *
     * @throws RenderUnavailable
     */
    public function recentForService(string $serviceId, LogFilter $filter): LogPage;
}
