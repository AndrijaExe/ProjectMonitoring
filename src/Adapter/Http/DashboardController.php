<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use App\Application\GetMonitoringOverview;
use App\Application\GetProjectDetail;
use App\Application\RecordHealthSnapshot;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly GetMonitoringOverview $overview,
        private readonly GetProjectDetail $projectDetail,
        private readonly RecordHealthSnapshot $recordHealthSnapshot,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->requireAdmin($request)) {
            return $redirect;
        }

        return new Response($this->twig->render('dashboard/index.html.twig', [
            'overview' => $this->overview->execute(),
        ]));
    }

    #[Route('/projects/{gameId}', name: 'project_detail', methods: ['GET'])]
    public function show(Request $request, string $gameId): Response
    {
        if ($redirect = $this->requireAdmin($request)) {
            return $redirect;
        }

        try {
            $detail = $this->projectDetail->execute($gameId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }

        return new Response($this->twig->render('dashboard/project.html.twig', [
            'detail' => $detail,
        ]));
    }

    #[Route('/projects/{gameId}/refresh', name: 'project_refresh', methods: ['POST'])]
    public function refresh(Request $request, string $gameId): Response
    {
        if ($redirect = $this->requireAdmin($request)) {
            return $redirect;
        }

        try {
            $this->recordHealthSnapshot->forGameId($gameId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown project.');
        }

        return new RedirectResponse('/projects/'.$gameId);
    }

    #[Route('/refresh', name: 'dashboard_refresh', methods: ['POST'])]
    public function refreshAll(Request $request): Response
    {
        if ($redirect = $this->requireAdmin($request)) {
            return $redirect;
        }

        $this->recordHealthSnapshot->forAll();

        return new RedirectResponse('/');
    }

    private function requireAdmin(Request $request): ?RedirectResponse
    {
        if ($this->authenticator->isAuthenticated($request)) {
            return null;
        }

        return new RedirectResponse('/login');
    }
}
