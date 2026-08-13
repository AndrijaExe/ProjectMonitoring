<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\AlarmStateStore;
use App\Model\GameId;

final class InMemoryAlarmStateStore implements AlarmStateStore
{
    /** @var array<string, array<string, \DateTimeImmutable>> */
    private array $open = [];

    public function raised(GameId $gameId): array
    {
        $raised = $this->open[$gameId->value] ?? [];
        ksort($raised);

        return $raised;
    }

    public function open(GameId $gameId, string $key, \DateTimeImmutable $at): void
    {
        $this->open[$gameId->value][$key] ??= $at;
    }

    public function close(GameId $gameId, string $key): void
    {
        unset($this->open[$gameId->value][$key]);
    }
}
