<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\LogFilter;
use App\Model\LogSource;
use App\Model\LogsUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The monitor's own logs. When the board looks wrong, the next question is whether the game
 * is broken or the watcher is, and that answer should not require a second browser tab.
 */
final class GetSystemLogs
{
    public function __construct(
        private readonly LogSource $logs,
        // Render sets this inside every service, so the monitor finds itself without being told.
        #[Autowire('%env(RENDER_SERVICE_ID)%')]
        private readonly string $serviceId = '',
    ) {
    }

    /**
     * @return array{configured: bool, source: ?string, lines: list<array<string, mixed>>, note: ?string}
     */
    public function execute(LogFilter $filter): array
    {
        if (!$this->logs->isConfigured()) {
            return [
                'configured' => false,
                'source' => null,
                'lines' => [],
                'note' => 'Set RENDER_API_KEY on the API service to read logs here.',
            ];
        }

        try {
            $page = $this->logs->recentForService($this->serviceId, $filter);
        } catch (LogsUnavailable $exception) {
            return ['configured' => true, 'source' => null, 'lines' => [], 'note' => $exception->getMessage()];
        }

        return [
            'configured' => true,
            'source' => $page->source,
            'lines' => array_map(static fn ($line): array => $line->toArray(), $page->lines),
            'note' => null,
        ];
    }
}
