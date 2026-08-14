<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What the host says about a service right now.
 *
 * A probe answers "does it reply", which is not the same question as "is it meant to be running".
 * A stopped service and a crashed one both fail every probe, and only one of them is a reason to
 * get out of bed; the difference is only visible from the host, so it is read from there.
 */
final readonly class ServiceState
{
    /** Deploy statuses that mean the host is mid-change and the buttons should wait. */
    private const IN_FLIGHT = [
        'created',
        'queued',
        'build_in_progress',
        'update_in_progress',
        'pre_deploy_in_progress',
    ];

    private const FAILED = [
        'build_failed',
        'update_failed',
        'pre_deploy_failed',
    ];

    public function __construct(
        public string $name,
        public bool $stopped,
        public string $deployStatus = '',
        public ?\DateTimeImmutable $deployAt = null,
        public string $commit = '',
    ) {
    }

    public function isBusy(): bool
    {
        return in_array($this->deployStatus, self::IN_FLIGHT, true);
    }

    public function deployFailed(): bool
    {
        return in_array($this->deployStatus, self::FAILED, true);
    }

    /**
     * One line an operator can act on, rather than a status word they have to interpret.
     */
    public function summary(): string
    {
        if ($this->stopped) {
            return 'Stopped. It will answer nothing until someone starts it.';
        }

        if ($this->isBusy()) {
            return 'Deploying now. The old instance keeps serving until the new one is up.';
        }

        if ($this->deployFailed()) {
            return 'The last deploy failed, so the previous build is still what is running.';
        }

        return 'Running.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'stopped' => $this->stopped,
            'busy' => $this->isBusy(),
            'failed' => $this->deployFailed(),
            'deploy_status' => $this->deployStatus,
            'deploy_at' => $this->deployAt?->format(\DateTimeInterface::ATOM),
            'commit' => $this->commit,
            'summary' => $this->summary(),
        ];
    }
}
