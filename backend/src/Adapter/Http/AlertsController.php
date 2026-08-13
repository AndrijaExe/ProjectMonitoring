<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\SendTestAlert;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AlertsController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly SendTestAlert $testAlert,
    ) {
    }

    #[Route('/api/v1/alerts/test', name: 'alerts_test', methods: ['POST', 'OPTIONS'])]
    public function test(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        return new JsonResponse($this->testAlert->execute());
    }
}
