<?php

declare(strict_types=1);

namespace App\Model;

interface ProjectRepository
{
    public function save(Project $project): void;

    public function findByGameId(GameId $gameId): ?Project;

    /**
     * @return list<Project>
     */
    public function all(): array;
}
