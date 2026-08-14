<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Reading and changing the run state of a project's service at its host.
 *
 * The rest of the console only observes. This is the one port that acts, which is why it is small
 * and why every method here maps to a single, reversible operation.
 */
interface ServiceControl
{
    public function isConfigured(): bool;

    /**
     * @throws RenderUnavailable when the host cannot be reached or does not know the service
     */
    public function state(Project $project): ServiceState;

    /**
     * @throws RenderUnavailable
     */
    public function apply(Project $project, ServiceAction $action): void;
}
