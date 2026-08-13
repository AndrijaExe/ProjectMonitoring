<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * The board, plus when it was last written to.
 *
 * A monitor's worst failure mode is silence: a stopped schedule, an expired token or a suspended
 * instance leaves the last known statuses on screen, and a board of green from yesterday reads
 * exactly like a board of green from a minute ago. The age of the newest probe is therefore part
 * of the answer rather than something the reader is trusted to notice.
 */
final readonly class MonitoringOverview
{
    /**
     * @param list<ProjectCard> $projects
     * @param ?string           $lastProbeAt       newest probe across every project, ATOM
     * @param int               $staleAfterMinutes 0 turns the check off
     */
    public function __construct(
        public array $projects,
        public ?string $lastProbeAt = null,
        public bool $stale = false,
        public int $staleAfterMinutes = 0,
    ) {
    }

    /**
     * @return array{projects: list<array<string, mixed>>, last_probe_at: ?string, stale: bool, stale_after_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'projects' => array_map(static fn (ProjectCard $card): array => $card->toArray(), $this->projects),
            'last_probe_at' => $this->lastProbeAt,
            'stale' => $this->stale,
            'stale_after_minutes' => $this->staleAfterMinutes,
        ];
    }
}
