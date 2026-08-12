<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\AdminAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class AdminAuthController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->authenticator->isAuthenticated($request)) {
            return new RedirectResponse('/');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('admin_token', '');
            if ($this->authenticator->login($request, $token)) {
                return new RedirectResponse('/');
            }
            $error = 'Token rejected.';
        }

        return new Response($this->twig->render('login.html.twig', [
            'error' => $error,
            'configured' => $this->authenticator->isConfigured(),
        ]));
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->authenticator->logout($request);

        return new RedirectResponse('/login');
    }
}
