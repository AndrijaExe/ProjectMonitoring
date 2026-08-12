<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\RecordHealthSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectsHealthController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly RecordHealthSnapshot $recordHealthSnapshot,
    ) {
    }

    #[Route('/api/v1/projects/{gameId}/poll', name: 'project_poll', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        try {
            $snapshots = $this->recordHealthSnapshot->forGameId($gameId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }

        $payload = [];
        foreach ($snapshots as $snapshot) {
            $payload[] = [
                'endpoint' => $snapshot->endpoint->value,
                'status' => $snapshot->status->value,
                'http_code' => $snapshot->httpCode,
                'latency_ms' => $snapshot->latencyMs,
                'checked_at' => $snapshot->checkedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse(['snapshots' => $payload]);
    }
}
