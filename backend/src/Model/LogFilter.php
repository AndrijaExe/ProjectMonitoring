<?php

declare(strict_types=1);

namespace App\Model;

final readonly class LogFilter
{
    public const MAX_LIMIT = 200;
    public const MAX_WINDOW_MINUTES = 10_080;

    public int $limit;
    public int $sinceMinutes;

    public function __construct(
        int $limit = 100,
        public ?string $level = null,
        public ?string $text = null,
        int $sinceMinutes = 1440,
    ) {
        // Clamp rather than reject: these arrive from a query string, and a console that
        // errors on a typo in a filter is more annoying than one that shows fewer lines.
        $this->limit = max(1, min($limit, self::MAX_LIMIT));
        $this->sinceMinutes = max(1, min($sinceMinutes, self::MAX_WINDOW_MINUTES));
    }

    public function since(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d minutes', $this->sinceMinutes));
    }
}
