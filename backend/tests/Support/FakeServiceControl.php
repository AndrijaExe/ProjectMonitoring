<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\Project;
use App\Model\RenderUnavailable;
use App\Model\ServiceAction;
use App\Model\ServiceControl;
use App\Model\ServiceState;

final class FakeServiceControl implements ServiceControl
{
    /** @var list<ServiceAction> */
    public array $applied = [];

    public function __construct(
        private readonly bool $configured = true,
        private ServiceState $state = new ServiceState('loop9-backend', false, 'live'),
        private readonly ?RenderUnavailable $failure = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function state(Project $project): ServiceState
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->state;
    }

    public function apply(Project $project, ServiceAction $action): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->applied[] = $action;

        // The host does not change the state instantly, but a test that never changed it could
        // not tell a working button from one that only logged.
        $this->state = match ($action) {
            ServiceAction::Stop => new ServiceState($this->state->name, true),
            ServiceAction::Start => new ServiceState($this->state->name, false, 'live'),
            ServiceAction::Rebuild => new ServiceState($this->state->name, false, 'build_in_progress'),
        };
    }
}
