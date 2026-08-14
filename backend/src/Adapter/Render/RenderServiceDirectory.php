<?php

declare(strict_types=1);

namespace App\Adapter\Render;

use App\Model\Project;
use App\Model\RenderUnavailable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Turns a project into the Render service that answers for it.
 *
 * Registering a project means naming its endpoints, not copying a service id out of a dashboard,
 * so the id is discovered from the hostname in its health URL. Two features need the same answer
 * — reading logs and pressing buttons — and a second copy of this lookup would be a second thing
 * to fix the next time Render changes how names and hostnames relate.
 */
final class RenderServiceDirectory
{
    private const HOST_SUFFIX = '.onrender.com';
    private const LOOKUP_TTL = 3600;

    public function __construct(
        private readonly RenderApi $api,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{id: string, ownerId: string, name: string}
     */
    public function forProject(Project $project): array
    {
        $host = $this->renderHost($project->healthUrl);

        /** @var array{id: string, ownerId: string, name: string} */
        // The key carries a version because the cached shape gained a name: an entry written by
        // an earlier release would be read as a service without one.
        return $this->cache->get('render_service_2_'.str_replace('.', '_', $host), function (ItemInterface $item) use ($host): array {
            $item->expiresAfter(self::LOOKUP_TTL);

            return $this->resolve($host);
        });
    }

    /**
     * The monitor's own service, which Render names in an environment variable rather than
     * making us guess.
     *
     * @return array{id: string, ownerId: string, name: string}
     */
    public function byId(string $serviceId): array
    {
        if (trim($serviceId) === '') {
            throw new RenderUnavailable('No service id. RENDER_SERVICE_ID is only set when running on Render.');
        }

        /** @var array{id: string, ownerId: string, name: string} */
        return $this->cache->get('render_self_'.$serviceId, function (ItemInterface $item) use ($serviceId): array {
            $item->expiresAfter(self::LOOKUP_TTL);

            $payload = $this->api->get('/services/'.rawurlencode($serviceId));
            $service = isset($payload['service']) && is_array($payload['service']) ? $payload['service'] : $payload;

            return $this->identity($service);
        });
    }

    /**
     * @return array{id: string, ownerId: string, name: string}
     */
    private function resolve(string $host): array
    {
        $name = substr($host, 0, -strlen(self::HOST_SUFFIX));

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

        throw new RenderUnavailable(sprintf(
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
            throw new RenderUnavailable('Render described a service without an id.');
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
        foreach ($this->api->get('/services', $query) as $row) {
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
        if (!str_ends_with($host, self::HOST_SUFFIX)) {
            throw new RenderUnavailable('This is only wired for targets hosted on Render.');
        }

        return $host;
    }
}
