<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class JsonFileDatabase
{
    /**
     * @var array{
     *     projects: list<array<string, mixed>>,
     *     health_snapshots: list<array<string, mixed>>,
     *     metric_samples: list<array<string, mixed>>
     * }
     */
    private ?array $state = null;
    private bool $booted = false;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DATABASE_PATH)%')]
        private readonly string $databasePath,
        private readonly ProjectCatalogSeeder $seeder,
    ) {
    }

    /**
     * @return array{
     *     projects: list<array<string, mixed>>,
     *     health_snapshots: list<array<string, mixed>>,
     *     metric_samples: list<array<string, mixed>>
     * }
     */
    public function read(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $path = $this->absolutePath();
        $this->ensureDirectory($path);

        if (!is_file($path)) {
            $this->state = $this->emptyState();
            $this->flush();
        } else {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                $decoded = $this->emptyState();
            }

            $this->state = [
                'projects' => array_values($decoded['projects'] ?? []),
                'health_snapshots' => array_values($decoded['health_snapshots'] ?? []),
                'metric_samples' => array_values($decoded['metric_samples'] ?? []),
            ];
        }

        if (!$this->booted) {
            $this->booted = true;
            $this->seeder->seed($this);
        }

        return $this->state ?? $this->emptyState();
    }

    /**
     * @param callable(array{
     *     projects: list<array<string, mixed>>,
     *     health_snapshots: list<array<string, mixed>>,
     *     metric_samples: list<array<string, mixed>>
     * }): array $mutator
     */
    public function mutate(callable $mutator): void
    {
        $this->state = $mutator($this->read());
        $this->flush();
    }

    public function pingWrite(): void
    {
        $this->read();
        $this->flush();
    }

    public function absolutePath(): string
    {
        if (str_starts_with($this->databasePath, '/')) {
            return $this->databasePath;
        }

        return $this->projectDir.'/'.$this->databasePath;
    }

    private function flush(): void
    {
        $path = $this->absolutePath();
        $this->ensureDirectory($path);
        $payload = json_encode($this->state ?? $this->emptyState(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException('Unable to write monitoring store.');
        }
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('Unable to replace monitoring store.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create data directory.');
        }
    }

    /**
     * @return array{
     *     projects: list<array<string, mixed>>,
     *     health_snapshots: list<array<string, mixed>>,
     *     metric_samples: list<array<string, mixed>>
     * }
     */
    private function emptyState(): array
    {
        return [
            'projects' => [],
            'health_snapshots' => [],
            'metric_samples' => [],
        ];
    }
}
