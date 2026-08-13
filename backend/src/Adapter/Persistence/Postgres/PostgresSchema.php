<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

final class PostgresSchema
{
    private const STATEMENTS = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS projects (
            game_id TEXT PRIMARY KEY,
            display_name TEXT NOT NULL,
            health_url TEXT NOT NULL,
            ready_url TEXT NOT NULL,
            ingest_token_hash TEXT NOT NULL
        )
        SQL,
        <<<'SQL'
        ALTER TABLE projects ADD COLUMN IF NOT EXISTS metrics_url TEXT NULL
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS health_snapshots (
            id BIGSERIAL PRIMARY KEY,
            game_id TEXT NOT NULL REFERENCES projects (game_id) ON DELETE CASCADE,
            endpoint TEXT NOT NULL,
            status TEXT NOT NULL,
            http_code INTEGER NOT NULL,
            latency_ms INTEGER NOT NULL,
            error TEXT NULL,
            checked_at TIMESTAMPTZ NOT NULL
        )
        SQL,
        <<<'SQL'
        CREATE INDEX IF NOT EXISTS health_snapshots_latest
            ON health_snapshots (game_id, endpoint, checked_at DESC)
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS metric_samples (
            id BIGSERIAL PRIMARY KEY,
            game_id TEXT NOT NULL REFERENCES projects (game_id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            value DOUBLE PRECISION NOT NULL,
            tags JSONB NOT NULL DEFAULT '{}'::jsonb,
            recorded_at TIMESTAMPTZ NOT NULL
        )
        SQL,
        <<<'SQL'
        CREATE INDEX IF NOT EXISTS metric_samples_window
            ON metric_samples (game_id, recorded_at DESC)
        SQL,
        // One row per raised alarm. A rate or a level is simply true again on the next poll,
        // so without this the same warning would be mailed every hour.
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS metric_alarms (
            game_id TEXT NOT NULL REFERENCES projects (game_id) ON DELETE CASCADE,
            alarm_key TEXT NOT NULL,
            opened_at TIMESTAMPTZ NOT NULL,
            PRIMARY KEY (game_id, alarm_key)
        )
        SQL,
    ];

    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function install(): void
    {
        $pdo = $this->connection->pdo();
        foreach (self::STATEMENTS as $statement) {
            $pdo->exec($statement);
        }
    }

    public function truncate(): void
    {
        $this->connection->pdo()->exec('TRUNCATE projects, health_snapshots, metric_samples, metric_alarms RESTART IDENTITY CASCADE');
    }
}
