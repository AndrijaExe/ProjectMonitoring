<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The console will not act, for a reason that is not the host's fault.
 *
 * Separate from RenderUnavailable because the answer is different: this one is a decision the
 * deployment made on purpose, and retrying it will fail the same way until someone changes it.
 */
final class ControlRefused extends \RuntimeException
{
}
