<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

use App\Model\GameId;
use App\Model\IngestToken;
use App\Model\ProjectRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IngestTokenAuthenticator
{
    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    public function authenticate(string $gameId, string $token): void
    {
        $project = $this->projects->findByGameId(GameId::fromString($gameId));
        if ($project === null) {
            throw new NotFoundHttpException('Unknown project.');
        }

        if (!IngestToken::matches($token, $project->ingestTokenHash)) {
            throw new AccessDeniedHttpException('Invalid ingest token.');
        }
    }
}
