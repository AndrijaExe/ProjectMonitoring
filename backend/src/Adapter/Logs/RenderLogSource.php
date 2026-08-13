<?php

declare(strict_types=1);

namespace App\Adapter\Logs;

use App\Model\LogFilter;
use App\Model\LogLine;
use App\Model\LogPage;
use App\Model\LogSource;
use App\Model\LogsUnavailable;
use App\Model\Project;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads logs through Render's public API.
 *
 * The API key is never handed to the browser: the console asks this service, which asks
 * Render. That matters because a Render key authorises everything the dashboard can do,
 * so the blast radius has to stay limited to the queries implemented here.
 */
final class RenderLogSource implements LogSource
{
    private const BASE_URL = 'https://api.render.com/v1';
    private const RENDER_HOST_SUFFIX = '.onrender.com';
    private const SERVICE_LOOKUP_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(RENDER_API_KEY)%')]
        private readonly string $apiKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function recent(Project $project, LogFilter $filter): LogPage
    {
        if (!$this->isConfigured()) {
            throw new LogsUnavailable('RENDER_API_KEY is not set.');
        }

        return $this->page($this->service($project), $filter);
    }

    public function recentForService(string $serviceId, LogFilter $filter): LogPage
    {
        if (!$this->isConfigured()) {
            throw new LogsUnavailable('RENDER_API_KEY is not set.');
        }

        if (trim($serviceId) === '') {
            throw new LogsUnavailable('No service id. RENDER_SERVICE_ID is only set when running on Render.');
        }

        /** @var array{id: string, ownerId: string, name: string} $service */
        $service = $this->cache->get('render_self_'.$serviceId, function (ItemInterface $item) use ($serviceId): array {
            $item->expiresAfter(self::SERVICE_LOOKUP_TTL);

            $payload = $this->get('/services/'.rawurlencode($serviceId), []);
            $service = isset($payload['service']) && is_array($payload['service']) ? $payload['service'] : $payload;

            return $this->identity($service);
        });

        return $this->page($service, $filter);
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

        $payload = $this->get('/logs', $query);
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

    /**
     * @return array{id: string, ownerId: string, name: string}
     */
    private function service(Project $project): array
    {
        $host = $this->renderHost($project->healthUrl);

        /** @var array{id: string, ownerId: string, name: string} */
        // The key carries a version because the cached shape gained a name: an entry written by
        // the previous release would be read as a service without one.
        return $this->cache->get('render_service_2_'.str_replace('.', '_', $host), function (ItemInterface $item) use ($host): array {
            $item->expiresAfter(self::SERVICE_LOOKUP_TTL);

            return $this->resolve($host);
        });
    }

    /**
     * @return array{id: string, ownerId: string, name: string}
     */
    private function resolve(string $host): array
    {
        $name = substr($host, 0, -strlen(self::RENDER_HOST_SUFFIX));

        // Fast path. Holds whenever the hostname was still free when the service was created.
        foreach ($this->services(['name' => $name, 'limit' => 20]) as $service) {
            if (($service['name'] ?? null) === $name) {
                return $this->identity($service);
            }
        }

        // Render appends a suffix to the hostname when the name is taken platform-wide, so the
        // two can disagree. The URL it reports is the authority.
        $seen = [];
        foreach ($this->services(['limit' => 100]) as $service) {
            $url = $service['serviceDetails']['url'] ?? '';
            if (is_string($url) && $url !== '' && parse_url($url, PHP_URL_HOST) === $host) {
                return $this->identity($service);
            }

            if (isset($service['name'])) {
                $seen[] = (string) $service['name'];
            }
        }

        throw new LogsUnavailable(sprintf(
            'No Render service serves %s. This key sees: %s.',
            $host,
            $seen === [] ? 'nothing at all' : implode(', ', array_slice($seen, 0, 12)),
        ));
    }

    /**
     * @param array<string, mixed> $service
     *
     * @return array{id: string, ownerId: string, name: string}
     */
    private function identity(array $service): array
    {
        if (!isset($service['id'], $service['ownerId'])) {
            throw new LogsUnavailable('Render described a service without an id.');
        }

        return [
            'id' => (string) $service['id'],
            'ownerId' => (string) $service['ownerId'],
            'name' => (string) ($service['name'] ?? $service['id']),
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function services(array $query): array
    {
        $services = [];
        foreach ($this->get('/services', $query) as $row) {
            // List endpoints wrap each item, but tolerate a bare object too.
            $service = is_array($row) && isset($row['service']) && is_array($row['service'])
                ? $row['service']
                : $row;

            if (is_array($service)) {
                $services[] = $service;
            }
        }

        return $services;
    }

    private function renderHost(string $healthUrl): string
    {
        $host = (string) (parse_url($healthUrl, PHP_URL_HOST) ?? '');
        if (!str_ends_with($host, self::RENDER_HOST_SUFFIX)) {
            throw new LogsUnavailable('Logs are only wired for targets hosted on Render.');
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $search = $this->queryString($query);
            $response = $this->httpClient->request('GET', self::BASE_URL.$path.($search === '' ? '' : '?'.$search), [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new LogsUnavailable('Render rejected the API key.');
            }

            if ($status >= 400) {
                throw new LogsUnavailable(sprintf('Render answered %d for %s.', $status, $path));
            }

            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $exception) {
            throw new LogsUnavailable('Could not reach the Render API.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Repeats the key for list values (`resource=a&resource=b`). Render reads them that way,
     * while PHP's own encoder would emit `resource[0]=a`, which it ignores.
     *
     * @param array<string, mixed> $query
     */
    private function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $single);
            }
        }

        return implode('&', $pairs);
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
