<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ProbeResult
{
    public function __construct(
        public HealthStatus $status,
        public int $httpCode,
        public int $latencyMs,
        public ?string $error = null,
    ) {
    }

    public static function fromHttp(HealthEndpoint $endpoint, int $httpCode, int $latencyMs, ?string $jsonStatus): self
    {
        if ($httpCode === 0) {
            return new self(HealthStatus::Down, 0, $latencyMs, 'No HTTP response.');
        }

        $normalized = strtolower(trim((string) $jsonStatus));

        if ($endpoint === HealthEndpoint::Health) {
            if ($httpCode === 200 && ($normalized === '' || $normalized === 'ok')) {
                return new self(HealthStatus::Ok, $httpCode, $latencyMs);
            }

            return new self(HealthStatus::Error, $httpCode, $latencyMs, 'Health check did not return ok.');
        }

        if ($httpCode === 200 && ($normalized === '' || $normalized === 'ready')) {
            return new self(HealthStatus::Ready, $httpCode, $latencyMs);
        }

        if ($httpCode === 503 || $normalized === 'not_ready') {
            return new self(HealthStatus::NotReady, $httpCode, $latencyMs);
        }

        return new self(HealthStatus::Error, $httpCode, $latencyMs, 'Ready check failed.');
    }

    public static function down(int $latencyMs, string $error): self
    {
        return new self(HealthStatus::Down, 0, $latencyMs, $error);
    }
}
