<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Anything worth mailing an operator about.
 *
 * A probe result and a counter behaving badly are different observations, and forcing the
 * second into the shape of the first would produce mails that name an endpoint and a status
 * code for something that has neither. The channel only needs a subject, a body, and which
 * project to link to.
 */
interface Notification
{
    public Project $project { get; }

    public function subject(): string;

    public function body(): string;
}
