<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\GameId;
use App\Model\HealthEndpoint;
use App\Model\IngestToken;
use App\Model\MetricSample;
use App\Model\ProbeResult;
use PHPUnit\Framework\TestCase;

final class ModelValidationTest extends TestCase
{
    public function testGameIdRejectsUppercaseAndSymbols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GameId::fromString('Loop_9');
    }

    public function testGameIdNormalizes(): void
    {
        self::assertSame('loop9', GameId::fromString('Loop9')->value);
    }

    public function testMetricNameMustBeDottedLowercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MetricSample(GameId::fromString('loop9'), 'Chat Requests', 1, [], new \DateTimeImmutable());
    }

    public function testIngestTokenHashIsStable(): void
    {
        $hash = IngestToken::hash('dev-loop9-ingest-token');
        self::assertTrue(IngestToken::matches('dev-loop9-ingest-token', $hash));
        self::assertFalse(IngestToken::matches('other-token-value-1', $hash));
    }

    public function testReadyProbeMaps503ToNotReady(): void
    {
        $result = ProbeResult::fromHttp(HealthEndpoint::Ready, 503, 12, 'not_ready');
        self::assertSame('not_ready', $result->status->value);
    }
}
