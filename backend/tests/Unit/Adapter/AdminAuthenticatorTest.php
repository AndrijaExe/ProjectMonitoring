<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Auth\AdminAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminAuthenticatorTest extends TestCase
{
    private const FULL = 'full-token-long-enough';
    private const READONLY = 'readonly-token-long-enough';

    public function testTheFullTokenMayReadAndAct(): void
    {
        $request = $this->requestWithToken(self::FULL);

        self::assertTrue($this->authenticator()->isAuthenticated($request));
        self::assertTrue($this->authenticator()->canWrite($request));
        self::assertFalse($this->authenticator()->isReadOnly($request));
    }

    public function testTheReadOnlyTokenMayReadButNotAct(): void
    {
        $request = $this->requestWithToken(self::READONLY);

        self::assertTrue($this->authenticator()->isAuthenticated($request));
        self::assertFalse($this->authenticator()->canWrite($request));
        self::assertTrue($this->authenticator()->isReadOnly($request));
    }

    public function testAnUnknownTokenGetsNothing(): void
    {
        $request = $this->requestWithToken('not-a-token-but-long-enough');

        self::assertFalse($this->authenticator()->isAuthenticated($request));
        self::assertFalse($this->authenticator()->canWrite($request));
        self::assertFalse($this->authenticator()->isReadOnly($request));
    }

    public function testNoTokenGetsNothing(): void
    {
        $request = Request::create('/api/v1/overview');

        self::assertFalse($this->authenticator()->isAuthenticated($request));
        self::assertFalse($this->authenticator()->canWrite($request));
    }

    /**
     * A deployment that never set the read-only token must not accidentally accept an empty
     * header as that token.
     */
    public function testWithoutAReadOnlyTokenOnlyTheFullOneWorks(): void
    {
        $authenticator = new AdminAuthenticator(self::FULL, '');

        self::assertTrue($authenticator->isAuthenticated($this->requestWithToken(self::FULL)));
        self::assertFalse($authenticator->isAuthenticated($this->requestWithToken('')));
        self::assertFalse($authenticator->isAuthenticated($this->requestWithToken(self::READONLY)));
    }

    /**
     * Short secrets count as unset, so a half-filled environment fails closed.
     */
    public function testAShortReadOnlyTokenIsTreatedAsUnset(): void
    {
        $authenticator = new AdminAuthenticator(self::FULL, 'short');

        self::assertFalse($authenticator->isAuthenticated($this->requestWithToken('short')));
    }

    public function testAShortAdminTokenDisablesEverything(): void
    {
        $authenticator = new AdminAuthenticator('short', self::READONLY);

        self::assertFalse($authenticator->isConfigured());
        self::assertFalse($authenticator->canWrite($this->requestWithToken('short')));
    }

    /**
     * Read-only is header-only on purpose, so the console can never open a session that
     * shows buttons every click would refuse.
     */
    public function testOnlyTheFullTokenCanOpenASession(): void
    {
        self::assertFalse($this->authenticator()->tokenMatches(self::READONLY));
        self::assertTrue($this->authenticator()->tokenMatches(self::FULL));
    }

    private function authenticator(): AdminAuthenticator
    {
        return new AdminAuthenticator(self::FULL, self::READONLY);
    }

    private function requestWithToken(string $token): Request
    {
        $request = Request::create('/api/v1/overview');
        $request->headers->set('X-Admin-Token', $token);

        return $request;
    }
}
