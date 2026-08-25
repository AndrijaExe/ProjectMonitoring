<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\Notification;
use App\Model\Project;

/**
 * A notification with whatever subject and body a test needs, for the cases that are about the
 * channel rather than about what raised the alert.
 */
final readonly class FakeNotification implements Notification
{
    public function __construct(
        public Project $project,
        private string $subject = 'Something happened',
        private string $body = 'A body.',
    ) {
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }
}
