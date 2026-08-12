<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Adapter\Persistence\Postgres\PdoProjectRepository;
use App\Adapter\Persistence\Postgres\PostgresConnection;
use App\Adapter\Persistence\Postgres\PostgresSchema;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;

final class TestDatabase
{
    private static ?PostgresConnection $connection = null;

    public static function connection(): PostgresConnection
    {
        return self::$connection ??= new PostgresConnection(self::url());
    }

    /**
     * Gives every test an empty schema with the seeded project the suite expects.
     */
    public static function reset(): void
    {
        $schema = new PostgresSchema(self::connection());

        try {
            $schema->install();
        } catch (\PDOException $exception) {
            self::fail($exception);
        }

        $schema->truncate();

        (new PdoProjectRepository(self::connection()))->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            (string) ($_SERVER['LOOP9_HEALTH_URL'] ?? 'http://127.0.0.1:9/healthz'),
            (string) ($_SERVER['LOOP9_READY_URL'] ?? 'http://127.0.0.1:9/readyz'),
            IngestToken::hash((string) ($_SERVER['LOOP9_INGEST_TOKEN'] ?? 'test-ingest-token-ok')),
        ));
    }

    private static function url(): string
    {
        $url = (string) ($_SERVER['DATABASE_URL'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('DATABASE_URL is missing from .env.test.');
        }

        return $url;
    }

    private static function fail(\PDOException $exception): never
    {
        throw new \RuntimeException(sprintf(
            "Cannot reach the test database at %s.\nStart it with: docker compose up -d db\nDriver said: %s",
            self::url(),
            $exception->getMessage(),
        ), 0, $exception);
    }
}
