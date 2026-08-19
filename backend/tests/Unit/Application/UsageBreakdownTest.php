<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\UsageBreakdown;
use PHPUnit\Framework\TestCase;

final class UsageBreakdownTest extends TestCase
{
    public function testVendorSeriesAreSplitAndTotalsStayOnTop(): void
    {
        $summary = (new UsageBreakdown())->summarize([
            'ai.tokens.in' => 300.0,
            'ai.tokens.out' => 80.0,
            'ai.cost.micros' => 140.0,
            'ai.tokens.in.openai' => 200.0,
            'ai.tokens.out.openai' => 50.0,
            'ai.cost.micros.openai' => 100.0,
            'ai.tokens.in.groq' => 100.0,
            'ai.tokens.out.groq' => 30.0,
            'ai.cost.micros.groq' => 40.0,
            'chat.messages' => 12.0,
        ]);

        self::assertSame(300, $summary['tokens_in']);
        self::assertSame(80, $summary['tokens_out']);
        self::assertSame(140, $summary['cost_micros']);
        self::assertSame(['groq', 'openai'], array_column($summary['providers'], 'id'));
        self::assertSame('OpenAI', $summary['providers'][1]['label']);
        self::assertSame(200, $summary['providers'][1]['tokens_in']);
        self::assertSame(40, $summary['providers'][0]['cost_micros']);
    }

    public function testTotalsWithoutAVendorBecomeOneRowSoAChartStillHasASeries(): void
    {
        $summary = (new UsageBreakdown())->summarize([
            'ai.tokens.in' => 20.0,
            'ai.tokens.out' => 8.0,
            'ai.cost.micros' => 14.0,
        ]);

        self::assertSame([
            [
                'id' => 'all',
                'label' => 'All providers',
                'tokens_in' => 20,
                'tokens_out' => 8,
                'cost_micros' => 14,
            ],
        ], $summary['providers']);
    }

    public function testEmptyTotalsStayEmpty(): void
    {
        $summary = (new UsageBreakdown())->summarize([]);

        self::assertSame(0, $summary['tokens_in']);
        self::assertSame([], $summary['providers']);
    }

    public function testTheWindowOpensFourteenUtcDaysBack(): void
    {
        $start = (new UsageBreakdown())->windowStart(new \DateTimeImmutable('2026-08-19T15:30:00+02:00'));

        self::assertSame('2026-08-06', $start->format('Y-m-d'));
        self::assertSame('UTC', $start->getTimezone()->getName());
        self::assertSame('00:00:00', $start->format('H:i:s'));
    }
}
