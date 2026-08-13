<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\ClearHealthHistory;
use App\Application\GetMonitoringOverview;
use App\Application\GetProjectDetail;
use App\Application\RecordHealthSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly GetMonitoringOverview $overview,
        private readonly GetProjectDetail $projectDetail,
        private readonly RecordHealthSnapshot $recordHealthSnapshot,
        private readonly ClearHealthHistory $clearHistory,
    ) {
    }

    #[Route('/api/v1/projects/{gameId}/snapshots', name: 'project_clear_history', methods: ['DELETE', 'OPTIONS'])]
    public function clear(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }
        $this->requireAdmin($request);

        try {
            return new JsonResponse($this->clearHistory->execute($gameId));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }
    }

    #[Route('/api/v1/overview', name: 'monitoring_overview', methods: ['GET', 'OPTIONS'])]
    public function overview(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }
        $this->requireAdmin($request);

        return new JsonResponse([
            'projects' => array_map(
                static fn ($card): array => $card->toArray(),
                $this->overview->execute()->projects,
            ),
        ]);
    }

    #[Route('/api/v1/projects/{gameId}', name: 'project_detail', methods: ['GET', 'OPTIONS'])]
    public function show(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }
        $this->requireAdmin($request);

        try {
            return new JsonResponse($this->projectDetail->execute($gameId)->toArray());
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }
    }

    #[Route('/api/v1/poll', name: 'dashboard_refresh', methods: ['POST', 'OPTIONS'])]
    public function refreshAll(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }
        $this->requireAdmin($request);

        $snapshots = $this->recordHealthSnapshot->forAll();

        return new JsonResponse([
            'polled' => count($snapshots),
        ]);
    }

    private function requireAdmin(Request $request): void
    {
        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }
    }
}
