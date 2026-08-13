<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
        $this->contexts[] = $context;
    }
}
