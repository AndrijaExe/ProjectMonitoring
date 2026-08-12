<?php

declare(strict_types=1);

namespace App\Model;

final readonly class IngestToken
{
    public static function hash(string $token): string
    {
        $token = trim($token);
        if (strlen($token) < 16) {
            throw new \InvalidArgumentException('Ingest token must be at least 16 characters.');
        }

        return hash('sha256', $token);
    }

    public static function matches(string $plainToken, string $expectedHash): bool
    {
        if ($plainToken === '' || $expectedHash === '') {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $plainToken));
    }
}
