<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\ControlProjectService;
use App\Model\ControlRefused;
use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Model\RenderUnavailable;
use App\Model\ServiceAction;
use App\Model\ServiceState;
use App\Tests\Support\CollectingLogger;
use App\Tests\Support\FakeServiceControl;
use App\Tests\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class ControlProjectServiceTest extends TestCase
{
    public function testTheStateSaysWhatIsRunningAndWhetherTheButtonsAreAllowed(): void
    {
        $service = $this->service(new FakeServiceControl(), enabled: true);

        $answer = $service->state('loop9');

        self::assertTrue($answer['configured']);
        self::assertTrue($answer['enabled']);
        self::assertSame('live', $answer['state']['deploy_status']);
        self::assertNull($answer['note']);
    }

    public function testWithControlsOffTheStateIsStillReadableAndSaysHowToTurnThemOn(): void
    {
        $service = $this->service(new FakeServiceControl(), enabled: false);

        $answer = $service->state('loop9');

        // Seeing that a service is stopped is useful even where changing it is not allowed.
        self::assertFalse($answer['enabled']);
        self::assertNotNull($answer['state']);
        self::assertStringContainsString('CONTROLS_ENABLED=true', (string) $answer['note']);
    }

    public function testWithControlsOffAnActionIsRefusedBeforeTheHostIsTouched(): void
    {
        $control = new FakeServiceControl();
        $service = $this->service($control, enabled: false);

        try {
            $service->apply('loop9', ServiceAction::Stop);
            self::fail('A disabled console must not act.');
        } catch (ControlRefused $exception) {
            self::assertStringContainsString('CONTROLS_ENABLED=true', $exception->getMessage());
        }

        self::assertSame([], $control->applied);
    }

    public function testEachActionReachesTheHostAndIsWrittenDownTwice(): void
    {
        $control = new FakeServiceControl();
        $logger = new CollectingLogger();
        $service = $this->service($control, enabled: true, logger: $logger);

        $answer = $service->apply('loop9', ServiceAction::Stop);

        self::assertSame([ServiceAction::Stop], $control->applied);
        self::assertTrue($answer['state']['stopped']);
        // Asked, then accepted: a trail with only the second line cannot explain an outage that
        // began with the first.
        self::assertSame(
            ['Console asked to {action} {project}.', 'The host accepted the {action} of {project}.'],
            $logger->messages,
        );
        self::assertSame('stop', $logger->contexts[0]['action']);
        self::assertSame('loop9', $logger->contexts[0]['project']);
    }

    public function testTheRequestIsRecordedEvenWhenTheHostRefusesIt(): void
    {
        $logger = new CollectingLogger();
        $service = $this->service(
            new FakeServiceControl(failure: new RenderUnavailable('Render answered 400.')),
            enabled: true,
            logger: $logger,
        );

        try {
            $service->apply('loop9', ServiceAction::Rebuild);
            self::fail('The host said no; that has to surface.');
        } catch (RenderUnavailable) {
        }

        // The attempt is the interesting part: without it, a rebuild that never happened looks
        // like a rebuild that was never asked for.
        self::assertSame(['Console asked to {action} {project}.'], $logger->messages);
    }

    public function testWithoutAKeyThereIsNothingToControlAndTheStateSaysSo(): void
    {
        $service = $this->service(new FakeServiceControl(configured: false), enabled: true);

        $answer = $service->state('loop9');
        self::assertFalse($answer['configured']);
        self::assertNull($answer['state']);
        self::assertStringContainsString('RENDER_API_KEY', (string) $answer['note']);

        $this->expectException(ControlRefused::class);
        $service->apply('loop9', ServiceAction::Stop);
    }

    public function testAHostThatWillNotAnswerLeavesTheRestOfTheViewIntact(): void
    {
        $service = $this->service(
            new FakeServiceControl(failure: new RenderUnavailable('Could not reach the Render API.')),
            enabled: true,
        );

        $answer = $service->state('loop9');

        self::assertNull($answer['state']);
        self::assertSame('Could not reach the Render API.', $answer['note']);
    }

    public function testAnUnknownProjectIsNotAThingToStop(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(new FakeServiceControl(), enabled: true)->apply('nope', ServiceAction::Stop);
    }

    public function testABusyServiceIsReportedAsBusySoTheConsoleCanWait(): void
    {
        $service = $this->service(
            new FakeServiceControl(state: new ServiceState('loop9-backend', false, 'update_in_progress')),
            enabled: true,
        );

        self::assertTrue($service->state('loop9')['state']['busy']);
    }

    private function service(
        FakeServiceControl $control,
        bool $enabled,
        ?CollectingLogger $logger = null,
    ): ControlProjectService {
        $projects = new InMemoryProjectRepository();
        $projects->save(new Project(
            GameId::fromString('loop9'),
            'Loop 9',
            'https://loop9-backend.onrender.com/healthz',
            'https://loop9-backend.onrender.com/readyz',
            IngestToken::hash('test-ingest-token-ok'),
        ));

        return new ControlProjectService($projects, $control, $logger ?? new CollectingLogger(), $enabled);
    }
}
