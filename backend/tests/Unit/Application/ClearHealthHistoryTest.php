<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\ClearHealthHistory;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\InMemoryHealthSnapshotStore;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ClearHealthHistoryTest extends TestCase
{
    public function testItRemovesOnlyTheChosenProjectAndLeavesATrace(): void
    {
        $snapshots = new InMemoryHealthSnapshotStore();
        $snapshots->record($this->snapshot('loop9'));
        $snapshots->record($this->snapshot('loop9'));
        $snapshots->record($this->snapshot('other'));

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level.': '.$message.' '.json_encode($context);
            }
        };

        $result = (new ClearHealthHistory($this->projects(), $snapshots, $logger))->execute('loop9');

        self::assertSame(['cleared' => 2], $result);
        self::assertCount(1, $snapshots->recent(GameId::fromString('other')));
        self::assertCount(0, $snapshots->recent(GameId::fromString('loop9')));

        // Erasing evidence quietly is how a monitor starts lying.
        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('warning: Health history cleared', $logger->lines[0]);
        self::assertStringContainsString('"rows":2', $logger->lines[0]);
    }

    public function testAnUnknownProjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClearHealthHistory($this->projects(), new InMemoryHealthSnapshotStore(), new \Psr\Log\NullLogger()))
            ->execute('nope');
    }

    private function projects(): InMemoryProjectRepository
    {
        $projects = new InMemoryProjectRepository();
        foreach (['loop9' => 'Loop 9', 'other' => 'Other'] as $id => $name) {
            $projects->save(new Project(
                GameId::fromString($id),
                $name,
                'https://'.$id.'.onrender.com/healthz',
                'https://'.$id.'.onrender.com/readyz',
                IngestToken::hash('dev-'.$id.'-ingest-token'),
            ));
        }

        return $projects;
    }

    private function snapshot(string $gameId): HealthSnapshot
    {
        return new HealthSnapshot(
            GameId::fromString($gameId),
            HealthEndpoint::Health,
            HealthStatus::Ok,
            200,
            20,
            new \DateTimeImmutable(),
        );
    }
}
