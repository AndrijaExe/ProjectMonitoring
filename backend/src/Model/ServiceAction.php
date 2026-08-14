<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The three things an operator can do to a running service from the console.
 *
 * Deliberately three, and deliberately not "delete", "scale" or "edit environment". Every button
 * here is one the host's own dashboard already offers, and each of them is reversible by pressing
 * another one; the console is a faster route to them at 3am, not a second control plane.
 */
enum ServiceAction: string
{
    /** Build the current commit again and deploy it. */
    case Rebuild = 'rebuild';

    /** Take the service down and keep it down, until someone starts it. */
    case Stop = 'stop';

    /** Bring a stopped service back. */
    case Start = 'start';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \InvalidArgumentException('Unknown action. Use rebuild, stop or start.');
    }

    /**
     * Written for the audit line and the mail, in the past tense of what was asked for.
     */
    public function asked(): string
    {
        return match ($this) {
            self::Rebuild => 'rebuild',
            self::Stop => 'stop',
            self::Start => 'start',
        };
    }
}
