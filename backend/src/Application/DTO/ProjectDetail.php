<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class ProjectDetail
{
    /**
     * @param list<array<string, mixed>> $healthHistory
     * @param list<array<string, mixed>> $recentMetrics
     * @param array<string, mixed>       $usage
     */
    public function __construct(
        public ProjectCard $card,
        public array $healthHistory,
        public array $recentMetrics,
        public array $usage = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->card->toArray(),
            'health_history' => $this->healthHistory,
            'recent_metrics' => $this->recentMetrics,
            'usage' => $this->usage,
        ];
    }
}
