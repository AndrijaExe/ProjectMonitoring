<?php

declare(strict_types=1);

namespace App\Adapter\Render;

use App\Model\RenderUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One door to Render's API.
 *
 * The key never reaches a browser: the console asks this API, which holds it. That is not
 * politeness about secrets — a Render key authorises everything the dashboard can do, including
 * deleting services, so the set of things it can be used for has to be the set of calls written
 * here rather than whatever a stolen browser session decides to try.
 */
final class RenderApi
{
    private const BASE_URL = 'https://api.render.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(RENDER_API_KEY)%')]
        private readonly string $apiKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, [], $body);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $query, array $body = []): array
    {
        if (!$this->isConfigured()) {
            throw new RenderUnavailable('RENDER_API_KEY is not set.');
        }

        try {
            $search = $this->queryString($query);
            $options = [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Accept' => 'application/json',
                ],
            ];

            // Only where there is something to say. Suspend and resume take no body, and an
            // encoded empty array is a body: `[]` where the host expects an object at most.
            if ($body !== []) {
                $options['json'] = $body;
            }

            $response = $this->httpClient->request(
                $method,
                self::BASE_URL.$path.($search === '' ? '' : '?'.$search),
                $options,
            );

            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new RenderUnavailable('Render rejected the API key.');
            }

            if ($status === 429) {
                // Deploys, suspends and resumes are capped at ten a minute per service.
                throw new RenderUnavailable('Render is rate limiting us. Wait a minute and try again.');
            }

            if ($status >= 400) {
                throw new RenderUnavailable(sprintf('Render answered %d for %s.', $status, $path));
            }

            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $exception) {
            throw new RenderUnavailable('Could not reach the Render API.', 0, $exception);
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
}
