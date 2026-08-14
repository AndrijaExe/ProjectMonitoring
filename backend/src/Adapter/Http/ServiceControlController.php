<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\ControlProjectService;
use App\Model\ControlRefused;
use App\Model\RenderUnavailable;
use App\Model\ServiceAction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ServiceControlController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly ControlProjectService $service,
    ) {
    }

    #[Route('/api/v1/projects/{gameId}/service', name: 'project_service', methods: ['GET', 'POST', 'OPTIONS'])]
    public function service(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        if (!$this->authenticator->isAuthenticated($request)) {
            throw new AccessDeniedHttpException('Admin token required.');
        }

        $action = null;
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $body */
            $body = json_decode($request->getContent() ?: '{}', true) ?: [];

            try {
                // Read before the project is looked up, so an action nobody offers is answered as
                // a bad request instead of being mistaken for an unknown project further in.
                $action = ServiceAction::fromString((string) ($body['action'] ?? ''));
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['error' => $exception->getMessage()], 400);
            }
        }

        try {
            return new JsonResponse(
                $action === null
                    ? $this->service->state($gameId)
                    : $this->service->apply($gameId, $action),
            );
        } catch (ControlRefused $exception) {
            // Not the caller's mistake and not a lost request: the deployment said no.
            return new JsonResponse(['error' => $exception->getMessage()], 403);
        } catch (RenderUnavailable $exception) {
            // The host refused or could not be reached. Nothing changed, and saying so beats a
            // generic failure that leaves the operator unsure whether the service is now down.
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }
    }
}
