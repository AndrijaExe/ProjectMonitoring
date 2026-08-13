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

    /**
     * A timeout or a rate limit says we could not see the target, not that the target is
     * broken. Alerting on those would page somebody for a sleeping free instance, and
     * treating them as recoveries would hide a real outage that started while we were blind.
     */
    public function isConclusive(): bool
    {
        return $this !== self::Timeout && $this !== self::Throttled;
    }
}
