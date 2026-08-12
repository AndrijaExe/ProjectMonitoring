<?php

declare(strict_types=1);

namespace App\Model;

final readonly class MetricBatch
{
    public const MAX_SAMPLES = 50;

    /**
     * @param list<MetricSample> $samples
     */
    public function __construct(public array $samples)
    {
        if ($samples === []) {
            throw new \InvalidArgumentException('Metric batch cannot be empty.');
        }

        if (count($samples) > self::MAX_SAMPLES) {
            throw new \InvalidArgumentException(sprintf('Metric batch cannot exceed %d samples.', self::MAX_SAMPLES));
        }
    }
}
