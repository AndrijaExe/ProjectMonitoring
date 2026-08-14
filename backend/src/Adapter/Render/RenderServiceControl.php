<?php

declare(strict_types=1);

namespace App\Adapter\Render;

use App\Model\Project;
use App\Model\ServiceAction;
use App\Model\ServiceControl;
use App\Model\ServiceState;

final class RenderServiceControl implements ServiceControl
{
    public function __construct(
        private readonly RenderApi $api,
        private readonly RenderServiceDirectory $services,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    public function state(Project $project): ServiceState
    {
        $service = $this->services->forProject($project);

        $payload = $this->api->get('/services/'.rawurlencode($service['id']));
        $described = isset($payload['service']) && is_array($payload['service']) ? $payload['service'] : $payload;

        return new ServiceState(
            $service['name'],
            // Render spells this as a pair of words rather than a boolean.
            ($described['suspended'] ?? '') === 'suspended',
            ...$this->latestDeploy($service['id']),
        );
    }

    public function apply(Project $project, ServiceAction $action): void
    {
        $service = $this->services->forProject($project);
        $id = rawurlencode($service['id']);

        match ($action) {
            // The current commit, built fresh. Not a rollback and not a different branch: the
            // console has no business choosing which code runs, only whether it is running.
            ServiceAction::Rebuild => $this->api->post('/services/'.$id.'/deploys', ['clearCache' => 'do_not_clear']),
            ServiceAction::Stop => $this->api->post('/services/'.$id.'/suspend'),
            ServiceAction::Start => $this->api->post('/services/'.$id.'/resume'),
        };
    }

    /**
     * @return array{deployStatus: string, deployAt: ?\DateTimeImmutable, commit: string}
     */
    private function latestDeploy(string $serviceId): array
    {
        $blank = ['deployStatus' => '', 'deployAt' => null, 'commit' => ''];

        $payload = $this->api->get('/services/'.rawurlencode($serviceId).'/deploys', ['limit' => 1]);
        $first = $payload[0] ?? null;
        if (!is_array($first)) {
            return $blank;
        }

        $deploy = isset($first['deploy']) && is_array($first['deploy']) ? $first['deploy'] : $first;
        $at = $deploy['finishedAt'] ?? $deploy['startedAt'] ?? $deploy['createdAt'] ?? null;

        return [
            'deployStatus' => (string) ($deploy['status'] ?? ''),
            'deployAt' => $this->time(is_string($at) ? $at : null),
            // The subject line, so the operator can tell which change is live without leaving
            // the console for a commit list.
            'commit' => $this->firstLine((string) ($deploy['commit']['message'] ?? '')),
        ];
    }

    private function firstLine(string $message): string
    {
        $line = trim((string) strtok($message, "\n"));

        return mb_strlen($line) > 80 ? mb_substr($line, 0, 79).'…' : $line;
    }

    private function time(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
