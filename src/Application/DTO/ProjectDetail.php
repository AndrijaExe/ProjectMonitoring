<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class ProjectDetail
{
    /**
     * @param list<array<string, mixed>> $healthHistory
     * @param list<array<string, mixed>> $recentMetrics
     */
    public function __construct(
        public ProjectCard $card,
        public array $healthHistory,
        public array $recentMetrics,
    ) {
    }
}
