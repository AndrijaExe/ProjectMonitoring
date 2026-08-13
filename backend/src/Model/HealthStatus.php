<?php

declare(strict_types=1);

namespace App\Model;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Ready = 'ready';
    case NotReady = 'not_ready';
    case Down = 'down';
    case Timeout = 'timeout';
    case Throttled = 'throttled';
    case Error = 'error';

    public function isHealthy(): bool
    {
        return $this === self::Ok || $this === self::Ready;
    }
}
