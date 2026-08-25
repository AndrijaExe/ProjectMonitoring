<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\DeviceTokenStore;

final class InMemoryDeviceTokenStore implements DeviceTokenStore
{
    /** @var array<string, string> token => platform */
    public array $devices = [];

    /**
     * @param list<string> $tokens
     */
    public function __construct(array $tokens = [])
    {
        foreach ($tokens as $token) {
            $this->devices[$token] = 'android';
        }
    }

    public function all(): array
    {
        return array_keys($this->devices);
    }

    public function remember(string $token, string $platform, \DateTimeImmutable $at): void
    {
        $this->devices[$token] = $platform;
    }

    public function forget(string $token): void
    {
        unset($this->devices[$token]);
    }
}
