<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class AdminAuthenticator
{
    public const SESSION_KEY = 'admin_authenticated';

    public function __construct(
        #[Autowire('%env(ADMIN_TOKEN)%')]
        private readonly string $adminToken,
    ) {
    }

    public function isConfigured(): bool
    {
        return strlen($this->adminToken) >= 16;
    }

    public function isAuthenticated(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if ($request->hasSession() && $request->getSession()->get(self::SESSION_KEY) === true) {
            return true;
        }

        return $this->tokenMatches((string) $request->headers->get('X-Admin-Token', ''));
    }

    public function login(Request $request, string $token): bool
    {
        if (!$this->tokenMatches($token)) {
            return false;
        }

        $request->getSession()->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    public function tokenMatches(string $token): bool
    {
        if (!$this->isConfigured() || $token === '') {
            return false;
        }

        return hash_equals($this->adminToken, $token);
    }
}
