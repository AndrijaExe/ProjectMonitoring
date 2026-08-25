<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\RegisterDevice;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DevicesController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly RegisterDevice $devices,
    ) {
    }

    /**
     * Where to send alerts, said by the phone that wants them.
     *
     * Open to the read-only token, and that is the whole point rather than an oversight. The
     * read-only rule exists so a lost phone cannot touch the infrastructure; asking to be told
     * when something breaks touches nothing. A phone that could not register would be a phone
     * that has to be watched instead of one that speaks up.
     */
    #[Route('/api/v1/devices', name: 'devices', methods: ['POST', 'DELETE', 'OPTIONS'])]
    public function devices(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];
        $token = (string) ($body['token'] ?? '');

        if ($request->isMethod('DELETE')) {
            $this->devices->forget($token);

            return new JsonResponse(['registered' => false]);
        }

        try {
            $this->devices->remember($token, (string) ($body['platform'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        }

        return new JsonResponse(['registered' => true]);
    }
}
