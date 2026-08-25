<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Two levels of access, because reading the board and stopping a service are not the
 * same decision.
 *
 * The full token is the one the console signs in with, and it may act. The read-only
 * token may see everything and probe, which is how a monitor reads, but it cannot stop
 * a service, clear history or send mail. It exists so the phone app can carry a secret
 * that does no damage if the phone is lost.
 *
 * Read-only is header-only on purpose: it never opens a browser session, so the console
 * cannot end up showing buttons that every click would refuse.
 */
final class AdminAuthenticator
{
    public const SESSION_KEY = 'admin_authenticated';

    /**
     * Short secrets are treated as unset rather than weak, so a half-filled environment
     * fails closed instead of accepting "x".
     */
    private const MIN_TOKEN_LENGTH = 16;

    public function __construct(
        #[Autowire('%env(ADMIN_TOKEN)%')]
        private readonly string $adminToken,
        #[Autowire('%env(ADMIN_READONLY_TOKEN)%')]
        private readonly string $readonlyToken = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return strlen($this->adminToken) >= self::MIN_TOKEN_LENGTH;
    }

    /**
     * True for either token. Enough to read the board.
     */
    public function isAuthenticated(Request $request): bool
    {
        return $this->canWrite($request) || $this->readonlyTokenMatches($this->offeredToken($request));
    }

    /**
     * True only for the full token or an established console session.
     */
    public function canWrite(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if ($request->hasSession() && $request->getSession()->get(self::SESSION_KEY) === true) {
            return true;
        }

        return $this->tokenMatches($this->offeredToken($request));
    }

    /**
     * True when the caller got in, but with the token that may not act.
     */
    public function isReadOnly(Request $request): bool
    {
        return $this->isAuthenticated($request) && !$this->canWrite($request);
    }

    public function login(Request $request, string $token): bool
    {
        // Only the full token opens a session. See the class comment.
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

    private function readonlyTokenMatches(string $token): bool
    {
        if (strlen($this->readonlyToken) < self::MIN_TOKEN_LENGTH || $token === '') {
            return false;
        }

        return hash_equals($this->readonlyToken, $token);
    }

    private function offeredToken(Request $request): string
    {
        return (string) $request->headers->get('X-Admin-Token', '');
    }
}
