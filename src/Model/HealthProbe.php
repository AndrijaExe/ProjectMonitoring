<?php

declare(strict_types=1);

namespace App\Model;

interface HealthProbe
{
    public function probe(string $url, HealthEndpoint $endpoint): ProbeResult;
}
