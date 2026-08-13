<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\GameMetricSource;
use App\Model\GameReading;
use App\Model\MetricsUnavailable;
use App\Model\Project;

final class FakeGameMetricSource implements GameMetricSource
{
    /** @var array<string, float> */
    private array $counters = [];

    /** @var array<string, float> */
    private array $gauges = [];

    private string $storage = 'redis';

    private ?MetricsUnavailable $failure = null;

    /**
     * @param array<string, float> $counters
     * @param array<string, float> $gauges
     */
    public function willReturn(array $counters, array $gauges = [], string $storage = 'redis'): void
    {
        $this->counters = $counters;
        $this->gauges = $gauges;
        $this->storage = $storage;
        $this->failure = null;
    }

    public function willFail(string $reason): void
    {
        $this->failure = new MetricsUnavailable($reason);
    }

    public function read(Project $project): GameReading
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new GameReading($this->counters, $this->gauges, $this->storage);
    }
}
