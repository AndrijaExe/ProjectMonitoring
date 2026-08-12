<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class MonitoringOverview
{
    /**
     * @param list<ProjectCard> $projects
     */
    public function __construct(public array $projects)
    {
    }
}
