<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AnnounceHealthChange;
use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\HealthSnapshot;
use App\Model\HealthStatus;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\InMemoryHealthSnapshotStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AnnounceHealthChangeTest extends TestCase
{
    private InMemoryHealthSnapshotStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryHealthSnapshotStore();
    }

    public function testAFallFromHealthyIsAnnouncedOnce(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-2 hours'));
        $channel = $this->announce($this->snapshot(HealthStatus::Down, 'now'));

        self::assertCount(1, $channel->sent);
        self::assertSame('Loop 9 health is down', $channel->sent[0]->subject());
        self::assertSame(HealthStatus::Ok, $channel->sent[0]->previous);
        self::assertFalse($channel->sent[0]->isRecovery());
    }

    public function testStayingDownIsSilent(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Down, '-2 hours'));
        $channel = $this->announce($this->snapshot(HealthStatus::Down, 'now'));

        self::assertSame([], $channel->sent);
    }

    public function testStayingHealthyIsSilent(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-1 hour'));
        $channel = $this->announce($this->snapshot(HealthStatus::Ok, 'now'));

        self::assertSame([], $channel->sent);
    }

    public function testARecoveryReportsHowLongItWasDown(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-5 hours'));
        $this->store->record($this->snapshot(HealthStatus::Down, '-3 hours'));
        $this->store->record($this->snapshot(HealthStatus::Down, '-1 hour'));

        $channel = $this->announce($this->snapshot(HealthStatus::Ok, 'now'));

        self::assertCount(1, $channel->sent);
        $alert = $channel->sent[0];
        self::assertTrue($alert->isRecovery());
        // Measured from the first failing probe, not the last one.
        self::assertEqualsWithDelta(3 * 3600, (int) $alert->outageSeconds, 5);
        self::assertStringContainsString('Down for: 3 hours', $alert->body());
    }

    public function testAThrottledReadingNeitherAlertsNorCountsAsRecovery(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Down, '-2 hours'));

        $throttled = $this->announce($this->snapshot(HealthStatus::Throttled, '-1 hour'));
        self::assertSame([], $throttled->sent, 'Not seeing the target is not a verdict.');

        // The blind reading is stored, as the console still shows it.
        $this->store->record($this->snapshot(HealthStatus::Throttled, '-1 hour'));

        $recovered = $this->announce($this->snapshot(HealthStatus::Ok, 'now'));
        self::assertCount(1, $recovered->sent);
        // The outage is measured from the real failure, not from the moment we went blind.
        self::assertEqualsWithDelta(2 * 3600, (int) $recovered->sent[0]->outageSeconds, 5);
    }

    public function testATimeoutDoesNotSuppressALaterRealFailure(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-3 hours'));
        $this->store->record($this->snapshot(HealthStatus::Timeout, '-2 hours'));

        $channel = $this->announce($this->snapshot(HealthStatus::Down, 'now'));

        self::assertCount(1, $channel->sent);
        self::assertSame(HealthStatus::Ok, $channel->sent[0]->previous);
    }

    public function testTheFirstReadingEverIsAnnouncedOnlyWhenItIsBad(): void
    {
        $quiet = $this->announce($this->snapshot(HealthStatus::Ok, 'now'));
        self::assertSame([], $quiet->sent);

        $loud = $this->announce($this->snapshot(HealthStatus::Error, 'now'));
        self::assertCount(1, $loud->sent);
        self::assertNull($loud->sent[0]->previous);
        self::assertStringContainsString('first conclusive reading', $loud->sent[0]->body());
    }

    public function testTheOtherProbeIsNotConfusedWithThisOne(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-1 hour'));
        $this->store->record($this->snapshot(HealthStatus::Ok, '-1 hour', HealthEndpoint::Ready));

        $channel = $this->announce($this->snapshot(HealthStatus::NotReady, 'now', HealthEndpoint::Ready));

        self::assertCount(1, $channel->sent);
        self::assertSame(HealthEndpoint::Ready, $channel->sent[0]->snapshot->endpoint);
    }

    public function testAnUnconfiguredChannelIsNeverCalled(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-1 hour'));
        $channel = new FakeAlertChannel(configured: false);

        (new AnnounceHealthChange($this->store, $channel, new NullLogger()))
            ->forNewSnapshot($this->project(), $this->snapshot(HealthStatus::Down, 'now'));

        self::assertSame([], $channel->sent);
    }

    public function testAFailingChannelDoesNotBreakThePoll(): void
    {
        $this->store->record($this->snapshot(HealthStatus::Ok, '-1 hour'));
        $channel = new FakeAlertChannel();
        $channel->willFail('mailbox on fire');

        (new AnnounceHealthChange($this->store, $channel, new NullLogger()))
            ->forNewSnapshot($this->project(), $this->snapshot(HealthStatus::Down, 'now'));

        self::assertSame([], $channel->sent);
    }

    private function announce(HealthSnapshot $fresh): FakeAlertChannel
    {
        $channel = new FakeAlertChannel();
        (new AnnounceHealthChange($this->store, $channel, new NullLogger()))
            ->forNewSnapshot($this->project(), $fresh);

        return $channel;
    }

    private function snapshot(
        HealthStatus $status,
        string $when,
        HealthEndpoint $endpoint = HealthEndpoint::Health,
    ): HealthSnapshot {
        return new HealthSnapshot(
            GameId::fromString('loop9'),
            $endpoint,
            $status,
            $status->isHealthy() ? 200 : 503,
            42,
            new \DateTimeImmutable($when),
        );
    }

    private function project(): Project
    {
        return new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        );
    }
}
