<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\SendTestAlert;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Tests\Support\FakeAlertChannel;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class SendTestAlertTest extends TestCase
{
    public function testItSendsThroughTheRealPathAndMarksTheMailAsATest(): void
    {
        $channel = new FakeAlertChannel();
        $result = $this->useCase($channel)->execute();

        self::assertTrue($result['sent']);
        self::assertCount(1, $channel->sent);
        self::assertTrue($channel->sent[0]->isDrill);
        self::assertSame('[test] Loop 9 health is down', $channel->sent[0]->subject());
        self::assertStringContainsString('Nothing is wrong.', $channel->sent[0]->body());
    }

    public function testAnUnconfiguredChannelExplainsWhatIsMissing(): void
    {
        $result = $this->useCase(new FakeAlertChannel(configured: false))->execute();

        self::assertFalse($result['sent']);
        self::assertStringContainsString('RESEND_API_KEY', $result['note']);
    }

    public function testAFailureIsReportedRatherThanSwallowed(): void
    {
        // The polling path logs and moves on; here the failure is the answer to the question.
        $channel = new FakeAlertChannel();
        $channel->willFail('Resend answered 401');

        $result = $this->useCase($channel)->execute();

        self::assertFalse($result['sent']);
        self::assertSame('Resend answered 401', $result['note']);
    }

    private function useCase(FakeAlertChannel $channel): SendTestAlert
    {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('dev-loop9-ingest-token'),
        ));

        return new SendTestAlert($projects, $channel);
    }
}
