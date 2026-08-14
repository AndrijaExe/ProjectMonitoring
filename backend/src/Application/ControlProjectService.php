<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\ControlRefused;
use App\Model\GameId;
use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\RenderUnavailable;
use App\Model\ServiceAction;
use App\Model\ServiceControl;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reading and changing a project's run state from the console.
 *
 * Every other use case in this application observes. This one is the exception, so it carries the
 * things that only an acting one needs: an explicit switch that has to be turned on for the
 * buttons to exist at all, and a log line for each press.
 */
final class ControlProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ServiceControl $control,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:CONTROLS_ENABLED)%')]
        private readonly bool $enabled = false,
    ) {
    }

    /**
     * @return array{configured: bool, enabled: bool, state: ?array<string, mixed>, note: ?string}
     */
    public function state(string $gameId): array
    {
        $project = $this->project($gameId);

        if (!$this->control->isConfigured()) {
            return $this->unavailable('Set RENDER_API_KEY on the API service to see and change the run state here.');
        }

        try {
            $state = $this->control->state($project);
        } catch (RenderUnavailable $exception) {
            // The rest of the project view is still worth reading, so a host that will not
            // answer is a sentence in this panel rather than a broken page.
            return $this->unavailable($exception->getMessage());
        }

        return [
            'configured' => true,
            'enabled' => $this->enabled,
            'state' => $state->toArray(),
            'note' => $this->enabled
                ? null
                : 'Read-only. Set CONTROLS_ENABLED=true on the API service to allow the buttons.',
        ];
    }

    /**
     * @return array{configured: bool, enabled: bool, state: ?array<string, mixed>, note: ?string}
     */
    public function apply(string $gameId, ServiceAction $wanted): array
    {
        $project = $this->project($gameId);

        if (!$this->control->isConfigured()) {
            throw new ControlRefused('RENDER_API_KEY is not set, so there is nothing to control.');
        }

        if (!$this->enabled) {
            // A key that can read logs can also stop services, so the destructive half of it
            // stays shut until a deployment says otherwise. Reading a dashboard and taking a
            // service down should not be one decision.
            throw new ControlRefused('Controls are off. Set CONTROLS_ENABLED=true on the API service to allow this.');
        }

        // Written before the call, because the interesting case is the one that does not come
        // back: an audit trail that only records successes cannot explain an outage.
        $this->logger->warning('Console asked to {action} {project}.', [
            'action' => $wanted->asked(),
            'project' => $project->gameId->value,
        ]);

        $this->control->apply($project, $wanted);

        $this->logger->warning('The host accepted the {action} of {project}.', [
            'action' => $wanted->asked(),
            'project' => $project->gameId->value,
        ]);

        // Render answers these calls before the change has taken effect, so the state read back
        // here is usually still the old one. The console keeps asking; this is only a head start.
        return $this->state($gameId);
    }

    /**
     * @return array{configured: bool, enabled: bool, state: null, note: string}
     */
    private function unavailable(string $note): array
    {
        return [
            'configured' => $this->control->isConfigured(),
            'enabled' => false,
            'state' => null,
            'note' => $note,
        ];
    }

    private function project(string $gameId): Project
    {
        return $this->projects->findByGameId(GameId::fromString($gameId))
            ?? throw new \InvalidArgumentException('Unknown project.');
    }
}
