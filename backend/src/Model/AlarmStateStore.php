<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Remembers which alarms are already raised.
 *
 * Health alerts can work this out from the probe history, because a probe records a status
 * every time. An alarm about a rate or a level has no such trail: the condition is simply true
 * again on the next poll, and without a memory of having said so the operator would get the
 * same mail every hour until they stopped reading them.
 */
interface AlarmStateStore
{
    /**
     * @return list<string> keys currently raised for this project
     */
    public function openKeys(GameId $gameId): array;

    public function open(GameId $gameId, string $key, \DateTimeImmutable $at): void;

    public function close(GameId $gameId, string $key): void;
}
