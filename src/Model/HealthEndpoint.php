<?php

declare(strict_types=1);

namespace App\Model;

enum HealthEndpoint: string
{
    case Health = 'health';
    case Ready = 'ready';
}
