<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\IngestMetricBatch;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\InMemoryMetricStore;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class IngestMetricBatchTest extends TestCase
{
    public function testStoresValidatedSamples(): void
    {
        $projects = $this->projects();
        $metrics = new InMemoryMetricStore();
        $useCase = new IngestMetricBatch($projects, $metrics);

        $batch = $useCase->execute('loop9', [
            ['name' => 'chat.requests', 'value' => 2, 'tags' => ['route' => 'chat']],
            ['name' => 'chat.errors', 'value' => 1],
        ]);

        self::assertCount(2, $batch->samples);
        self::assertSame(2, $metrics->countSince(GameId::fromString('loop9'), new \DateTimeImmutable('-1 hour')));
        self::assertSame(2.0, $metrics->totalsSince(GameId::fromString('loop9'), new \DateTimeImmutable('-1 hour'))['chat.requests']);
    }

    public function testRejectsUnknownProject(): void
    {
        $useCase = new IngestMetricBatch(new InMemoryProjectRepository(), new InMemoryMetricStore());

        $this->expectException(\InvalidArgumentException::class);
        $useCase->execute('loop9', [['name' => 'chat.requests', 'value' => 1]]);
    }

    public function testRejectsOversizedBatch(): void
    {
        $useCase = new IngestMetricBatch($this->projects(), new InMemoryMetricStore());
        $raw = [];
        for ($i = 0; $i < 51; ++$i) {
            $raw[] = ['name' => 'chat.requests', 'value' => 1];
        }

        $this->expectException(\InvalidArgumentException::class);
        $useCase->execute('loop9', $raw);
    }

    private function projects(): InMemoryProjectRepository
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));

        return $projects;
    }
}
