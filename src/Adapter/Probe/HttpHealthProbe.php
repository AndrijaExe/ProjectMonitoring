<?php

declare(strict_types=1);

namespace App\Adapter\Probe;

use App\Model\HealthEndpoint;
use App\Model\HealthProbe;
use App\Model\ProbeResult;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpHealthProbe implements HealthProbe
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function probe(string $url, HealthEndpoint $endpoint): ProbeResult
    {
        $started = hrtime(true);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5.0,
                'max_redirects' => 2,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'ProjectMonitoring/1.0',
                ],
            ]);
            $httpCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            $latencyMs = (int) max(0, (hrtime(true) - $started) / 1_000_000);

            return ProbeResult::down($latencyMs, 'Probe transport failed.');
        }

        $latencyMs = (int) max(0, (hrtime(true) - $started) / 1_000_000);
        $jsonStatus = null;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['status']) && is_string($decoded['status'])) {
            $jsonStatus = $decoded['status'];
        }

        return ProbeResult::fromHttp($endpoint, $httpCode, $latencyMs, $jsonStatus);
    }
}
