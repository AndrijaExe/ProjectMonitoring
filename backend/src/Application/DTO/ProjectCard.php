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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'game_id' => $this->gameId,
            'display_name' => $this->displayName,
            'health_url' => $this->healthUrl,
            'ready_url' => $this->readyUrl,
            'health' => [
                'status' => $this->healthStatus,
                'http_code' => $this->healthHttpCode,
                'latency_ms' => $this->healthLatencyMs,
                'checked_at' => $this->healthCheckedAt,
            ],
            'ready' => [
                'status' => $this->readyStatus,
                'http_code' => $this->readyHttpCode,
                'latency_ms' => $this->readyLatencyMs,
                'checked_at' => $this->readyCheckedAt,
            ],
            'metrics' => [
                'count_24h' => $this->recentMetricCount,
                // Cast keeps the JSON shape an object even when no metric arrived yet.
                'totals_24h' => (object) $this->recentMetricTotals,
            ],
        ];
    }
}
