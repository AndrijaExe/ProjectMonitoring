<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use App\Model\DeviceTokenStore;

final class PdoDeviceTokenStore implements DeviceTokenStore
{
    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function all(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT token FROM device_tokens ORDER BY registered_at ASC',
        );

        $tokens = [];
        foreach ($statement->fetchAll() as $row) {
            $tokens[] = (string) $row['token'];
        }

        return $tokens;
    }

    public function remember(string $token, string $platform, \DateTimeImmutable $at): void
    {
        // The first registration is the one that dates the device; a later sign-in only says
        // the route is still live.
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            INSERT INTO device_tokens (token, platform, registered_at, last_seen_at)
            VALUES (:token, :platform, :at, :at)
            ON CONFLICT (token) DO UPDATE SET
                platform = EXCLUDED.platform,
                last_seen_at = EXCLUDED.last_seen_at
            SQL);
        $statement->execute([
            'token' => $token,
            'platform' => $platform,
            'at' => $at->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function forget(string $token): void
    {
        $statement = $this->connection->pdo()->prepare('DELETE FROM device_tokens WHERE token = :token');
        $statement->execute(['token' => $token]);
    }
}
