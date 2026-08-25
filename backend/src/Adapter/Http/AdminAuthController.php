<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdminAuthController
{
    public function __construct(private readonly AdminAuthenticator $authenticator)
    {
    }

    #[Route('/api/v1/auth/login', name: 'admin_login', methods: ['POST', 'OPTIONS'])]
    public function login(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AccessDeniedHttpException('Token rejected.');
        }

        $token = is_array($payload) && is_string($payload['token'] ?? null) ? $payload['token'] : '';
        if (!$this->authenticator->tokenMatches($token)) {
            throw new AccessDeniedHttpException('Token rejected.');
        }

        // Only the full token signs in here, so this answer is never read-only.
        return new JsonResponse(['authenticated' => true, 'readonly' => false]);
    }

    /**
     * Accepts either token, which makes it the one endpoint a client can use to find out what
     * its secret is worth. The phone app checks a pasted token here rather than at /auth/login,
     * because login is for the token that may act and would refuse a read-only one.
     */
    #[Route('/api/v1/auth/session', name: 'admin_session', methods: ['GET', 'OPTIONS'])]
    public function session(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        return new JsonResponse([
            'authenticated' => true,
            'readonly' => $this->authenticator->isReadOnly($request),
        ]);
    }
}
