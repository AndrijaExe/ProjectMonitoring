<?php

declare(strict_types=1);

namespace App\Adapter\Persistence\Postgres;

use App\Model\GameId;
use App\Model\MetricBatch;
use App\Model\MetricSample;
use App\Model\MetricStore;

final class PdoMetricStore implements MetricStore
{
    public function __construct(private readonly PostgresConnection $connection)
    {
    }

    public function recordBatch(MetricBatch $batch): void
    {
        $this->connection->transactional(static function (\PDO $pdo) use ($batch): void {
            $statement = $pdo->prepare(<<<'SQL'
                INSERT INTO metric_samples (game_id, name, value, tags, recorded_at)
                VALUES (:game_id, :name, :value, :tags, :recorded_at)
                SQL);

            foreach ($batch->samples as $sample) {
                $statement->execute([
                    'game_id' => $sample->gameId->value,
                    'name' => $sample->name,
                    'value' => $sample->value,
                    'tags' => json_encode($sample->tags, JSON_THROW_ON_ERROR),
                    'recorded_at' => $sample->recordedAt->format(\DateTimeInterface::ATOM),
                ]);
            }
        });
    }

    public function countSince(GameId $gameId, \DateTimeImmutable $since): int
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT COUNT(*) FROM metric_samples
            WHERE game_id = :game_id AND recorded_at >= :since
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'since' => $since->format(\DateTimeInterface::ATOM),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function totalsSince(GameId $gameId, \DateTimeImmutable $since): array
    {
        // Two kinds of series share this table. A pushed sample is an event with a value, so
        // the window total is their sum. A scraped counter is a lifetime reading, so the window
        // total is how much it grew — summing those would add yesterday to itself all day.
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            WITH windowed AS (
                SELECT
                    name,
                    COALESCE(MAX(tags ->> 'kind'), '') AS kind,
                    SUM(value) AS total,
                    (array_agg(value ORDER BY recorded_at ASC, id ASC))[1] AS first_value,
                    (array_agg(value ORDER BY recorded_at DESC, id DESC))[1] AS last_value
                FROM metric_samples
                WHERE game_id = :game_id
                  AND recorded_at >= :since
                  AND COALESCE(tags ->> 'kind', '') <> 'gauge'
                GROUP BY name
            ),
            before AS (
                SELECT DISTINCT ON (name) name, value
                FROM metric_samples
                WHERE game_id = :game_id AND recorded_at < :since
                ORDER BY name, recorded_at DESC, id DESC
            )
            SELECT w.name, w.kind, w.total, w.last_value, COALESCE(b.value, w.first_value) AS baseline
            FROM windowed w
            LEFT JOIN before b ON b.name = w.name
            ORDER BY w.name ASC
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'since' => $since->format(\DateTimeInterface::ATOM),
        ]);

        $totals = [];
        foreach ($statement->fetchAll() as $row) {
            $totals[(string) $row['name']] = ((string) $row['kind']) === 'counter'
                ? self::increase((float) $row['baseline'], (float) $row['last_value'])
                : (float) $row['total'];
        }

        return $totals;
    }

    public function latestGauges(GameId $gameId, \DateTimeImmutable $since): array
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT DISTINCT ON (name) name, value
            FROM metric_samples
            WHERE game_id = :game_id
              AND recorded_at >= :since
              AND tags ->> 'kind' = 'gauge'
            ORDER BY name, recorded_at DESC, id DESC
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'since' => $since->format(\DateTimeInterface::ATOM),
        ]);

        $gauges = [];
        foreach ($statement->fetchAll() as $row) {
            $gauges[(string) $row['name']] = (float) $row['value'];
        }

        return $gauges;
    }

    /**
     * Growth from where the counter stood when the window opened.
     *
     * The baseline is the last reading before the window when there is one, so growth between
     * that reading and the first one inside is not lost. A series first seen inside the window
     * falls back to its own first reading, which understates: claiming its lifetime total
     * happened today would invent activity, and a monitor inventing activity is worse than one
     * admitting it arrived late.
     *
     * A counter reading lower than its baseline means the store behind it was cleared, so the
     * newest reading is the whole of what has been counted since.
     */
    private static function increase(float $baseline, float $last): float
    {
        return $last >= $baseline ? $last - $baseline : $last;
    }

    public function recent(GameId $gameId, int $limit = 50): array
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT * FROM metric_samples
            WHERE game_id = :game_id
            ORDER BY recorded_at DESC, id DESC
            LIMIT :limit
            SQL);
        $statement->bindValue('game_id', $gameId->value);
        $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MetricSample
    {
        $tags = [];
        $decoded = json_decode((string) ($row['tags'] ?? '{}'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $tags[(string) $key] = (string) $value;
            }
        }

        return new MetricSample(
            GameId::fromString((string) $row['game_id']),
            (string) $row['name'],
            (float) $row['value'],
            $tags,
            new \DateTimeImmutable((string) $row['recorded_at']),
        );
    }
}
