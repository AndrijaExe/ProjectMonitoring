<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Project
{
    public function __construct(
        public GameId $gameId,
        public string $displayName,
        public string $healthUrl,
        public string $readyUrl,
        public string $ingestTokenHash,
        /** Where the game publishes its own counters. Null when it publishes none. */
        public ?string $metricsUrl = null,
    ) {
        if (trim($displayName) === '' || mb_strlen($displayName) > 80) {
            throw new \InvalidArgumentException('Display name must be 1–80 characters.');
        }

        $this->assertHttpUrl($healthUrl, 'health_url');
        $this->assertHttpUrl($readyUrl, 'ready_url');

        if ($metricsUrl !== null) {
            $this->assertHttpUrl($metricsUrl, 'metrics_url');
        }

        if ($ingestTokenHash === '' || strlen($ingestTokenHash) < 32) {
            throw new \InvalidArgumentException('Ingest token hash is missing.');
        }
    }

    public function withEndpoints(string $healthUrl, string $readyUrl): self
    {
        return new self(
            $this->gameId,
            $this->displayName,
            $healthUrl,
            $readyUrl,
            $this->ingestTokenHash,
            $this->metricsUrl,
        );
    }

    public function withIngestTokenHash(string $ingestTokenHash): self
    {
        return new self(
            $this->gameId,
            $this->displayName,
            $this->healthUrl,
            $this->readyUrl,
            $ingestTokenHash,
            $this->metricsUrl,
        );
    }

    private function assertHttpUrl(string $url, string $field): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a valid URL.', $field));
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must use http or https.', $field));
        }
    }
}
