<?php

declare(strict_types=1);

namespace App\Adapter\Metrics;

use App\Model\GameMetricSource;
use App\Model\GameReading;
use App\Model\MetricSample;
use App\Model\MetricsUnavailable;
use App\Model\Project;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpGameMetricSource implements GameMetricSource
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        /**
         * Per-game secrets as "loop9=abc,other=def".
         *
         * Kept in the environment rather than beside the URL in the database. The ingest token
         * is stored only as a hash for exactly this reason, and a scrape token is no less of a
         * credential for being read-only.
         */
        #[Autowire('%env(METRICS_TOKENS)%')]
        private readonly string $tokens,
        #[Autowire('%env(int:PROBE_TIMEOUT_SECONDS)%')]
        private readonly int $timeoutSeconds,
    ) {
    }

    public function read(Project $project): GameReading
    {
        $url = $project->metricsUrl;
        if ($url === null) {
            throw new MetricsUnavailable('No metrics URL registered for this project.');
        }

        $token = $this->tokenFor($project->gameId->value);
        if ($token === '') {
            throw new MetricsUnavailable(sprintf('No entry for "%s" in METRICS_TOKENS.', $project->gameId->value));
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'X-Metrics-Token' => $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new MetricsUnavailable(sprintf('The game answered %d.', $status));
            }

            $payload = $response->toArray(false);
        } catch (MetricsUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new MetricsUnavailable($exception->getMessage(), 0, $exception);
        }

        $counters = $payload['counters'] ?? null;
        if (!is_array($counters)) {
            throw new MetricsUnavailable('The answer had no "counters" object.');
        }

        // Gauges are newer than the first version of this contract, so a game that publishes
        // only counters is still a valid answer rather than a broken one.
        $gauges = $payload['gauges'] ?? [];

        return new GameReading(
            $this->clean($counters),
            is_array($gauges) ? $this->clean($gauges) : [],
            is_string($payload['storage'] ?? null) ? $payload['storage'] : '',
        );
    }

    /**
     * @param array<mixed> $counters
     *
     * @return array<string, float>
     */
    private function clean(array $counters): array
    {
        $clean = [];
        foreach ($counters as $name => $value) {
            // A game is free to publish anything. Names that would be rejected further down
            // are dropped here rather than allowed to fail the whole reading.
            if (!is_string($name) || preg_match(MetricSample::NAME_PATTERN, $name) !== 1) {
                continue;
            }

            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            $clean[$name] = (float) $value;
        }

        return $clean;
    }

    private function tokenFor(string $gameId): string
    {
        foreach (explode(',', $this->tokens) as $pair) {
            [$id, $token] = array_pad(explode('=', trim($pair), 2), 2, '');
            if (trim($id) === $gameId) {
                return trim($token);
            }
        }

        return '';
    }
}
