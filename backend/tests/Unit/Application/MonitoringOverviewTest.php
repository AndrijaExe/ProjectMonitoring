<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AnnounceHealthChange;
use App\Application\CollectGameMetrics;
use App\Application\GetMonitoringOverview;
use App\Application\RecordHealthSnapshot;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\ProbeResult;
use App\Model\Project;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\FakeGameMetricSource;
use App\Tests\Support\FakeHealthProbe;
use App\Tests\Support\InMemoryHealthSnapshotStore;
use App\Tests\Support\InMemoryMetricStore;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MonitoringOverviewTest extends TestCase
{
    public function testRecordsProbeResultsAndSurfacesThemOnTheOverview(): void
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));
        $snapshots = new InMemoryHealthSnapshotStore();
        $probe = new FakeHealthProbe();
        $probe->willReturn('https://loop9-backend.onrender.com/healthz', new ProbeResult(HealthStatus::Ok, 200, 18));
        $probe->willReturn('https://loop9-backend.onrender.com/readyz', new ProbeResult(HealthStatus::Ready, 200, 22));

        $announce = new AnnounceHealthChange($snapshots, new FakeAlertChannel(configured: false), new NullLogger());
        $collect = new CollectGameMetrics(new FakeGameMetricSource(), new InMemoryMetricStore(), new NullLogger());
        $recorded = (new RecordHealthSnapshot($projects, $probe, $snapshots, $announce, $collect))->forGameId('loop9');
        self::assertCount(2, $recorded);
        self::assertSame(HealthEndpoint::Health, $recorded[0]->endpoint);

        $overview = (new GetMonitoringOverview($projects, $snapshots, new InMemoryMetricStore()))->execute();
        self::assertCount(1, $overview->projects);
        self::assertSame('ok', $overview->projects[0]->healthStatus);
        self::assertSame('ready', $overview->projects[0]->readyStatus);
        self::assertSame(18, $overview->projects[0]->healthLatencyMs);
    }
}
