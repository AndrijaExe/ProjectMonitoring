<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Turns cumulative AI counters into a day-by-day bill the console can chart.
 *
 * The game publishes totals and, when it knows the host, the same numbers again under
 * `ai.tokens.in.openai` and friends. This class never calls a provider; it only reads
 * what the monitor already stored.
 */
final class UsageBreakdown
{
    public const WINDOW_DAYS = 14;

    private const TOKENS_IN = 'ai.tokens.in';
    private const TOKENS_OUT = 'ai.tokens.out';
    private const COST_MICROS = 'ai.cost.micros';

    /** @var array<string, string> */
    private const LABELS = [
        'openai' => 'OpenAI',
        'gemini' => 'Gemini',
        'groq' => 'Groq',
        'unknown' => 'Unknown host',
        'all' => 'All providers',
    ];

    /**
     * @param array<string, float> $totals growth in a window, not lifetime readings
     *
     * @return array{
     *     tokens_in: int,
     *     tokens_out: int,
     *     cost_micros: int,
     *     providers: list<array{id: string, label: string, tokens_in: int, tokens_out: int, cost_micros: int}>
     * }
     */
    public function summarize(array $totals): array
    {
        $tokensIn = $this->intValue($totals[self::TOKENS_IN] ?? 0);
        $tokensOut = $this->intValue($totals[self::TOKENS_OUT] ?? 0);
        $cost = $this->intValue($totals[self::COST_MICROS] ?? 0);

        /** @var array<string, array{id: string, label: string, tokens_in: int, tokens_out: int, cost_micros: int}> $providers */
        $providers = [];

        foreach ($totals as $name => $value) {
            $parsed = $this->parseVendorSeries((string) $name);
            if ($parsed === null) {
                continue;
            }

            [$field, $vendor] = $parsed;
            if (!isset($providers[$vendor])) {
                $providers[$vendor] = [
                    'id' => $vendor,
                    'label' => self::LABELS[$vendor] ?? $this->fallbackLabel($vendor),
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'cost_micros' => 0,
                ];
            }

            $providers[$vendor][$field] = $this->intValue($value);
        }

        ksort($providers);

        if ($providers === [] && ($tokensIn > 0 || $tokensOut > 0 || $cost > 0)) {
            $providers['all'] = [
                'id' => 'all',
                'label' => self::LABELS['all'],
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_micros' => $cost,
            ];
        }

        return [
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_micros' => $cost,
            'providers' => array_values($providers),
        ];
    }

    /**
     * @param list<array{date: string, totals: array<string, float>}> $days
     *
     * @return list<array{
     *     date: string,
     *     tokens_in: int,
     *     tokens_out: int,
     *     cost_micros: int,
     *     providers: list<array{id: string, label: string, tokens_in: int, tokens_out: int, cost_micros: int}>
     * }>
     */
    public function days(array $days): array
    {
        $rows = [];
        foreach ($days as $day) {
            $rows[] = [
                'date' => $day['date'],
                ...$this->summarize($day['totals']),
            ];
        }

        return $rows;
    }

    public function windowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify(sprintf('-%d days', self::WINDOW_DAYS - 1));
    }

    /**
     * @return ?array{0: 'tokens_in'|'tokens_out'|'cost_micros', 1: string}
     */
    private function parseVendorSeries(string $name): ?array
    {
        foreach ([
            'tokens_in' => self::TOKENS_IN,
            'tokens_out' => self::TOKENS_OUT,
            'cost_micros' => self::COST_MICROS,
        ] as $field => $prefix) {
            if ($name === $prefix) {
                return null;
            }

            $lead = $prefix.'.';
            if (str_starts_with($name, $lead)) {
                $vendor = substr($name, strlen($lead));

                return $vendor === '' ? null : [$field, $vendor];
            }
        }

        return null;
    }

    private function fallbackLabel(string $vendor): string
    {
        return ucfirst(str_replace('-', ' ', $vendor));
    }

    private function intValue(float|int $value): int
    {
        return (int) round((float) $value);
    }
}
