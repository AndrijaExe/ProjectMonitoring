<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\GetProjectLogs;
use App\Model\LogFilter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class LogsController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly GetProjectLogs $logs,
    ) {
    }

    #[Route('/api/v1/projects/{gameId}/logs', name: 'project_logs', methods: ['GET', 'OPTIONS'])]
    public function __invoke(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        $filter = new LogFilter(
            $request->query->getInt('limit', 100),
            $this->trimmed($request, 'level'),
            $this->trimmed($request, 'text'),
            $request->query->getInt('since_minutes', 1440),
        );

        try {
            return new JsonResponse($this->logs->execute($gameId, $filter));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }
    }

    private function trimmed(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query->get($key, ''));

        return $value === '' ? null : $value;
    }
}
