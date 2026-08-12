<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\HealthEndpoint;
use App\Model\HealthProbe;
use App\Model\HealthStatus;
use App\Model\ProbeResult;

final class FakeHealthProbe implements HealthProbe
{
    /** @var array<string, ProbeResult> */
    private array $results = [];

    public function willReturn(string $url, ProbeResult $result): void
    {
        $this->results[$url] = $result;
    }

    public function probe(string $url, HealthEndpoint $endpoint): ProbeResult
    {
        return $this->results[$url] ?? new ProbeResult(HealthStatus::Down, 0, 1, 'No fake result.');
    }
}
