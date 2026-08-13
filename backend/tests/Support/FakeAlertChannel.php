<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\Alert;
use App\Model\AlertChannel;

final class FakeAlertChannel implements AlertChannel
{
    /**
     * @var list<Alert>
     */
    public array $sent = [];

    private ?string $failure = null;

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function willFail(string $message): void
    {
        $this->failure = $message;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(Alert $alert): void
    {
        if ($this->failure !== null) {
            throw new \RuntimeException($this->failure);
        }

        $this->sent[] = $alert;
    }
}
