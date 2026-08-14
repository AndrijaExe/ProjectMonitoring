<?php

declare(strict_types=1);

namespace App\Adapter\Logs;

use App\Adapter\Render\RenderApi;
use App\Adapter\Render\RenderServiceDirectory;
use App\Model\LogFilter;
use App\Model\LogLine;
use App\Model\LogPage;
use App\Model\LogSource;
use App\Model\LogsUnavailable;
use App\Model\Project;

/**
 * Reads logs through Render's public API.
 *
 * The console never talks to Render: it asks this API, which holds the key. That matters because
 * a Render key authorises everything the dashboard can do, so the blast radius has to stay
 * limited to the queries implemented here.
 */
final class RenderLogSource implements LogSource
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

    public function recent(Project $project, LogFilter $filter): LogPage
    {
        $this->requireKey();

        return $this->page($this->services->forProject($project), $filter);
    }

    public function recentForService(string $serviceId, LogFilter $filter): LogPage
    {
        $this->requireKey();

        return $this->page($this->services->byId($serviceId), $filter);
    }

    /**
     * @param array{id: string, ownerId: string, name: string} $service
     */
    private function page(array $service, LogFilter $filter): LogPage
    {
        $query = [
            'ownerId' => $service['ownerId'],
            'resource' => [$service['id']],
            'limit' => $filter->limit,
            'direction' => 'backward',
            'startTime' => $filter->since(new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        if ($filter->level !== null && $filter->level !== '') {
            $query['level'] = [$filter->level];
        }

        if ($filter->text !== null && $filter->text !== '') {
            // Render matches log text with wildcards, so a bare term would match nothing.
            $query['text'] = ['*'.$filter->text.'*'];
        }

        $payload = $this->api->get('/logs', $query);
        $lines = [];
        $routine = 0;

        foreach ($payload['logs'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $line = $this->toLine($entry);
            if ($this->isPlatformProbe($line->message)) {
                ++$routine;
                continue;
            }

            $lines[] = $line;
        }

        // Render does not promise an order and has answered oldest first, which buries the
        // line that made someone open the panel under a screen of routine ones.
        usort($lines, static fn (LogLine $a, LogLine $b): int => $b->at <=> $a->at);

        return new LogPage($service['name'], $lines, $routine);
    }

    /**
     * Render probes every service's health check path every few seconds, and the web server
     * writes a line for each one. At that rate they are the only thing a panel of the newest
     * hundred lines can show, so a warning the application actually wrote would never be
     * visible. The host's own log page still has them; this panel exists to show the rest.
     */
    private function isPlatformProbe(string $message): bool
    {
        return preg_match('/"Render\/[\d.]+"\s*$/', $message) === 1;
    }

    private function requireKey(): void
    {
        if (!$this->isConfigured()) {
            throw new LogsUnavailable('RENDER_API_KEY is not set.');
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function toLine(array $entry): LogLine
    {
        $labels = [];
        foreach ($entry['labels'] ?? [] as $label) {
            if (is_array($label) && isset($label['name'], $label['value'])) {
                $labels[(string) $label['name']] = (string) $label['value'];
            }
        }

        return new LogLine(
            $this->timestamp($entry['timestamp'] ?? null),
            $this->plain((string) ($entry['message'] ?? '')),
            $labels['level'] ?? null,
            $labels['type'] ?? null,
        );
    }

    /**
     * Render colours its own platform lines for a terminal. In a browser the escape codes
     * arrive as literal noise in front of every word worth reading.
     */
    private function plain(string $message): string
    {
        return trim((string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $message));
    }

    private function timestamp(mixed $raw): \DateTimeImmutable
    {
        if (is_string($raw) && $raw !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Exception) {
                // Fall through to now: a line with an odd timestamp is still worth showing.
            }
        }

        return new \DateTimeImmutable();
    }
}
