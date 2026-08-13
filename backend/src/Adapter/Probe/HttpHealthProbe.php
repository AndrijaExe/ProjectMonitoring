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

    /**
     * Render routes outbound traffic through IP ranges shared by every service in a region,
     * so a CDN in front of the target can throttle this probe over traffic that is not ours.
     * A short pause often lands the retry in a fresh window.
     */
    private const RETRIES_ON_THROTTLE = 1;
    private const TOO_MANY_REQUESTS = 429;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(float:PROBE_TIMEOUT_SECONDS)%')]
        private readonly float $timeoutSeconds = 15.0,
        private readonly int $throttleBackoffSeconds = 2,
    ) {
    }

    public function probe(string $url, HealthEndpoint $endpoint): ProbeResult
    {
        $started = hrtime(true);
        $attempts = 0;
        $throttledAttempts = 0;

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
                $headers = $response->getHeaders(false);
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

            if ($httpCode === self::TOO_MANY_REQUESTS) {
                ++$throttledAttempts;

                if ($throttledAttempts <= self::RETRIES_ON_THROTTLE) {
                    if ($this->throttleBackoffSeconds > 0) {
                        sleep($this->throttleBackoffSeconds);
                    }

                    continue;
                }

                return ProbeResult::throttled(
                    $httpCode,
                    $this->elapsedMs($started),
                    $this->throttleReason($headers, $throttledAttempts),
                );
            }

            return ProbeResult::fromHttp($endpoint, $httpCode, $this->elapsedMs($started), $this->jsonStatus($body));
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function throttleReason(array $headers, int $attempts): string
    {
        $reason = sprintf('Rate limited before reaching the target, %d attempts.', $attempts);

        $by = $headers['server'][0] ?? null;
        if (is_string($by) && $by !== '') {
            $reason .= ' Refused by '.mb_substr($by, 0, 40).'.';
        }

        $retryAfter = $headers['retry-after'][0] ?? null;
        if (is_string($retryAfter) && $retryAfter !== '') {
            $reason .= ' Retry-After: '.mb_substr($retryAfter, 0, 20).'.';
        }

        return $reason;
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
