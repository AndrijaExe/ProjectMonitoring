<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\GameMetricSource;
use App\Model\MetricsUnavailable;
use App\Model\Project;

final class FakeGameMetricSource implements GameMetricSource
{
    /** @var array<string, float> */
    private array $counters = [];

    private ?MetricsUnavailable $failure = null;

    /**
     * @param array<string, float> $counters
     */
    public function willReturn(array $counters): void
    {
        $this->counters = $counters;
        $this->failure = null;
    }

    public function willFail(string $reason): void
    {
        $this->failure = new MetricsUnavailable($reason);
    }

    public function read(Project $project): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->counters;
    }
}
