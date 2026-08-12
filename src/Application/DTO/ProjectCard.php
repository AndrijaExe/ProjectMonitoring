<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class ProjectCard
{
    /**
     * @param array<string, float> $recentMetricTotals
     */
    public function __construct(
        public string $gameId,
        public string $displayName,
        public string $healthUrl,
        public string $readyUrl,
        public ?string $healthStatus,
        public ?int $healthHttpCode,
        public ?int $healthLatencyMs,
        public ?string $healthCheckedAt,
        public ?string $readyStatus,
        public ?int $readyHttpCode,
        public ?int $readyLatencyMs,
        public ?string $readyCheckedAt,
        public int $recentMetricCount,
        public array $recentMetricTotals,
    ) {
    }
}
