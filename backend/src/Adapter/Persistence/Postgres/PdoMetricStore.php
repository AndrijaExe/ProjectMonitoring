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
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT name, SUM(value) AS total FROM metric_samples
            WHERE game_id = :game_id AND recorded_at >= :since
            GROUP BY name
            ORDER BY name ASC
            SQL);
        $statement->execute([
            'game_id' => $gameId->value,
            'since' => $since->format(\DateTimeInterface::ATOM),
        ]);

        $totals = [];
        foreach ($statement->fetchAll() as $row) {
            $totals[(string) $row['name']] = (float) $row['total'];
        }

        return $totals;
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
