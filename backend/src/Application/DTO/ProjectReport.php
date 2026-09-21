<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class ProjectReport
{
    /**
     * @param list<array<string, mixed>> $buckets oldest first; the last one is still filling
     */
    public function __construct(
        public string $gameId,
        public string $period,
        public array $buckets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'game_id' => $this->gameId,
            'period' => $this->period,
            'timezone' => 'UTC',
            'buckets' => $this->buckets,
        ];
    }
}
