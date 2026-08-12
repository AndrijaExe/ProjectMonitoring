<?php

declare(strict_types=1);

namespace App\Model;

final readonly class HealthSnapshot
{
    public function __construct(
        public GameId $gameId,
        public HealthEndpoint $endpoint,
        public HealthStatus $status,
        public int $httpCode,
        public int $latencyMs,
        public \DateTimeImmutable $checkedAt,
        public ?string $error = null,
    ) {
        if ($httpCode < 0 || $httpCode > 599) {
            throw new \InvalidArgumentException('HTTP code is out of range.');
        }

        if ($latencyMs < 0) {
            throw new \InvalidArgumentException('Latency cannot be negative.');
        }
    }
}
