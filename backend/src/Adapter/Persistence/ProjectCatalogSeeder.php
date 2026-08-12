<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\Project;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProjectCatalogSeeder
{
    public function __construct(
        #[Autowire('%env(LOOP9_DISPLAY_NAME)%')]
        private readonly string $loop9DisplayName,
        #[Autowire('%env(LOOP9_HEALTH_URL)%')]
        private readonly string $loop9HealthUrl,
        #[Autowire('%env(LOOP9_READY_URL)%')]
        private readonly string $loop9ReadyUrl,
        #[Autowire('%env(LOOP9_INGEST_TOKEN)%')]
        private readonly string $loop9IngestToken,
    ) {
    }

    public function seed(JsonFileDatabase $database): void
    {
        $repository = new JsonProjectRepository($database);
        $existing = $repository->findByGameId(GameId::fromString('loop9'));
        $token = $this->loop9IngestToken !== '' ? $this->loop9IngestToken : 'dev-loop9-ingest-token';
        $hash = $existing?->ingestTokenHash ?? IngestToken::hash($token);

        if ($this->loop9IngestToken !== '') {
            $hash = IngestToken::hash($this->loop9IngestToken);
        }

        $repository->save(new Project(
            GameId::fromString('loop9'),
            $this->loop9DisplayName !== '' ? $this->loop9DisplayName : 'Loop 9',
            $this->loop9HealthUrl,
            $this->loop9ReadyUrl,
            $hash,
        ));
    }
}
