<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The host would not answer, or would not let us.
 *
 * Every optional integration with the host fails the same way and is reported the same way: as a
 * sentence on the panel that wanted it. The console's own job — probes, history, alarms — never
 * depends on it, so none of these are exceptions anybody sees as a broken page.
 */
class RenderUnavailable extends \RuntimeException
{
}
