<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\GetProjectLogs;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\LogFilter;
use App\Model\LogLine;
use App\Model\Project;
use App\Tests\Support\FakeLogSource;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class GetProjectLogsTest extends TestCase
{
    public function testAnUnconfiguredSourceExplainsTheSetupStepInsteadOfFailing(): void
    {
        $result = $this->useCase(new FakeLogSource(configured: false))->execute('loop9', new LogFilter());

        self::assertFalse($result['configured']);
        self::assertSame([], $result['lines']);
        self::assertStringContainsString('RENDER_API_KEY', (string) $result['note']);
    }

    public function testAnUpstreamFailureBecomesANoteRatherThanABrokenPage(): void
    {
        $source = new FakeLogSource();
        $source->willFail('Render rejected the API key.');

        $result = $this->useCase($source)->execute('loop9', new LogFilter());

        self::assertTrue($result['configured']);
        self::assertSame([], $result['lines']);
        self::assertSame('Render rejected the API key.', $result['note']);
    }

    public function testItReturnsLinesReadyForTheConsole(): void
    {
        $source = new FakeLogSource();
        $source->willReturn(new LogLine(
            new \DateTimeImmutable('2026-08-13T07:20:05+00:00'),
            'Chat request finished',
            'info',
            'app',
        ));

        $result = $this->useCase($source)->execute('loop9', new LogFilter());

        self::assertNull($result['note']);
        self::assertSame([[
            'at' => '2026-08-13T07:20:05+00:00',
            'message' => 'Chat request finished',
            'level' => 'info',
            'type' => 'app',
        ]], $result['lines']);
    }

    public function testAnUnknownProjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase(new FakeLogSource())->execute('nope', new LogFilter());
    }

    private function useCase(FakeLogSource $source): GetProjectLogs
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));

        return new GetProjectLogs($projects, $source);
    }
}
