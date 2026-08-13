<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\LogFilter;
use App\Model\LogLine;
use App\Model\LogSource;
use App\Model\LogsUnavailable;
use App\Model\Project;

final class FakeLogSource implements LogSource
{
    /**
     * @var list<LogLine>
     */
    private array $lines = [];

    private ?LogsUnavailable $failure = null;

    public function __construct(private bool $configured = true)
    {
    }

    public function willReturn(LogLine ...$lines): void
    {
        $this->lines = array_values($lines);
    }

    public function willFail(string $message): void
    {
        $this->failure = new LogsUnavailable($message);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function recent(Project $project, LogFilter $filter): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->lines;
    }
}
