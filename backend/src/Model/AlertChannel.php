<?php

declare(strict_types=1);

namespace App\Model;

interface AlertChannel
{
    public function isConfigured(): bool;

    /**
     * @throws \RuntimeException when the alert could not be handed over
     */
    public function send(Notification $alert): void;
}
