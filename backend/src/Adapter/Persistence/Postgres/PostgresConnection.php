<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PostgresConnection
{
    private ?\PDO $pdo = null;

    public function __construct(
        #[Autowire('%env(DATABASE_URL)%')]
        private readonly string $databaseUrl,
    ) {
    }

    public function pdo(): \PDO
    {
        return $this->pdo ??= new \PDO($this->dsn(), $this->user(), $this->password(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Wraps the callback in a transaction so a metric batch either lands whole or not at all.
     */
    public function transactional(callable $work): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $work($pdo);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    private function dsn(): string
    {
        $parts = $this->parse();
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'], $parts['dbname']);

        if ($parts['sslmode'] !== null) {
            $dsn .= ';sslmode='.$parts['sslmode'];
        }

        return $dsn;
    }

    private function user(): string
    {
        return $this->parse()['user'];
    }

    private function password(): string
    {
        return $this->parse()['password'];
    }

    /**
     * @return array{host: string, port: int, dbname: string, user: string, password: string, sslmode: ?string}
     */
    private function parse(): array
    {
        $url = $this->databaseUrl;
        if ($url === '') {
            throw new \RuntimeException('DATABASE_URL is not configured.');
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new \RuntimeException('DATABASE_URL is not a valid connection string.');
        }

        $sslmode = null;
        if (isset($parsed['query'])) {
            $query = [];
            parse_str($parsed['query'], $query);
            if (isset($query['sslmode']) && is_string($query['sslmode'])) {
                $sslmode = $query['sslmode'];
            }
        }

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 5432,
            'dbname' => ltrim($parsed['path'] ?? '', '/') ?: 'postgres',
            'user' => rawurldecode($parsed['user'] ?? 'postgres'),
            'password' => rawurldecode($parsed['pass'] ?? ''),
            'sslmode' => $sslmode,
        ];
    }
}
