<?php

declare(strict_types=1);

namespace App\Application;

/**
 * The three grains a report can be cut at. Every bucket is aligned to UTC — a day starts at
 * midnight, a week on Monday, a month on the 1st — so the same bucket reads the same from
 * the console and the phone, and from an operator on a different clock.
 */
enum ReportPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public static function fromString(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Week;
    }

    /** How many buckets a report carries, newest one in progress. */
    public function bucketCount(): int
    {
        return match ($this) {
            self::Day => 30,
            self::Week => 12,
            self::Month => 12,
        };
    }

    /** The start of the bucket that contains $at. */
    public function bucketStart(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);

        return match ($this) {
            self::Day => $utc,
            // ISO week: Monday is day 1, so stepping back (N-1) days lands on it.
            self::Week => $utc->modify(sprintf('-%d days', ((int) $utc->format('N')) - 1)),
            self::Month => $utc->setDate((int) $utc->format('Y'), (int) $utc->format('m'), 1),
        };
    }

    public function next(\DateTimeImmutable $bucketStart): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $bucketStart->modify('+1 day'),
            self::Week => $bucketStart->modify('+7 days'),
            self::Month => $bucketStart->modify('first day of next month'),
        };
    }

    public function previous(\DateTimeImmutable $bucketStart): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $bucketStart->modify('-1 day'),
            self::Week => $bucketStart->modify('-7 days'),
            self::Month => $bucketStart->modify('first day of previous month'),
        };
    }

    /**
     * A stable key for the bucket: `2026-09-21`, `2026-W38`, `2026-09`. Clients label these
     * however reads best; the key is what they group by.
     */
    public function key(\DateTimeImmutable $bucketStart): string
    {
        return match ($this) {
            self::Day => $bucketStart->format('Y-m-d'),
            self::Week => $bucketStart->format('o-\WW'),
            self::Month => $bucketStart->format('Y-m'),
        };
    }
}
