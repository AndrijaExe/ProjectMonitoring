<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\GetProjectLogs;
use App\Application\GetSystemLogs;
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
        private readonly GetSystemLogs $systemLogs,
    ) {
    }

    #[Route('/api/v1/system/logs', name: 'system_logs', methods: ['GET', 'OPTIONS'])]
    public function system(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        return new JsonResponse($this->systemLogs->execute($this->filter($request)));
    }

    #[Route('/api/v1/projects/{gameId}/logs', name: 'project_logs', methods: ['GET', 'OPTIONS'])]
    public function project(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        try {
            return new JsonResponse($this->logs->execute($gameId, $this->filter($request)));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }
    }

    private function filter(Request $request): LogFilter
    {
        return new LogFilter(
            $request->query->getInt('limit', 100),
            $this->trimmed($request, 'level'),
            $this->trimmed($request, 'text'),
            $request->query->getInt('since_minutes', 1440),
        );
    }

    private function trimmed(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query->get($key, ''));

        return $value === '' ? null : $value;
    }
}
