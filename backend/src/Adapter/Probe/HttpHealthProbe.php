<?php

declare(strict_types=1);

namespace App\Adapter\Probe;

use App\Model\HealthEndpoint;
use App\Model\HealthProbe;
use App\Model\ProbeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpHealthProbe implements HealthProbe
{
    /**
     * Sleeping hosts (Render free tier and friends) drop the request that wakes them
     * and answer the next one, so a single timeout is not yet evidence of an outage.
     */
    private const RETRIES_ON_TIMEOUT = 1;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(float:PROBE_TIMEOUT_SECONDS)%')]
        private readonly float $timeoutSeconds = 15.0,
    ) {
    }

    public function probe(string $url, HealthEndpoint $endpoint): ProbeResult
    {
        $started = hrtime(true);
        $attempts = 0;

        while (true) {
            ++$attempts;

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => $this->timeoutSeconds,
                    'max_redirects' => 2,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'ProjectMonitoring/1.0',
                    ],
                ]);
                $httpCode = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (TimeoutExceptionInterface) {
                if ($attempts > self::RETRIES_ON_TIMEOUT) {
                    return ProbeResult::timedOut($this->elapsedMs($started), sprintf(
                        'No response within %.1fs over %d attempts.',
                        $this->timeoutSeconds,
                        $attempts,
                    ));
                }

                continue;
            } catch (TransportExceptionInterface $exception) {
                return ProbeResult::down($this->elapsedMs($started), $this->reason($exception));
            }

            return ProbeResult::fromHttp($endpoint, $httpCode, $this->elapsedMs($started), $this->jsonStatus($body));
        }
    }

    private function jsonStatus(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['status']) && is_string($decoded['status'])) {
            return $decoded['status'];
        }

        return null;
    }

    private function elapsedMs(float|int $started): int
    {
        return (int) max(0, (hrtime(true) - $started) / 1_000_000);
    }

    private function reason(TransportExceptionInterface $exception): string
    {
        $message = trim(strtok($exception->getMessage(), "\n") ?: '');
        if ($message === '') {
            return 'Probe transport failed.';
        }

        return mb_substr($message, 0, 160);
    }
}
