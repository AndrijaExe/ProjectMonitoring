<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\GameId;
use App\Model\LogFilter;
use App\Model\LogSource;
use App\Model\LogsUnavailable;
use App\Model\ProjectRepository;

final class GetProjectLogs
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly LogSource $logs,
    ) {
    }

    /**
     * @return array{configured: bool, lines: list<array<string, mixed>>, note: ?string}
     */
    public function execute(string $gameId, LogFilter $filter): array
    {
        $project = $this->projects->findByGameId(GameId::fromString($gameId));
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown project.');
        }

        if (!$this->logs->isConfigured()) {
            return [
                'configured' => false,
                'lines' => [],
                'note' => 'Set RENDER_API_KEY on the API service to read logs here.',
            ];
        }

        // An upstream that will not answer is reported as an empty panel with a reason,
        // not as a broken page: the rest of the project view is still worth reading.
        try {
            $lines = $this->logs->recent($project, $filter);
        } catch (LogsUnavailable $exception) {
            return [
                'configured' => true,
                'lines' => [],
                'note' => $exception->getMessage(),
            ];
        }

        return [
            'configured' => true,
            'lines' => array_map(static fn ($line): array => $line->toArray(), $lines),
            'note' => null,
        ];
    }
}
