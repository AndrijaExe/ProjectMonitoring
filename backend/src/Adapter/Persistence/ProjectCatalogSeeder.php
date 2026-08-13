<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use App\Model\ProjectRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProjectCatalogSeeder
{
    public function __construct(
        private readonly ProjectRepository $projects,
        #[Autowire('%env(LOOP9_DISPLAY_NAME)%')]
        private readonly string $loop9DisplayName,
        #[Autowire('%env(LOOP9_HEALTH_URL)%')]
        private readonly string $loop9HealthUrl,
        #[Autowire('%env(LOOP9_READY_URL)%')]
        private readonly string $loop9ReadyUrl,
        #[Autowire('%env(LOOP9_INGEST_TOKEN)%')]
        private readonly string $loop9IngestToken,
        #[Autowire('%env(LOOP9_METRICS_URL)%')]
        private readonly string $loop9MetricsUrl = '',
    ) {
    }

    public function seed(): void
    {
        $gameId = GameId::fromString('loop9');
        $existing = $this->projects->findByGameId($gameId);

        $hash = $this->loop9IngestToken !== ''
            ? IngestToken::hash($this->loop9IngestToken)
            : $existing?->ingestTokenHash ?? IngestToken::hash('dev-loop9-ingest-token');

        $this->projects->save(new Project(
            $gameId,
            $this->loop9DisplayName !== '' ? $this->loop9DisplayName : 'Loop 9',
            $this->loop9HealthUrl,
            $this->loop9ReadyUrl,
            $hash,
            $this->loop9MetricsUrl !== '' ? $this->loop9MetricsUrl : null,
        ));
    }
}
