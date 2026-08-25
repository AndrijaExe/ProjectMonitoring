<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The phones that asked to be told when something breaks.
 *
 * A push token is not a secret and not an identity: it names a delivery route that the phone's
 * operating system can retire at any time. So this store is deliberately forgetful — anything
 * the push service reports as gone is dropped rather than retried, and re-registering is how a
 * phone comes back.
 */
interface DeviceTokenStore
{
    /**
     * @return list<string>
     */
    public function all(): array;

    /**
     * Registering twice is how the app says "still here" on every sign-in, so this must not
     * fail on a token it already knows.
     */
    public function remember(string $token, string $platform, \DateTimeImmutable $at): void;

    public function forget(string $token): void;
}
