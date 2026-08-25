<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\DeviceTokenStore;

/**
 * Takes a phone's word for where to reach it.
 *
 * The shape of the token is checked because a wrong one is silent otherwise: Expo would accept
 * the request, refuse the message, and the operator would wait for an alert that never had
 * anywhere to go. Better to refuse it while somebody is still looking at the screen.
 */
final class RegisterDevice
{
    /**
     * Expo has issued both spellings over the years and honours both, so both are let through.
     */
    private const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_+\/=.:-]+\]$/';

    private const PLATFORMS = ['android', 'ios'];

    public function __construct(private readonly DeviceTokenStore $devices)
    {
    }

    /**
     * @throws \InvalidArgumentException when the token or platform is not one Expo could use
     */
    public function remember(string $token, string $platform): void
    {
        $token = trim($token);
        $platform = strtolower(trim($platform));

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('That is not an Expo push token.');
        }

        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException(sprintf('Platform must be one of: %s.', implode(', ', self::PLATFORMS)));
        }

        $this->devices->remember($token, $platform, new \DateTimeImmutable());
    }

    /**
     * Signing out is not the moment to be strict: a token this store has never heard of is
     * already in the state the caller is asking for.
     */
    public function forget(string $token): void
    {
        $this->devices->forget(trim($token));
    }
}
