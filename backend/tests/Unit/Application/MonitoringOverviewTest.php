<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AnnounceHealthChange;
use App\Application\AnnounceMetricAlarms;
use App\Application\CollectGameMetrics;
use App\Application\GetMonitoringOverview;
use App\Application\RecordHealthSnapshot;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\ProbeResult;
use App\Model\Project;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\FakeGameMetricSource;
use App\Tests\Support\FakeHealthProbe;
use App\Tests\Support\InMemoryAlarmStateStore;
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
        $metrics = new InMemoryMetricStore();
        $collect = new CollectGameMetrics(
            new FakeGameMetricSource(),
            $metrics,
            new NullLogger(),
            new AnnounceMetricAlarms(
                $metrics,
                new InMemoryAlarmStateStore(),
                new FakeAlertChannel(configured: false),
                new NullLogger(),
            ),
        );
        $recorded = (new RecordHealthSnapshot($projects, $probe, $snapshots, $announce, $collect))->forGameId('loop9');
        self::assertCount(2, $recorded);
        self::assertSame(HealthEndpoint::Health, $recorded[0]->endpoint);

        $overview = (new GetMonitoringOverview($projects, $snapshots, new InMemoryMetricStore()))->execute();
        self::assertCount(1, $overview->projects);
        self::assertSame('ok', $overview->projects[0]->healthStatus);
        self::assertSame('ready', $overview->projects[0]->readyStatus);
        self::assertSame(18, $overview->projects[0]->healthLatencyMs);
        self::assertFalse($overview->stale);
    }

    public function testAProbeOlderThanTheWindowIsReportedAsStale(): void
    {
        $snapshots = new InMemoryHealthSnapshotStore();
        $snapshots->record($this->snapshotFrom('-3 hours'));

        $overview = $this->overviewOver($snapshots)->execute();

        self::assertTrue($overview->stale);
        self::assertNotNull($overview->lastProbeAt);
        self::assertSame(120, $overview->staleAfterMinutes);
    }

    public function testARecentProbeIsNotStale(): void
    {
        $snapshots = new InMemoryHealthSnapshotStore();
        $snapshots->record($this->snapshotFrom('-10 minutes'));

        self::assertFalse($this->overviewOver($snapshots)->execute()->stale);
    }

    public function testAProjectNeverProbedIsStaleBecauseNobodyIsWatchingItEither(): void
    {
        $overview = $this->overviewOver(new InMemoryHealthSnapshotStore())->execute();

        self::assertTrue($overview->stale);
        self::assertNull($overview->lastProbeAt);
    }

    public function testAnEmptyFleetIsNeverStale(): void
    {
        $overview = new GetMonitoringOverview(
            new InMemoryProjectRepository(),
            new InMemoryHealthSnapshotStore(),
            new InMemoryMetricStore(),
        );

        self::assertFalse($overview->execute()->stale);
    }

    public function testTheWarningCanBeTurnedOff(): void
    {
        $snapshots = new InMemoryHealthSnapshotStore();
        $snapshots->record($this->snapshotFrom('-30 days'));

        $overview = new GetMonitoringOverview(
            $this->projects(),
            $snapshots,
            new InMemoryMetricStore(),
            staleAfterMinutes: 0,
        );

        self::assertFalse($overview->execute()->stale);
    }

    private function overviewOver(InMemoryHealthSnapshotStore $snapshots): GetMonitoringOverview
    {
        return new GetMonitoringOverview($this->projects(), $snapshots, new InMemoryMetricStore());
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

    private function snapshotFrom(string $ago): HealthSnapshot
    {
        return new HealthSnapshot(
            GameId::fromString('loop9'),
            HealthEndpoint::Health,
            HealthStatus::Ok,
            200,
            18,
            new \DateTimeImmutable($ago),
        );
    }
}
