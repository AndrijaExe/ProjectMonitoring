<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The token is good, but it may not do this.
 *
 * Separate from a plain refusal because the two mean opposite things to a client. A rejected
 * token means the session is over and the operator has to sign in again. This means the
 * session is fine and the request was out of scope, so signing the operator out on it would
 * lock a read-only client out of the board over one button it should never have offered.
 */
final class WriteAccessDenied extends AccessDeniedHttpException
{
    public const CODE = 'FORBIDDEN';

    public function __construct(string $message = 'This token may not act.')
    {
        parent::__construct($message);
    }
}
